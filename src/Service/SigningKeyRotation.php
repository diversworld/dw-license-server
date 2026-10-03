<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

class SigningKeyRotation
{
    public function __construct(
        private readonly LicenseSigner $signer,
        private readonly Filesystem $filesystem,
        private readonly LockFactory $locks,
        #[Autowire('%kernel.project_dir%/config/license')] private readonly string $directory,
    ) {
    }

    public function fingerprint(): string
    {
        $ring = $this->signer->keyring();
        return hash('sha256', json_encode([$ring['revision'], $ring['active'], $ring['publicKeys'], array_column($ring['keys'], 'state')], JSON_THROW_ON_ERROR));
    }

    public function prepare(string $actor, string $reason): string
    {
        return $this->locked(function () use ($actor, $reason): string {
            $this->validateActor($actor, $reason);
            $ring = $this->signer->keyring();
            $pair = sodium_crypto_sign_keypair();
            $public = sodium_crypto_sign_publickey($pair);
            $id = $this->signer->identifier($public);
            $this->filesystem->mkdir($this->directory.'/keys', 0700);
            $path = $this->directory.'/keys/'.$id.'.key';
            if (file_exists($path)) { throw new \RuntimeException('Signing key already exists.'); }
            $this->write($path, base64_encode(sodium_crypto_sign_secretkey($pair))."\n");
            sodium_memzero($pair);
            $ring['publicKeys'][$id] = base64_encode($public);
            $ring['keys'][$id] = ['state' => 'prepared', 'unknownLeases' => false, 'lastTokenExpiry' => 0];
            $this->save($ring, 'prepare', $id, $actor, $reason);
            return $id;
        });
    }

    public function transition(string $action, string $id, string $actor, string $reason, string $expectedFingerprint, bool $rolloutConfirmed = false, ?\DateTimeImmutable $legacySafeAfter = null): void
    {
        $this->locked(function () use ($action, $id, $actor, $reason, $expectedFingerprint, $rolloutConfirmed, $legacySafeAfter): void {
            $this->validateActor($actor, $reason);
            if (!hash_equals($this->fingerprint(), $expectedFingerprint)) { throw new \DomainException('Keyring changed; review the current state first.'); }
            $ring = $this->signer->keyring();
            if (!isset($ring['keys'][$id])) { throw new \DomainException('Unknown key.'); }
            $entry = &$ring['keys'][$id];
            switch ($action) {
                case 'publish':
                    if ($entry['state'] !== 'prepared') { throw new \DomainException('Only prepared keys can be published.'); }
                    $entry['state'] = 'verification_allowed';
                    break;
                case 'activate':
                    if ($entry['state'] !== 'verification_allowed' || !$rolloutConfirmed) { throw new \DomainException('Distribute the verification key to every instance and client, then explicitly confirm rollout.'); }
                    $path = $id === $ring['legacy'] ? $this->directory.'/private.key' : $this->directory.'/keys/'.$id.'.key';
                    $secret = is_readable($path) ? base64_decode(trim(file_get_contents($path)), true) : false;
                    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
                        || base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) !== $ring['publicKeys'][$id]) {
                        throw new \RuntimeException('Prepared private key is missing or does not match.');
                    }
                    sodium_memzero($secret);
                    $ring['keys'][$ring['active']]['state'] = 'verification_allowed';
                    $entry['state'] = 'active';
                    $ring['active'] = $id;
                    break;
                case 'retire':
                case 'revoke':
                    if ($id === $ring['active']) { throw new \DomainException('Activate a healthy replacement before disabling the active key.'); }
                    if (!in_array($entry['state'], ['prepared', 'verification_allowed'], true)) { throw new \DomainException('Key is already disabled.'); }
                    if ($action === 'retire') {
                        $now = time();
                        if (($entry['lastTokenExpiry'] ?? 0) > $now || (($entry['unknownLeases'] ?? true) && ($legacySafeAfter === null || $legacySafeAfter->getTimestamp() > $now))) {
                            throw new \DomainException('Outstanding tokens must expire; legacy leases require an explicit verified inventory cutoff.');
                        }
                        $entry['legacySafeAfter'] = $legacySafeAfter?->format(DATE_ATOM);
                    }
                    $entry['state'] = $action === 'retire' ? 'retired' : 'revoked';
                    break;
                default: throw new \InvalidArgumentException('Unknown key transition.');
            }
            $this->save($ring, $action, $id, $actor, $reason);
        });
    }

    public function activate(string $id, string $actor, string $reason, string $expectedFingerprint, bool $rolloutConfirmed): void
    {
        $this->transition('activate', $id, $actor, $reason, $expectedFingerprint, $rolloutConfirmed);
    }

    private function validateActor(string $actor, string $reason): void
    {
        if (trim($actor) === '' || strlen($actor) > 255 || strlen(trim($reason)) < 3 || strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Actor and a reason of 3–1000 bytes are required.');
        }
    }

    private function save(array $ring, string $action, string $id, string $actor, string $reason): void
    {
        ++$ring['revision'];
        $ring['history'][] = ['revision' => $ring['revision'], 'action' => $action, 'kid' => $id, 'actor' => $actor, 'reason' => trim($reason), 'at' => gmdate(DATE_ATOM)];
        $this->write($this->directory.'/keyring.json', json_encode($ring, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    private function write(string $path, string $contents): void
    {
        $mask = umask(0077);
        try {
            $this->filesystem->dumpFile($path, $contents);
            $this->filesystem->chmod($path, 0600);
        } finally { umask($mask); }
    }

    private function locked(callable $operation): mixed
    {
        $lock = $this->locks->createLock('license.signing-key-rotation', 30);
        if (!$lock->acquire()) { throw new \RuntimeException('Signing key rotation already running.'); }
        try { return $operation(); } finally { $lock->release(); }
    }
}

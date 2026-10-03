<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Filesystem\Filesystem;

class LicenseSigner
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/license/private.key')] private readonly string $keyPath,
        private readonly ?LockFactory $locks = null,
    ) {
    }

    public function sign(array $claims): string
    {
        $lock = ($this->locks ?? new LockFactory(new FlockStore()))->createLock('license.signing-key-rotation', 30);
        if (!$lock->acquire(true)) { throw new \RuntimeException('Signing key is locked.'); }
        try {
            $ring = $this->keyring();
            if (($claims['type'] ?? null) === 'signing-key-manifest') {
                $claims = ['type' => 'signing-key-manifest', 'version' => 1, 'revision' => $ring['revision'], 'issued_at' => time(), 'expires_at' => time() + 3600, 'active' => $ring['active'], 'legacy' => $ring['legacy'], 'publicKeys' => $this->publicKeys(), 'disabled' => array_keys(array_filter($ring['keys'], fn (array $key): bool => in_array($key['state'], ['retired', 'revoked'], true)))];
            }
            $secret = $this->secretKey();
            $claims['kid'] = $ring['active'];
            $payload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $signature = sodium_crypto_sign_detached($payload, $secret);
            sodium_memzero($secret);
            // Persist lease bounds before returning a token; a write failure never issues it.
            if (is_file(dirname($this->keyPath).'/keyring.json')) {
                $entry = &$ring['keys'][$ring['active']];
                if (is_int($claims['expires_at'] ?? null)) {
                    $entry['lastTokenExpiry'] = max($entry['lastTokenExpiry'] ?? 0, $claims['expires_at']);
                } else { $entry['unknownLeases'] = true; }
                $fs = new Filesystem();
                $mask = umask(0077);
                try {
                    $fs->dumpFile(dirname($this->keyPath).'/keyring.json', json_encode($ring, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
                    $fs->chmod(dirname($this->keyPath).'/keyring.json', 0600);
                } finally { umask($mask); }
            }
            return $payload.'.'.$this->encode($signature);
        } finally { $lock->release(); }
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (2 !== count($parts) || strlen($token) > 16384) {
            throw new \DomainException('Invalid token.');
        }
        try {
            $claims = json_decode($this->decode($parts[0]), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException('Invalid claims.', previous: $e);
        }
        if (!is_array($claims)) {
            throw new \DomainException('Invalid claims.');
        }

        $ring = $this->keyring();
        $kid = $claims['kid'] ?? $ring['legacy'];
        if (!is_string($kid) || !isset($this->publicKeys()[$kid])) {
            throw new \DomainException('Unknown signing key.');
        }
        $key = base64_decode($ring['publicKeys'][$kid], true);
        $signature = $this->decode($parts[1]);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($signature, $parts[0], $key)) {
            throw new \DomainException('Invalid signature.');
        }

        return $claims;
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->secretKey());
    }

    public function keyId(): string
    {
        return $this->identifier($this->publicKey());
    }

    /** @return array<string, string> Base64-encoded public keys, never secret keys. */
    public function publicKeys(): array
    {
        $ring = $this->keyring();
        return array_filter($ring['publicKeys'], fn (string $id): bool => in_array($ring['keys'][$id]['state'], ['active', 'verification_allowed'], true), ARRAY_FILTER_USE_KEY);
    }

    public function identifier(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    public function keyring(): array
    {
        $path = dirname($this->keyPath).'/keyring.json';
        if (is_file($path)) {
            $ring = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($ring) || !is_string($ring['active'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D', $ring['active'])
                || !is_array($ring['publicKeys'] ?? null) || !isset($ring['publicKeys'][$ring['active']], $ring['publicKeys'][$ring['legacy'] ?? ''])) {
                throw new \RuntimeException('Invalid signing keyring.');
            }

            return $this->normalize($ring);
        }
        $key = sodium_crypto_sign_publickey_from_secretkey($this->readSecret($this->keyPath));
        $kid = $this->identifier($key);

        return $this->normalize(['active' => $kid, 'legacy' => $kid, 'publicKeys' => [$kid => base64_encode($key)]]);
    }

    private function normalize(array $ring): array
    {
        $ring['version'] = 2;
        $ring['revision'] ??= 0;
        $ring['history'] ??= [];
        foreach ($ring['publicKeys'] as $id => $encoded) {
            $key = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $this->identifier($key) !== $id) {
                throw new \RuntimeException('Invalid public signing key.');
            }
            $ring['keys'][$id] ??= ['state' => $id === $ring['active'] ? 'active' : 'verification_allowed', 'unknownLeases' => true, 'lastTokenExpiry' => 0];
            if (!in_array($ring['keys'][$id]['state'], ['prepared', 'active', 'verification_allowed', 'retired', 'revoked'], true)
                || (($ring['keys'][$id]['state'] === 'active') !== ($id === $ring['active']))) {
                throw new \RuntimeException('Invalid signing key state.');
            }
        }
        return $ring;
    }

    private function secretKey(): string
    {
        $ring = $this->keyring();
        $path = $ring['active'] === $ring['legacy'] ? $this->keyPath : dirname($this->keyPath).'/keys/'.$ring['active'].'.key';
        $secret = $this->readSecret($path);
        if (base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) !== $ring['publicKeys'][$ring['active']]) {
            throw new \RuntimeException('Signing key does not match keyring.');
        }

        return $secret;
    }

    private function readSecret(string $path): string
    {
        if (!is_readable($path)) {
            throw new \RuntimeException('Signierschlüssel fehlt. app:license:keys ausführen.');
        }
        $key = base64_decode(trim(file_get_contents($path)), true);
        if (false === $key || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen($key)) {
            throw new \RuntimeException('Ungültiger Signierschlüssel.');
        }

        return $key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \DomainException('Invalid encoding.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $decoded) {
            throw new \DomainException('Invalid encoding.');
        }

        return $decoded;
    }
}

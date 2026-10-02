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

    public function prepare(): string
    {
        return $this->locked(function (): string {
            $ring = $this->signer->keyring();
            $pair = sodium_crypto_sign_keypair();
            $public = sodium_crypto_sign_publickey($pair);
            $id = $this->signer->identifier($public);
            $this->filesystem->mkdir($this->directory.'/keys', 0700);
            $path = $this->directory.'/keys/'.$id.'.key';
            if (file_exists($path)) {
                throw new \RuntimeException('Signing key already exists.');
            }
            $this->write($path, base64_encode(sodium_crypto_sign_secretkey($pair))."\n");
            sodium_memzero($pair);
            $ring['publicKeys'][$id] = base64_encode($public);
            $this->write($this->directory.'/keyring.json', json_encode($ring, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

            return $id;
        });
    }

    public function activate(string $id): void
    {
        $this->locked(function () use ($id): void {
            $ring = $this->signer->keyring();
            if (!preg_match('/^[a-f0-9]{16}$/D', $id) || !isset($ring['publicKeys'][$id])) {
                throw new \InvalidArgumentException('Unknown prepared key.');
            }
            $path = $id === $ring['legacy'] ? $this->directory.'/private.key' : $this->directory.'/keys/'.$id.'.key';
            $secret = base64_decode(trim(file_get_contents($path)), true);
            if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
                || base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret)) !== $ring['publicKeys'][$id]) {
                throw new \RuntimeException('Prepared key does not match public key.');
            }
            $ring['active'] = $id;
            $this->write($this->directory.'/keyring.json', json_encode($ring, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        });
    }

    private function write(string $path, string $contents): void
    {
        $mask = umask(0077);
        try {
            $this->filesystem->dumpFile($path, $contents);
            $this->filesystem->chmod($path, 0600);
        } finally {
            umask($mask);
        }
    }

    private function locked(callable $operation): mixed
    {
        $lock = $this->locks->createLock('license.signing-key-rotation', 30);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Signing key rotation already running.');
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}

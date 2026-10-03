<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

final class TotpSecretCipher
{
    public const string PREFIX = 'enc:v1:';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly LockFactory $locks,
        #[Autowire('%env(resolve:TOTP_ENCRYPTION_KEY_FILE)%')] private readonly string $keyFile,
        #[Autowire('%env(bool:TOTP_KEY_AUTO_CREATE)%')] private readonly bool $autoCreate,
        #[Autowire('%kernel.project_dir%')] ?string $projectDir = null,
    ) {
        $root = $projectDir ?? dirname(__DIR__, 2);
        $path = realpath($keyFile) ?: \Symfony\Component\Filesystem\Path::canonicalize($keyFile);
        if (!\Symfony\Component\Filesystem\Path::isAbsolute($path)
            || str_starts_with($path, $root.'/public/') || str_starts_with($path, $root.'/config/license/')) {
            throw new \InvalidArgumentException('Authenticator key must be an absolute private path, separate from public files and license signing keys.');
        }
    }

    public function encrypt(#[\SensitiveParameter] string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($secret, $nonce, $this->key($this->autoCreate)));
    }

    public function decrypt(#[\SensitiveParameter] string $stored): string
    {
        // Legacy plaintext is read without changing historical audit records. Explicit conversion
        // and subsequent user changes seal it; the migration itself never invents a production key.
        if (!str_starts_with($stored, self::PREFIX)) { return $stored; }
        $bytes = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) { throw new \RuntimeException('Invalid encrypted authenticator secret.'); }
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key(false));
        if ($plain === false) { throw new \RuntimeException('Authenticator encryption key does not match. Restore the original security key.'); }

        return $plain;
    }

    public function initializeKey(): void { $this->key(true); }

    private function key(bool $create = false): string
    {
        if (!is_file($this->keyFile)) {
            if (!$create) { throw new \RuntimeException('Authenticator encryption key is missing. Restore the existing key. For a new installation only, run app:security:totp-key:init.'); }
            $lock = $this->locks->createLock('totp-encryption-key-initialization');
            $lock->acquire(true);
            try {
                if (!is_file($this->keyFile)) {
                    $this->filesystem->mkdir(dirname($this->keyFile), 0700);
                    $mask = umask(0077);
                    try { $this->filesystem->dumpFile($this->keyFile, base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))."\n"); $this->filesystem->chmod($this->keyFile, 0600); }
                    finally { umask($mask); }
                }
            } finally { $lock->release(); }
        }
        clearstatcache(true, $this->keyFile);
        if ((fileperms($this->keyFile) & 0077) !== 0) { throw new \RuntimeException('Authenticator encryption key permissions must be 0600 or stricter.'); }
        $key = base64_decode(trim(file_get_contents($this->keyFile)), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) { throw new \RuntimeException('Invalid authenticator encryption key file.'); }

        return $key;
    }
}

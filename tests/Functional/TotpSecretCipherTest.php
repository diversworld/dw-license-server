<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Security\TotpSecretCipher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\{LockFactory, Store\InMemoryStore};

final class TotpSecretCipherTest extends KernelTestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory = sys_get_temp_dir().'/totp-cipher-'.bin2hex(random_bytes(8)); }
    protected function tearDown(): void { (new Filesystem())->remove($this->directory); parent::tearDown(); }

    public function testEncryptionUsesRandomNoncesAndASeparateNonOverwrittenKey(): void
    {
        $cipher = $this->cipher(); $cipher->initializeKey();
        $originalKey = file_get_contents($this->directory.'/totp.key');
        $first = $cipher->encrypt('JBSWY3DPEHPK3PXP'); $second = $cipher->encrypt('JBSWY3DPEHPK3PXP');
        self::assertNotSame($first, $second);
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($first));
        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($second));
        $cipher->initializeKey(); self::assertSame($originalKey, file_get_contents($this->directory.'/totp.key'));
        self::assertSame(0600, fileperms($this->directory.'/totp.key') & 0777);
    }

    public function testMissingKeyCannotBeSilentlyReplaced(): void
    {
        $cipher = $this->cipher(); $cipher->initializeKey(); $stored = $cipher->encrypt('JBSWY3DPEHPK3PXP');
        unlink($this->directory.'/totp.key');
        foreach (['decrypt', 'encrypt'] as $method) {
            try { $cipher->$method($method === 'decrypt' ? $stored : 'JBSWY3DPEHPK3PXP'); self::fail('Missing key must fail.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('missing', $error->getMessage()); }
            self::assertFileDoesNotExist($this->directory.'/totp.key');
        }
    }

    public function testCiphertextTamperingIsDetected(): void
    {
        $cipher = $this->cipher(); $cipher->initializeKey(); $stored = $cipher->encrypt('JBSWY3DPEHPK3PXP');
        $bytes = base64_decode(substr($stored, strlen(TotpSecretCipher::PREFIX)), true);
        $bytes[-1] = chr(ord($bytes[-1]) ^ 0xff);
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(TotpSecretCipher::PREFIX.base64_encode($bytes));
    }

    public function testLegacyPlaintextCanBeReadBeforeAnExplicitConversion(): void
    {
        self::assertSame('JBSWY3DPEHPK3PXP', $this->cipher()->decrypt('JBSWY3DPEHPK3PXP'));
        self::assertFileDoesNotExist($this->directory.'/totp.key');
    }

    public function testAWorldReadableKeyIsRejected(): void
    {
        $cipher = $this->cipher(); $cipher->initializeKey(); chmod($this->directory.'/totp.key', 0644);
        $this->expectException(\RuntimeException::class); $cipher->encrypt('JBSWY3DPEHPK3PXP');
    }

    public function testLicensePublicKeyCannotBeReusedAsAnAuthenticatorEncryptionKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TotpSecretCipher(new Filesystem(), new LockFactory(new InMemoryStore()), dirname(__DIR__, 2).'/config/license/public.key', false);
    }

    private function cipher(): TotpSecretCipher { return new TotpSecretCipher(new Filesystem(), new LockFactory(new InMemoryStore()), $this->directory.'/totp.key', false); }
}

<?php

namespace App\Tests\Integration;

use App\Service\LicenseSigner;
use App\Service\SigningKeyRotation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SigningKeyLifecycleTest extends TestCase
{
    private string $directory;
    private LicenseSigner $signer;
    private SigningKeyRotation $rotation;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/license-keys-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/private.key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        chmod($this->directory.'/private.key', 0600);
        $locks = new LockFactory(new FlockStore());
        $this->signer = new LicenseSigner($this->directory.'/private.key', $locks);
        $this->rotation = new SigningKeyRotation($this->signer, new Filesystem(), $locks, $this->directory);
    }

    protected function tearDown(): void { (new Filesystem())->remove($this->directory); }

    private function change(string $action, string $id, bool $confirmed = false, ?\DateTimeImmutable $cutoff = null): void
    {
        $this->rotation->transition($action, $id, 'operator@example.org', 'Verified lifecycle change', $this->rotation->fingerprint(), $confirmed, $cutoff);
    }

    private function denied(callable $operation): void
    {
        try { $operation(); self::fail('Unsafe transition accepted.'); } catch (\DomainException $e) { self::assertNotSame('', $e->getMessage()); }
    }

    public function testRegularRotationAndEmergencyRevocationPreserveHealthyKeys(): void
    {
        $oldId = $this->signer->keyId();
        $claims = ['expires_at' => time() + 86400, 'mode' => 'offline'];
        $old = $this->signer->sign($claims);
        $encode = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = $encode(json_encode($claims));
        $legacy = $payload.'.'.$encode(sodium_crypto_sign_detached($payload, base64_decode(file_get_contents($this->directory.'/private.key'))));
        $newId = $this->rotation->prepare('operator@example.org', 'Regular rotation');
        self::assertCount(1, $this->signer->publicKeys());
        $this->denied(fn () => $this->change('activate', $newId, true));
        $this->change('publish', $newId);
        $this->denied(fn () => $this->change('activate', $newId));
        $this->change('activate', $newId, true);
        $new = $this->signer->sign($claims);
        self::assertSame($oldId, $this->signer->verify($old)['kid']);
        self::assertSame($claims, $this->signer->verify($legacy));
        $this->denied(fn () => $this->change('revoke', $newId));
        $this->denied(fn () => $this->change('retire', $oldId));
        $this->change('revoke', $oldId);
        $this->denied(fn () => $this->signer->verify($old));
        $this->denied(fn () => $this->signer->verify($legacy));
        self::assertSame($newId, $this->signer->verify($new)['kid']);
        self::assertFileExists($this->directory.'/private.key', 'Revocation must not destroy recovery material.');
        $ring = $this->signer->keyring();
        self::assertCount(4, $ring['history']);
        self::assertSame('operator@example.org', $ring['history'][3]['actor']);
        self::assertSame('revoked', $ring['keys'][$oldId]['state']);
    }

    public function testStaleReviewAndWrongPrivateKeyRejectActivation(): void
    {
        $fingerprint = $this->rotation->fingerprint();
        $id = $this->rotation->prepare('operator', 'Scheduled rotation');
        $this->denied(fn () => $this->rotation->transition('publish', $id, 'operator', 'Scheduled rotation', $fingerprint));
        $this->change('publish', $id);
        file_put_contents($this->directory.'/keys/'.$id.'.key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
        try { $this->change('activate', $id, true); self::fail('Wrong private key accepted.'); } catch (\RuntimeException $e) { self::assertStringContainsString('does not match', $e->getMessage()); }
        self::assertSame('verification_allowed', $this->signer->keyring()['keys'][$id]['state']);
    }

    public function testDistributionManifestIsSignedByPinnedExistingKey(): void
    {
        $trusted = $this->signer->publicKey();
        $newId = $this->rotation->prepare('operator', 'Trust distribution');
        $this->change('publish', $newId);
        $token = $this->signer->sign(['type' => 'signing-key-manifest', 'revision' => -1]);
        [$payload, $encodedSignature] = explode('.', $token);
        self::assertTrue(sodium_crypto_sign_verify_detached(base64_decode(strtr($encodedSignature, '-_', '+/')), $payload, $trusted));
        $manifest = $this->signer->verify($token);
        self::assertSame('signing-key-manifest', $manifest['type']);
        self::assertSame(1, $manifest['version']);
        self::assertSame(2, $manifest['revision']);
        self::assertArrayHasKey($newId, $manifest['publicKeys']);
        self::assertSame(3600, $manifest['expires_at'] - $manifest['issued_at']);
        self::assertSame(0600, fileperms($this->directory.'/keyring.json') & 0777);
    }

    public function testLeaseBoundsPreventPrematureRetirement(): void
    {
        $oldId = $this->signer->keyId();
        $first = $this->rotation->prepare('operator', 'Lease-bound test');
        $this->change('publish', $first); $this->change('activate', $first, true);
        $this->signer->sign(['expires_at' => time() + 3600]);
        $second = $this->rotation->prepare('operator', 'Lease-bound test');
        $this->change('publish', $second); $this->change('activate', $second, true);
        $this->denied(fn () => $this->change('retire', $first));
        $this->denied(fn () => $this->change('retire', $oldId));
        $this->change('retire', $oldId, false, new \DateTimeImmutable('-1 second'));
        self::assertSame('retired', $this->signer->keyring()['keys'][$oldId]['state']);
        self::assertArrayNotHasKey($oldId, $this->signer->publicKeys());
        self::assertGreaterThan(time(), $this->signer->keyring()['keys'][$first]['lastTokenExpiry']);
    }
}

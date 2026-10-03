<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\{LicenseSigner, SigningKeyRotation};
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\{LockFactory, Store\InMemoryStore};
use Symfony\Component\Process\Process;

final class RealContaoClientTest extends IsolatedWebTestCase
{
    private string $directory;
    private LicenseSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/real-contao-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $seed = str_repeat("\x01", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        file_put_contents($this->directory.'/private.key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed))));
        $this->signer = new LicenseSigner($this->directory.'/private.key');
        static::getContainer()->set(LicenseSigner::class, $this->signer);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) { (new Filesystem())->remove($this->directory); }
        parent::tearDown();
    }

    public static function profiles(): iterable { yield 'published single-key client' => ['public']; yield 'advanced client Git commit' => ['advanced']; }

    #[DataProvider('profiles')]
    public function testRealHttpTokensAndExactRefreshGraceExpiryBoundaries(string $profile): void
    {
        $this->requireProfile($profile);
        $license = $this->license();
        $license->getProduct()->setAllowedFeatures(['sla']);
        $license->setFeatures(['sla']);
        $license->getCustomer()->setActive(true);
        $license->getProduct()->setActive(true)->setTokenLifetimeSeconds(300)->setGracePeriodSeconds(600);
        $this->em->flush();
        $this->client->jsonRequest('POST', '/api/v1/licenses/activate', ['licenseKey' => $license->getLicenseKey(), 'product' => $license->getProduct()->getSlug(), 'tenant' => 'real-client', 'domain' => 'example.org']);
        self::assertResponseIsSuccessful();
        $token = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['token'];
        $claims = $this->signer->verify($token);
        $keys = base64_encode($this->signer->publicKey());
        self::assertSame('valid', $this->validate($profile, $token, $keys, $claims['refresh_after'] - 1)['state']);
        self::assertSame('grace', $this->validate($profile, $token, $keys, $claims['refresh_after'])['state']);
        self::assertTrue($this->validate($profile, $token, $keys, $claims['grace_until'] - 1)['enabled']);
        self::assertFalse($this->validate($profile, $token, $keys, $claims['grace_until'])['enabled']);
        self::assertFalse($this->validate($profile, $token, $keys, $claims['issued_at'] - 1)['enabled']);
        $this->client->jsonRequest('POST', '/api/v1/licenses/validate', ['token' => $token, 'tenant' => 'real-client', 'domain' => 'example.org']);
        self::assertResponseIsSuccessful();
        $renewed = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['token'];
        self::assertTrue($this->validate($profile, $renewed, $keys, $this->signer->verify($renewed)['issued_at'])['enabled']);
    }

    public function testFixedVectorsAlsoReproduceServerProtocolWithoutAClientCheckout(): void
    {
        $vectors = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/license_protocol_vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($vectors['vectors'] as $vector) { self::assertSame($vector['claims'], $this->signer->verify($vector['token'])); }
        self::assertSame($vectors['vectors']['kid']['token'], $this->signer->sign($vectors['vectors']['legacy']['claims']));
    }

    #[DataProvider('profiles')]
    public function testFixedProtocolVectorsWithRealClientSource(string $profile): void
    {
        $this->requireProfile($profile);
        $vectors = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/license_protocol_vectors.json'), true, flags: JSON_THROW_ON_ERROR);
        $keys = $profile === 'advanced' ? json_encode([$vectors['kid'] => $vectors['publicKey']], JSON_THROW_ON_ERROR) : $vectors['publicKey'];
        foreach ($vectors['vectors'] as $vector) {
            self::assertTrue($this->validate($profile, $vector['token'], $keys, $vector['claims']['issued_at'])['enabled']);
            self::assertFalse($this->validate($profile, $vector['token'], $keys, $vector['claims']['expires_at'])['enabled']);
            self::assertSame($vector['claims'], $this->signer->verify($vector['token']));
        }
        self::assertSame($vectors['vectors']['kid']['token'], $this->signer->sign($vectors['vectors']['legacy']['claims']));
    }

    public function testAdvancedRealClientVerifiesLegacyAndBothKeysAfterRotation(): void
    {
        $this->requireProfile('advanced');
        $claims = $this->claims();
        $old = $this->signer->sign($claims);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $payload = $encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $legacy = $payload.'.'.$encode(sodium_crypto_sign_detached($payload, base64_decode(file_get_contents($this->directory.'/private.key'), true)));
        $rotation = new SigningKeyRotation($this->signer, new Filesystem(), new LockFactory(new InMemoryStore()), $this->directory);
        $id = $rotation->prepare('test-operator', 'Scheduled rotation'); $rotation->transition('publish', $id, 'test-operator', 'Distribute verification key', $rotation->fingerprint()); $rotation->activate($id, 'test-operator', 'Distribution verified', $rotation->fingerprint(), true);
        $new = $this->signer->sign($claims);
        $keys = json_encode($this->signer->publicKeys(), JSON_THROW_ON_ERROR);
        foreach ([$legacy, $old, $new] as $token) { self::assertTrue($this->validate('advanced', $token, $keys, 1710000000)['enabled']); }
        self::assertFalse($this->validate('advanced', $new, $keys, 1710000300)['enabled']);
    }

    public function testPublishedClientLimitationsAreExplicitlyDetected(): void
    {
        $this->requireProfile('public');
        $claims = $this->claims();
        $claims['grace_until'] = $claims['expires_at'] = $claims['refresh_after'] + 60 * 86400;
        $token = $this->signer->sign($claims);
        self::assertFalse($this->validate('public', $token, json_encode($this->signer->publicKeys(), JSON_THROW_ON_ERROR), 1710000000)['enabled'], 'The published client does not accept a JSON keyring.');
        self::assertFalse($this->validate('public', $token, base64_encode($this->signer->publicKey()), $claims['refresh_after'] + 30 * 86400)['enabled'], 'The published client has a fixed 30-day grace limit.');
    }

    public function testAdvancedClientHonorsLongerExplicitGraceWindow(): void
    {
        $this->requireProfile('advanced');
        $claims = $this->claims();
        $claims['grace_until'] = $claims['expires_at'] = $claims['refresh_after'] + 60 * 86400;
        $keys = json_encode($this->signer->publicKeys(), JSON_THROW_ON_ERROR);
        self::assertTrue($this->validate('advanced', $this->signer->sign($claims), $keys, $claims['refresh_after'] + 30 * 86400)['enabled']);
        self::assertFalse($this->validate('advanced', $this->signer->sign($claims), $keys, $claims['grace_until'])['enabled']);
    }

    private function claims(): array
    {
        return ['tenant' => 'real-client', 'domain' => 'example.org', 'features' => ['sla'], 'mode' => 'online', 'status' => 'valid', 'issued_at' => 1710000000, 'refresh_after' => 1710000100, 'grace_until' => 1710000300, 'expires_at' => 1710000300];
    }

    private function requireProfile(string $profile): void
    {
        if (!is_file(dirname(__DIR__, 2).'/var/contao-integration/'.$profile.'.php')) {
            if (getenv('REQUIRE_REAL_CONTAO_CLIENT') === '1' && $profile === 'public') { self::fail('Required pinned real client is missing; run scripts/prepare_contao_client.py.'); }
            self::markTestSkipped('Pinned '.$profile.' real client was not prepared; no fixture substituted.');
        }
    }

    private function validate(string $profile, string $token, string $keys, int $now): array
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/Support/contao_validator_worker.php']);
        $process->setInput(json_encode(['profile' => $profile, 'token' => $token, 'keys' => $keys, 'now' => $now, 'tenant' => 'real-client', 'domain' => 'example.org'], JSON_THROW_ON_ERROR));
        $process->run(); self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}

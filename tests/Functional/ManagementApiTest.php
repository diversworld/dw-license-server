<?php
namespace App\Tests\Functional;
use App\Entity\{ApiToken, ApiOperation, License, LicenseAction};
use App\Tests\Support\IsolatedWebTestCase;
final class ManagementApiTest extends IsolatedWebTestCase
{
    private function credential(License $license, array $scopes = ['licenses:create', 'licenses:renew']): string
    {
        $secret = 'dwapi_'.bin2hex(random_bytes(32));
        $credential = (new ApiToken())->setToken($secret)->setCustomer($license->getCustomer())->setScopes($scopes)->setActive(true)->setExpiresAt(new \DateTimeImmutable('+1 month'));
        $this->em->persist($credential); $this->em->flush(); return $secret;
    }
    private function payload(string $product = 'test'): array { return ['product' => $product, 'expiresAt' => (new \DateTimeImmutable('+1 year'))->format(DATE_ATOM), 'mode' => 'online', 'features' => [], 'maxDomains' => 2, 'reason' => 'Created through test integration']; }
    private function call(string $url, array $payload, string $secret, string $key): void
    { $this->client->jsonRequest('POST', $url, $payload, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'HTTP_IDEMPOTENCY_KEY' => $key]); }
    public function testCreateIsCustomerScopedHashedAndIdempotent(): void
    {
        $base = $this->license(); $secret = $this->credential($base);
        $raw = $this->em->getConnection()->fetchAssociative('SELECT token, token_hash FROM api_token'); self::assertNull($raw['token']); self::assertSame(hash('sha256', $secret), $raw['token_hash']);
        $payload = $this->payload(); $this->call('/api/v1/management/licenses', $payload, $secret, 'create-1'); self::assertResponseIsSuccessful();
        $first = json_decode($this->client->getResponse()->getContent(), true); self::assertArrayHasKey('licenseKey', $first);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $this->call('/api/v1/management/licenses', $payload, $secret, 'create-1'); self::assertResponseIsSuccessful(); self::assertSame($first, json_decode($this->client->getResponse()->getContent(), true));
        self::assertSame(2, $this->em->getRepository(License::class)->count([])); self::assertSame(1, $this->em->getRepository(ApiOperation::class)->count([]));
        $payload['maxDomains'] = 3; $this->call('/api/v1/management/licenses', $payload, $secret, 'create-1'); self::assertResponseStatusCodeSame(409);
        self::assertSame(2, $this->em->getRepository(License::class)->count([]));
    }
    public function testForeignLicenseCannotBeRenewedAndMissingScopesAreDenied(): void
    {
        $own = $this->license(); $own->getProduct()->setSlug('own'); $this->em->flush(); $foreign = $this->license();
        $secret = $this->credential($own, ['licenses:renew']);
        $payload = ['expiresAt' => (new \DateTimeImmutable('+1 year'))->format(DATE_ATOM), 'reason' => 'Renew integration fixture'];
        $this->call('/api/v1/management/licenses/'.$foreign->getId().'/renew', $payload, $secret, 'foreign'); self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
        $this->call('/api/v1/management/licenses', $this->payload('own'), $secret, 'denied-create'); self::assertResponseStatusCodeSame(403);
        $this->call('/api/v1/management/licenses/'.$own->getId().'/renew', $payload, $secret, 'own-renew'); self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em->getRepository(LicenseAction::class)->count(['license' => $own]));
        $this->call('/api/v1/management/licenses/'.$own->getId().'/renew', $payload, $secret, 'own-renew'); self::assertResponseIsSuccessful(); self::assertSame(1, $this->em->getRepository(LicenseAction::class)->count(['license' => $own]));
    }
    public function testRevokedExpiredMissingAndUnscopedCredentialsCannotWrite(): void
    {
        $license = $this->license(); $secret = $this->credential($license, []);
        $this->call('/api/v1/management/licenses', $this->payload(), $secret, 'no-scope'); self::assertResponseStatusCodeSame(403);
        $credential = $this->em->getRepository(ApiToken::class)->findOneBy([]); $credential->setScopes(['licenses:create'])->setActive(false); $this->em->flush();
        $this->call('/api/v1/management/licenses', $this->payload(), $secret, 'revoked'); self::assertResponseStatusCodeSame(401);
        $credential->setActive(true)->setExpiresAt(new \DateTimeImmutable('-1 day')); $this->em->flush();
        $this->call('/api/v1/management/licenses', $this->payload(), $secret, 'expired'); self::assertResponseStatusCodeSame(401);
        $this->call('/api/v1/management/licenses', $this->payload(), 'invalid-credential-value', 'invalid'); self::assertResponseStatusCodeSame(401);
        $this->client->jsonRequest('POST', '/api/v1/management/licenses', $this->payload()); self::assertResponseStatusCodeSame(401);
        self::assertSame(1, $this->em->getRepository(License::class)->count([]));
    }
}

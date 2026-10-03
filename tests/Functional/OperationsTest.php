<?php

namespace App\Tests\Functional;

use App\Service\{LicenseSigner, ApiMetrics};
use App\Tests\Support\IsolatedWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

final class OperationsTest extends IsolatedWebTestCase
{
    public function testReadinessChecksRealDependenciesWithoutBusinessFixtures(): void
    {
        $directory = sys_get_temp_dir().'/license-health-'.bin2hex(random_bytes(8)); mkdir($directory, 0700);
        try {
            $this->client->request('GET', '/health/live'); self::assertResponseIsSuccessful();
            static::getContainer()->set(LicenseSigner::class, new LicenseSigner($directory.'/private.key'));
            $this->client->request('GET', '/health/ready'); self::assertResponseStatusCodeSame(503);
            self::assertSame(['status' => 'unavailable'], json_decode($this->client->getResponse()->getContent(), true));
            file_put_contents($directory.'/private.key', base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())));
            $this->client->request('GET', '/health/ready'); self::assertResponseIsSuccessful();
            self::assertSame(0, $this->em->getRepository(\App\Entity\Customer::class)->count([]));
            self::assertSame(0, $this->em->getRepository(\App\Entity\AuditLog::class)->count([]));
        } finally { (new Filesystem())->remove($directory); }
    }

    public function testUnavailableDatabaseNeverBlocksLiveness(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient(); $this->client->disableReboot();
        static::getContainer()->set('doctrine.dbal.default_connection', \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'health-test', 'password' => 'test-only', 'dbname' => 'nonexistent', 'serverVersion' => '11.8.0-MariaDB']));
        $this->client->request('GET', '/health/ready'); self::assertResponseStatusCodeSame(503);
        self::assertSame(['status' => 'unavailable'], json_decode($this->client->getResponse()->getContent(), true));
        $this->client->request('GET', '/health/live'); self::assertResponseIsSuccessful();
    }

    public function testRequestIdsAndBoundedMetricsNeverContainCredentialsOrDomains(): void
    {
        static::getContainer()->get('cache.app')->deleteItem('license_api_metrics_v1');
        $requestId = Uuid::v7()->toRfc4122();
        $this->client->jsonRequest('POST', '/api/v1/licenses/validate', ['token' => 'customer-secret', 'tenant' => 'arbitrary-tenant', 'domain' => 'private.customer.test'], server: ['HTTP_X_REQUEST_ID' => $requestId]);
        self::assertSame($requestId, $this->client->getResponse()->headers->get('X-Request-ID'));
        $metrics = static::getContainer()->get(ApiMetrics::class);
        $metrics->record('attacker-supplied-domain', 429, 0.01);
        $text = $metrics->exposition();
        self::assertStringContainsString('license_api_refresh_failures_total 1', $text);
        self::assertStringContainsString('license_api_rate_limits_total 1', $text);
        self::assertStringContainsString('operation="other"', $text);
        foreach (['customer-secret', 'private.customer.test', 'arbitrary-tenant', 'attacker-supplied-domain'] as $secret) { self::assertStringNotContainsString($secret, $text); }
        $this->client->request('GET', '/health/live', server: ['HTTP_X_REQUEST_ID' => 'not-a-valid-id']);
        self::assertTrue(Uuid::isValid($this->client->getResponse()->headers->get('X-Request-ID')));
    }

    public function testMetricsRequireGlobalAdministration(): void
    {
        $this->client->request('GET', '/operations/metrics'); self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        $global = $this->user(); $this->client->loginUser($global);
        $this->client->request('GET', '/operations/metrics'); self::assertResponseIsSuccessful();
        self::assertStringContainsString('license_api_requests_total', $this->client->getResponse()->getContent());
        $scoped = $this->user()->setGlobalAccess(false); $this->em->flush();
        $this->client->loginUser($scoped); $this->client->request('GET', '/operations/metrics'); self::assertResponseStatusCodeSame(403);
        // Switch principals only after ending the scoped request, without creating a user under that principal.
        $this->em->getFilters()->disable('customer_scope');
    }
}

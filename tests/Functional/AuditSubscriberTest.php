<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\{Activation, ApiToken, AuditLog, Customer, License, LogEntry, Product, ResetPasswordRequest, UpdateRelease, User};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AuditSubscriberTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        if (!\Doctrine\DBAL\Types\Type::hasType('uuid')) {
            \Doctrine\DBAL\Types\Type::addType('uuid', \Symfony\Bridge\Doctrine\Types\UuidType::class);
        }
        // Entirely isolated schema: no development or existing test data is touched.
        static::getContainer()->set('doctrine.dbal.default_connection', DriverManager::getConnection([
            'driver' => 'pdo_sqlite', 'memory' => true,
        ]));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    public function testEveryEntityTableIsAuditedForCreateUpdateAndArchiveOrDelete(): void
    {
        $customer = (new Customer())->setCompany('Test')->setFirstname('Test')->setLastname('Customer')
            ->setEmail('customer@example.org')->setStreet('Test 1')->setZip('12345')->setCity('Berlin');
        $product = (new Product())->setName('Testmodul')->setSlug('testmodul');
        $license = (new License())->setCustomer($customer)->setProduct($product);
        $user = (new User())->setEmail('admin@example.org')->setFirstname('Test')->setLastname('Admin')->setPassword('secret-password');
        $credential = (new ApiToken())->setCustomer($customer)->setToken('secret-api-token');
        $endpoint = new \App\Entity\WebhookEndpoint($customer, 'https://webhook.example.org/', 'encrypted-secret-fixture', ['license.created']);
        $entities = [
            $customer, $product, $license, $user,
            (new Activation())->setLicense($license)->setDomain('example.org'),
            $credential,
            (new LogEntry())->setAction('test'),
            (new UpdateRelease())->setProduct($product)->setChangelog('Test')->setPackageUrl('https://example.org/package'),
            new ResetPasswordRequest($user, new \DateTimeImmutable('+1 hour'), 'selector', 'secret-reset-token'),
            new \App\Entity\LicenseAction($license, 'pause', 'Test reason', $user, 'active', 'suspended', null, null),
            new \App\Entity\ReminderDelivery($license, new \DateTimeImmutable('+1 day'), 1, 'customer@example.test', 'de'),
            $endpoint,
            new \App\Entity\WebhookDelivery($endpoint, \Symfony\Component\Uid\Uuid::v7(), ['version' => 1]),
            new \App\Entity\ApiOperation($credential, 'idempotency-fixture', str_repeat('a', 64), ['licenseId' => (string) $license->getId()]),
        ];
        $changes = ['city', 'name', 'notes', 'firstname', 'domain', 'name', 'action', 'version', 'expiresAt', 'reason', 'status', 'url', 'status', 'bodyHash'];
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $tables = [];
        $ids = [];
        foreach ($entities as $entity) {
            $metadata = $this->em->getClassMetadata($entity::class);
            $table = $metadata->getTableName();
            $id = (string) $entity->getId();
            $tables[] = $table;
            $ids[$table] = $id;
            $this->assertEntry($table, 'created', $id);
        }
        $mappedTables = array_map(static fn ($metadata) => $metadata->getTableName(), array_filter(
            $this->em->getMetadataFactory()->getAllMetadata(),
            static fn ($metadata) => !in_array($metadata->name, [AuditLog::class, \App\Entity\AuditChainHead::class], true),
        ));
        sort($tables);
        sort($mappedTables);
        self::assertSame(array_values($mappedTables), $tables, 'Every mapped entity table must be covered.');

        foreach ($entities as $index => $entity) {
            $metadata = $this->em->getClassMetadata($entity::class);
            $metadata->setFieldValue($entity, $changes[$index], $changes[$index] === 'expiresAt' ? new \DateTimeImmutable('+2 hours') : 'changed');
        }
        $this->em->flush();
        foreach ($entities as $index => $entity) {
            $table = $this->em->getClassMetadata($entity::class)->getTableName();
            $entry = $this->assertEntry($table, 'updated', $ids[$table]);
            self::assertContains($changes[$index], json_decode($entry['context'], true, flags: JSON_THROW_ON_ERROR)['changedFields']);
        }

        $before = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log');
        $this->em->flush();
        self::assertSame($before, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'), 'No changes must not generate audit entries.');

        foreach (array_reverse($entities) as $entity) {
            if ($entity instanceof \App\Archive\ArchivableInterface) {
                $entity->archive();
            } else {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
        foreach ($tables as $table) {
            $archived = in_array($table, ['customer', 'product', 'license'], true);
            $this->assertEntry($table, $archived ? 'updated' : 'deleted', $ids[$table], $archived ? 2 : 1);
        }

        $entries = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id');
        self::assertCount(3 * count($entities), $entries, 'Audit entries must not audit themselves.');
        $previousHash = null;
        foreach ($entries as $entry) {
            self::assertSame($previousHash, $entry['previous_hash']);
            $previousHash = $entry['entry_hash'];
        }
        $encoded = serialize($entries);
        foreach (['secret-password', 'secret-api-token', 'secret-reset-token'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    public function testActorAndRequestAreRecorded(): void
    {
        $user = (new User())->setEmail('actor@example.org')->setFirstname('Test')->setLastname('Actor')->setPassword('test');
        $this->em->persist($user);
        $this->em->flush();
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_ADMIN']));
        $request = \Symfony\Component\HttpFoundation\Request::create('/admin', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Audit test']);
        static::getContainer()->get(\Symfony\Component\HttpFoundation\RequestStack::class)->push($request);

        $product = (new Product())->setName('Test')->setSlug('test');
        $this->em->persist($product);
        $this->em->flush();
        $entry = $this->assertEntry('product', 'created', (string) $product->getId());
        self::assertSame($user->getId()->toBinary(), $entry['performed_by_id']);
        self::assertSame('127.0.0.1', $entry['ip_address']);
        self::assertSame('Audit test', $entry['user_agent']);
    }

    public function testFailedFlushRollsBackEntityAndAuditEntryTogether(): void
    {
        $product = (new Product())->setName('Before')->setSlug('rollback');
        $this->em->persist($product);
        $this->em->flush();
        $this->em->getEventManager()->addEventListener([Events::postUpdate], new class {
            public function postUpdate(): void
            {
                throw new \RuntimeException('Test rollback');
            }
        });
        $product->setName('After');
        try {
            $this->em->flush();
            self::fail('The failing listener must abort the transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Test rollback', $exception->getMessage());
        }
        self::assertSame('Before', $this->em->getConnection()->fetchOne('SELECT name FROM product'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'));
    }

    public function testChangedValuesAreStoredAndDisplayedInTheAdminLog(): void
    {
        $user = (new User())->setEmail('audit@example.org')->setFirstname('Test')->setLastname('Admin')
            ->setPassword('old-secret-password')->setRoles(['ROLE_ADMIN']);
        $product = (new Product())->setName('Before')->setSlug('changes')->setActive(false);
        $this->em->persist($user);
        $this->em->persist($product);
        $this->em->flush();
        $oldDate = $product->getCreatedAt();
        $newDate = new \DateTimeImmutable('2026-10-03 12:34:56+02:00');
        $product->setName('<script>alert("test")</script>')->setActive(true)->setCreatedAt($newDate);
        $user->setPassword('new-secret-password')->setRoles(['ROLE_ADMIN', 'ROLE_TEST']);
        $this->em->flush();

        $entry = $this->assertEntry('product', 'updated', (string) $product->getId());
        $changes = json_decode($entry['context'], true, flags: JSON_THROW_ON_ERROR)['changes'];
        self::assertSame(['old' => 'Before', 'new' => '<script>alert("test")</script>'], $changes['name']);
        self::assertSame(['old' => false, 'new' => true], $changes['active']);
        self::assertSame($oldDate->format('Y-m-d\TH:i:s.uP'), $changes['createdAt']['old']);
        self::assertSame($newDate->format('Y-m-d\TH:i:s.uP'), $changes['createdAt']['new']);
        $userEntry = $this->assertEntry('user', 'updated', (string) $user->getId());
        $userChanges = json_decode($userEntry['context'], true, flags: JSON_THROW_ON_ERROR)['changes'];
        self::assertSame(['old' => '[redacted]', 'new' => '[redacted]'], $userChanges['password']);
        self::assertSame(['ROLE_ADMIN'], $userChanges['roles']['old']);
        self::assertSame(['ROLE_ADMIN', 'ROLE_TEST'], $userChanges['roles']['new']);

        $user->enableTwoFactor('JBSWY3DPEHPK3PXP', []);
        $this->em->flush();
        $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $router = static::getContainer()->get('router');
        $crawler = $this->client->request('GET', $router->generate('admin_audit_log_index', ['_locale' => 'de']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('thead', 'Änderungen');
        self::assertSelectorTextContains('.audit-changes thead', 'Vorher');
        self::assertSelectorTextContains('.audit-changes thead', 'Nachher');
        $changeText = implode(' ', $crawler->filter('.audit-changes tbody')->each(static fn ($node): string => $node->text()));
        self::assertStringContainsString('Before', $changeText);
        self::assertStringContainsString('Geschützt', $changeText);
        self::assertStringContainsString('&lt;script&gt;', $this->client->getResponse()->getContent());
        self::assertSame(0, $crawler->filter('.audit-changes script')->count());
        self::assertStringNotContainsString('old-secret-password', $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('new-secret-password', $this->client->getResponse()->getContent());
    }

    public function testAssociationChangesAndDeletedValuesAreRecorded(): void
    {
        $first = (new Product())->setName('First')->setSlug('first');
        $second = (new Product())->setName('Second')->setSlug('second');
        $release = (new UpdateRelease())->setProduct($first)->setChangelog('Initial')->setPackageUrl('https://example.org/package');
        foreach ([$first, $second, $release] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $release->setProduct($second);
        $this->em->flush();
        $entry = $this->assertEntry('update_release', 'updated', (string) $release->getId());
        $change = json_decode($entry['context'], true, flags: JSON_THROW_ON_ERROR)['changes']['product'];
        self::assertSame(['type' => 'product', 'id' => ['id' => (string) $first->getId()]], $change['old']);
        self::assertSame(['type' => 'product', 'id' => ['id' => (string) $second->getId()]], $change['new']);
        $id = (string) $release->getId();
        $this->em->remove($release);
        $this->em->flush();
        $entry = $this->assertEntry('update_release', 'deleted', $id);
        $changes = json_decode($entry['context'], true, flags: JSON_THROW_ON_ERROR)['changes'];
        self::assertSame(['old' => 'Initial', 'new' => null], $changes['changelog']);
    }

    public function testAuditEntriesCannotBeCreatedManually(): void
    {
        $user = (new User())->setEmail('audit-admin@example.org')->setFirstname('Test')->setLastname('Admin')
            ->setPassword('test')->setRoles(['ROLE_ADMIN']);
        $user->enableTwoFactor('JBSWY3DPEHPK3PXP', []);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user, 'main', ['2fa_complete' => true]);
        $router = static::getContainer()->get('router');

        $this->client->request('GET', $router->generate('admin_audit_log_index', ['_locale' => 'de']));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a.action-new');

        $newUrl = $router->generate('admin_audit_log_new', ['_locale' => 'de']);
        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, $newUrl);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log'));
    }

    private function assertEntry(string $table, string $action, string $id, int $expectedCount = 1): array
    {
        $entries = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM audit_log WHERE event_type = ? AND entity_id = ? ORDER BY id DESC', [$table.'.'.$action, $id]);
        self::assertCount($expectedCount, $entries, $table.'.'.$action);
        self::assertSame($table, $entries[0]['entity_type']);

        return $entries[0];
    }
}

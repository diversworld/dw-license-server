<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\{Activation, ApiToken, AuditLog, Customer, License, LogEntry, Product, ResetPasswordRequest, UpdateRelease, User};
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class AuditSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
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

    public function testEveryEntityTableIsAuditedForCreateUpdateAndDelete(): void
    {
        $customer = (new Customer())->setCompany('Test')->setFirstname('Test')->setLastname('Customer')
            ->setEmail('customer@example.org')->setStreet('Test 1')->setZip('12345')->setCity('Berlin');
        $product = (new Product())->setName('Testmodul')->setSlug('testmodul');
        $license = (new License())->setCustomer($customer)->setProduct($product);
        $user = (new User())->setEmail('admin@example.org')->setFirstname('Test')->setLastname('Admin')->setPassword('secret-password');
        $entities = [
            $customer, $product, $license, $user,
            (new Activation())->setLicense($license)->setDomain('example.org'),
            (new ApiToken())->setCustomer($customer)->setToken('secret-api-token'),
            (new LogEntry())->setAction('test'),
            (new UpdateRelease())->setProduct($product)->setChangelog('Test')->setPackageUrl('https://example.org/package'),
            new ResetPasswordRequest($user, new \DateTimeImmutable('+1 hour'), 'selector', 'secret-reset-token'),
        ];
        $changes = ['city', 'name', 'notes', 'firstname', 'domain', 'name', 'action', 'version', 'expiresAt'];
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
            static fn ($metadata) => $metadata->name !== AuditLog::class,
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
            $this->em->remove($entity);
        }
        $this->em->flush();
        foreach ($tables as $table) {
            $this->assertEntry($table, 'deleted', $ids[$table]);
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

    private function assertEntry(string $table, string $action, string $id): array
    {
        $entries = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM audit_log WHERE event_type = ? AND entity_id = ?', [$table.'.'.$action, $id]);
        self::assertCount(1, $entries, $table.'.'.$action);
        self::assertSame($table, $entries[0]['entity_type']);

        return $entries[0];
    }
}

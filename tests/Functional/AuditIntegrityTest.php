<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\AuditCanonical;
use App\Entity\AuditLog;
use App\Service\{AuditIntegrity, AuditService};
use App\Tests\Support\IsolatedWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuditIntegrityTest extends IsolatedWebTestCase
{
    public function testCanonicalHashMasksSecretsAndCheckpointCanBeExported(): void
    {
        $user = $this->user();
        static::getContainer()->get(RequestStack::class)->push(Request::create('/test', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Audit test']));
        $log = $this->audit()->log('security.test', 'user', 'test', (string) $user->getId(), 'Changes recorded', $user, ['password' => 'do-not-store', 'nested' => ['apiToken' => 'do-not-store', 'a' => 1, 'b' => 2]]);
        self::assertSame('[redacted]', $log->getContext()['password']);
        self::assertSame('[redacted]', $log->getContext()['nested']['apiToken']);
        self::assertSame((string) $user->getId().':'.$user->getUserIdentifier(), $log->getActorIdentity());
        self::assertSame([], $this->integrity()->verify()['errors']);
        self::assertSame(AuditCanonical::json(['a' => 1, 'b' => ['x' => 2, 'y' => 3]]), AuditCanonical::json(['b' => ['y' => 3, 'x' => 2], 'a' => 1]));
        $command = new CommandTester(static::getContainer()->get(\App\Command\VerifyAuditCommand::class));
        self::assertSame(0, $command->execute(['--checkpoint' => true]));
        $checkpoint = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($log->getEntryHash(), $checkpoint['hash']);
        self::assertSame(2, $checkpoint['entries']);
    }

    #[DataProvider('immutableFields')]
    public function testTamperingWithImmutableFieldsFailsVerification(string $column, string $value): void
    {
        $this->audit()->log('test.created', 'test', 'test:1', null, 'Original');
        $this->em->getConnection()->update('audit_log', [$column => $value], ['chain_sequence' => 1]);
        $this->em->clear();
        self::assertNotEmpty($this->integrity()->verify()['errors']);
        $command = new CommandTester(static::getContainer()->get(\App\Command\VerifyAuditCommand::class));
        self::assertSame(1, $command->execute([]));
    }

    public static function immutableFields(): iterable
    {
        foreach (['message' => 'Tampered', 'created_at' => '2020-01-01 12:00:00', 'actor_identity' => 'other', 'entity_id' => 'other', 'ip_address' => '127.0.0.2', 'user_agent' => 'Tampered', 'context' => '{"extra":"tampered"}', 'previous_hash' => str_repeat('a', 64)] as $column => $value) { yield $column => [$column, $value]; }
    }

    public function testBusinessRollbackRestoresEntriesAndChainHead(): void
    {
        $first = $this->audit()->log('test.initial', 'test', 'initial', null, 'Initial');
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $this->audit()->log('test.rolled_back', 'test', 'rolled-back', null, 'Not committed');
        $connection->rollBack();
        $this->em->clear();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
        self::assertSame($first->getEntryHash(), $connection->fetchOne('SELECT entry_hash FROM audit_chain_head'));
        self::assertSame([], $this->integrity()->verify()['errors']);
    }

    public function testLegacyHashesRemainUnchangedAndVerifyAlongsideNewEntries(): void
    {
        $legacy = (new AuditLog())->setEventType('legacy.created')->setEntityType('legacy')->setEntityIdentifier('legacy:1')->setMessage('Legacy')->setContext(['legacy' => true]);
        $legacy->setEntryHash(AuditCanonical::hash($legacy));
        $this->em->persist($legacy); $this->em->flush();
        $new = $this->audit()->log('new.created', 'new', 'new:1', null, 'New');
        self::assertSame($legacy->getEntryHash(), $new->getPreviousHash());
        self::assertSame([], $this->integrity()->verify()['errors']);
        $this->em->clear();
        $preserved = $this->em->find(AuditLog::class, $legacy->getId());
        self::assertSame(1, $preserved->getHashVersion());
        self::assertSame($legacy->getEntryHash(), $preserved->getEntryHash());
    }

    #[DataProvider('forbiddenWrites')]
    public function testOrmCannotModifyOrRemoveCommittedAuditEntries(string $operation): void
    {
        $log = $this->audit()->log('test.immutable', 'test', 'test:1', null, 'Original');
        $this->em->clear();
        $managed = $this->em->find(AuditLog::class, $log->getId());
        try {
            if ($operation === 'update') { $managed->setMessage('Forbidden'); }
            else { $this->em->remove($managed); }
            $this->em->flush();
            self::fail('ORM audit writes must be refused.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('Audit records', $error->getMessage());
        }
        self::assertSame('Original', $this->em->getConnection()->fetchOne('SELECT message FROM audit_log'));
    }

    public static function forbiddenWrites(): iterable { yield ['update']; yield ['delete']; }

    public function testRemovingAnActorPreservesTheHashedIdentitySnapshot(): void
    {
        $user = $this->user();
        $identity = (string) $user->getId().':'.$user->getUserIdentifier();
        $log = $this->audit()->log('security.test', 'user', 'test', (string) $user->getId(), 'Actor snapshot', $user);
        $this->em->remove($user); $this->em->flush(); $this->em->clear();
        self::assertSame($identity, $this->em->find(AuditLog::class, $log->getId())->getActorIdentity());
        self::assertSame([], $this->integrity()->verify()['errors']);
    }

    public function testFailedLoginCreatesSecurityEventWithoutSubmittedCredentials(): void
    {
        $this->user();
        $this->client->request('GET', '/login');
        $form = $this->client->getCrawler()->filter('form')->form();
        $form['_username'] = 'untrusted-login@example.test';
        $form['_password'] = 'do-not-log-this-password';
        $this->client->submit($form);
        self::assertResponseRedirects();
        $row = $this->em->getConnection()->fetchAssociative("SELECT * FROM audit_log WHERE event_type = 'security.login_failed'");
        self::assertIsArray($row);
        self::assertStringNotContainsString('do-not-log-this-password', implode('', array_map(static fn ($value) => (string) $value, $row)));
        self::assertStringNotContainsString('untrusted-login@example.test', implode('', array_map(static fn ($value) => (string) $value, $row)));
        self::assertSame([], $this->integrity()->verify()['errors']);
    }

    private function audit(): AuditService { return static::getContainer()->get(AuditService::class); }
    private function integrity(): AuditIntegrity { return static::getContainer()->get(AuditIntegrity::class); }
}

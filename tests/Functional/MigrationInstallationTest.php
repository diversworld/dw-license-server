<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use DoctrineMigrations\Version20261002153158;
use DoctrineMigrations\Version20261002154946;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/** Runs real production installation commands against disposable MySQL/MariaDB databases. */
class MigrationInstallationTest extends KernelTestCase
{
    private const string CLEANUP = 'DoctrineMigrations\\Version20261003080525';
    private const string PREVIOUS = 'DoctrineMigrations\\Version20261002154946';

    public static function interruptedMigrationStages(): iterable
    {
        yield 'only the action table exists' => [1];
        yield 'foreign keys and one product policy exist' => [4];
        yield 'nullable recovery codes exist' => [7];
        yield 'all schema changes exist but are unrecorded' => [9];
    }

    #[DataProvider('interruptedMigrationStages')]
    public function testInterruptedSchemaChangesCanBeResumed(int $executedStatements): void
    {
        $this->withDatabase(function (Connection $connection, array $environment) use ($executedStatements): void {
            $this->console(['doctrine:migrations:migrate', 'DoctrineMigrations\\Version20261002130822', '--no-interaction'], $environment);
            $userId = random_bytes(16);
            $connection->insert('user', ['id' => $userId, 'email' => 'interrupted@example.test',
                'firstname' => 'Existing', 'lastname' => 'User', 'roles' => '["ROLE_ADMIN"]',
                'password' => 'unused-test-password', 'active' => 1, 'created_at' => '2026-10-02 12:00:00']);

            // Simulate committed DDL followed by a crash before Doctrine records the version.
            require_once dirname(__DIR__, 2).'/migrations/Version20261002153158.php';
            require_once dirname(__DIR__, 2).'/migrations/Version20261002154946.php';
            $migration = new Version20261002153158($connection, new NullLogger());
            $migration->up($connection->createSchemaManager()->introspectSchema());
            self::assertCount(9, $migration->getSql());
            foreach (array_slice($migration->getSql(), 0, $executedStatements) as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }

            $productId = random_bytes(16);
            $customerId = random_bytes(16);
            $licenseId = random_bytes(16);
            $actionId = random_bytes(16);
            $connection->insert('product', ['id' => $productId, 'slug' => 'migration-test', 'created_at' => '2026-10-02 12:00:00']);
            $connection->insert('customer', ['id' => $customerId, 'company' => 'Test', 'firstname' => 'Test',
                'lastname' => 'Customer', 'email' => 'customer@example.test', 'street' => 'Test 1',
                'zip' => '12345', 'city' => 'Test', 'created_at' => '2026-10-02 12:00:00']);
            $connection->insert('license', ['id' => $licenseId, 'license_key' => 'migration-license',
                'type' => 'subscription', 'status' => 'active', 'max_domains' => 1, 'features' => '[]',
                'created_at' => '2026-10-02 12:00:00', 'customer_id' => $customerId, 'product_id' => $productId]);
            $connection->insert('license_action', ['id' => $actionId, 'created_at' => '2026-10-02 12:00:00',
                'action' => 'renew', 'reason' => 'Existing history', 'from_status' => 'active',
                'to_status' => 'active', 'license_id' => $licenseId, 'performed_by_id' => $userId]);
            $productTable = $connection->createSchemaManager()->introspectTable('product');
            if ($productTable->hasColumn('token_lifetime_seconds')) {
                $connection->update('product', ['token_lifetime_seconds' => 7200], ['id' => $productId]);
            }

            $userTable = $connection->createSchemaManager()->introspectTable('user');
            if ($userTable->hasColumn('totp_secret')) {
                $connection->update('user', ['totp_secret' => 'EXISTINGSECRET'], ['id' => $userId]);
            }
            if ($executedStatements === 9) {
                $connection->update('user', ['backup_code_hashes' => '["existing-code-hash"]'], ['id' => $userId]);
                $decision = new Version20261002154946($connection, new NullLogger());
                $decision->up($connection->createSchemaManager()->introspectSchema());
                foreach ($decision->getSql() as $query) {
                    $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
                $connection->update('user', ['two_factor_declined' => 1], ['id' => $userId]);
            }

            $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment);
            $this->console(['doctrine:schema:validate', '-v'], $environment);
            $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment);
            self::assertSame($executedStatements === 9 ? '["existing-code-hash"]' : '[]', $connection->fetchOne('SELECT backup_code_hashes FROM user WHERE id = ?', [$userId]));
            self::assertSame('Existing history', $connection->fetchOne('SELECT reason FROM license_action WHERE id = ?', [$actionId]));
            self::assertSame($productTable->hasColumn('token_lifetime_seconds') ? 7200 : 86400, (int) $connection->fetchOne('SELECT token_lifetime_seconds FROM product WHERE id = ?', [$productId]));
            if ($userTable->hasColumn('totp_secret')) {
                self::assertSame('EXISTINGSECRET', $connection->fetchOne('SELECT totp_secret FROM user WHERE id = ?', [$userId]));
            }
            if ($executedStatements === 9) {
                self::assertSame(1, (int) $connection->fetchOne('SELECT two_factor_declined FROM user WHERE id = ?', [$userId]));
            }
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?', ['DoctrineMigrations\\Version20261002153158']));
        });
    }

    public function testInterruptedArchiveMigrationPreservesAnExistingArchiveDate(): void
    {
        $this->withDatabase(function (Connection $connection, array $environment): void {
            $this->console(['doctrine:migrations:migrate', self::PREVIOUS, '--no-interaction'], $environment);
            require_once dirname(__DIR__, 2).'/migrations/Version20261003083348.php';
            $migration = new \DoctrineMigrations\Version20261003083348($connection, new NullLogger());
            $migration->up($connection->createSchemaManager()->introspectSchema());
            self::assertCount(3, $migration->getSql());
            $query = $migration->getSql()[0];
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            $id = random_bytes(16);
            $connection->insert('customer', ['id' => $id, 'company' => 'Test', 'firstname' => 'Test',
                'lastname' => 'Customer', 'email' => 'archived@example.test', 'street' => 'Test 1',
                'zip' => '12345', 'city' => 'Test', 'created_at' => '2026-10-02 12:00:00', 'deleted_at' => '2026-10-03 12:00:00']);
            $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment);
            $this->console(['doctrine:schema:validate', '-v'], $environment);
            self::assertSame('2026-10-03 12:00:00', $connection->fetchOne('SELECT deleted_at FROM customer WHERE id = ?', [$id]));
        });
    }

    public function testFreshInstallationAndRepeatedMigrationRun(): void
    {
        $this->withDatabase(function (Connection $connection, array $environment): void {
            $this->console(['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'], $environment);
            $this->console(['doctrine:schema:validate', '-v'], $environment);
            $this->console(['app:license:init'], $environment);
            $this->console(['app:license:init'], $environment);
            $this->console(['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'], $environment);
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM product'));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
            self::assertSame(86400, (int) $connection->fetchOne('SELECT token_lifetime_seconds FROM product'));
            self::assertSame(2592000, (int) $connection->fetchOne('SELECT grace_period_seconds FROM product'));
            self::assertSame(count(glob(dirname(__DIR__, 2).'/migrations/Version*.php')), (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
            self::assertFalse($connection->createSchemaManager()->tablesExist(['license_audit_log']));
        });
    }

    public function testLegacyAuditEntriesSurviveUpgradeDowngradeAndRetry(): void
    {
        $this->withDatabase(function (Connection $connection, array $environment): void {
            $this->console(['doctrine:migrations:migrate', 'DoctrineMigrations\\Version20261002130822', '--no-interaction'], $environment);
            $userId = random_bytes(16);
            $connection->insert('user', ['id' => $userId, 'email' => 'migration@example.test',
                'firstname' => 'Existing', 'lastname' => 'User', 'roles' => '["ROLE_ADMIN"]',
                'password' => 'unused-test-password', 'active' => 1, 'created_at' => '2026-10-02 12:00:00']);
            $first = $this->auditEntry('First legacy entry');
            $second = $this->auditEntry('Second legacy entry');
            $connection->insert('license_audit_log', $first);
            $connection->insert('license_audit_log', $second);
            // Simulate an interrupted earlier copy: an identical ID must not duplicate the row.
            $connection->insert('audit_log', $first);
            $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment);
            $this->console(['doctrine:schema:validate', '-v'], $environment);
            self::assertSame('[]', $connection->fetchOne('SELECT backup_code_hashes FROM user WHERE id = ?', [$userId]));
            self::assertNull($connection->createSchemaManager()->introspectTable('user')->getColumn('backup_code_hashes')->getDefault());
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
            foreach ([$first, $second] as $entry) {
                self::assertEquals($entry, $connection->fetchAssociative('SELECT * FROM audit_log WHERE id = ?', [$entry['id']]));
            }
            self::assertFalse($connection->createSchemaManager()->tablesExist(['license_audit_log']));
            $this->console(['doctrine:migrations:migrate', self::PREVIOUS, '--no-interaction'], $environment);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM license_audit_log'));
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
            $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log'));
            $this->console(['doctrine:schema:validate', '-v'], $environment);
        });
    }

    public function testAlreadyRemovedLegacyTableIsSupported(): void
    {
        $this->withDatabase(function (Connection $connection, array $environment): void {
            $this->console(['doctrine:migrations:migrate', self::PREVIOUS, '--no-interaction'], $environment);
            $connection->createSchemaManager()->dropTable('license_audit_log');
            $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment);
            $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment);
            $this->console(['doctrine:schema:validate', '-v'], $environment);
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?', [self::CLEANUP]));
        });
    }

    public function testConflictingAuditIdentifiersAbortWithoutLosingEntries(): void
    {
        $this->withDatabase(function (Connection $connection, array $environment): void {
            $this->console(['doctrine:migrations:migrate', self::PREVIOUS, '--no-interaction'], $environment);
            $entry = $this->auditEntry('Legacy value');
            $connection->insert('license_audit_log', $entry);
            $connection->insert('audit_log', array_replace($entry, ['message' => 'Different current value']));
            $output = $this->console(['doctrine:migrations:migrate', '--no-interaction'], $environment, expectFailure: true);
            self::assertStringContainsString('different contents', $output);
            self::assertSame('Legacy value', $connection->fetchOne('SELECT message FROM license_audit_log'));
            self::assertSame('Different current value', $connection->fetchOne('SELECT message FROM audit_log'));
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?', [self::CLEANUP]));
        });
    }

    private function withDatabase(callable $test): void
    {
        $dsn = getenv('MIGRATION_TEST_DATABASE_URL');
        if (!$dsn) {
            self::markTestSkipped('Set MIGRATION_TEST_DATABASE_URL to a MySQL/MariaDB test server with CREATE DATABASE permission.');
        }
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($dsn);
        self::assertSame('pdo_mysql', $parameters['driver']);
        $database = 'license_migrations_'.bin2hex(random_bytes(8));
        $admin = DriverManager::getConnection($parameters);
        $parameters['serverVersion'] = (string) $admin->fetchOne('SELECT VERSION()');
        $schema = $admin->createSchemaManager();
        $schema->createDatabase($database);
        $connection = null;
        try {
            $url = preg_replace('~(/)[^/?]*(\?.*)?$~', '$1'.$database.'$2', $dsn);
            self::assertIsString($url);
            $url .= (str_contains($url, '?') ? '&' : '?').'serverVersion='.rawurlencode($parameters['serverVersion']).'&charset=utf8mb4';
            // Dotenv variables inherited from PHPUnit must not override the explicitly isolated DSN.
            $environment = ['DATABASE_URL' => $url, 'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'SYMFONY_DOTENV_VARS' => false];
            self::assertStringContainsString($database, $this->console(['dbal:run-sql', 'SELECT DATABASE()'], $environment));
            $connection = DriverManager::getConnection(array_replace($parameters, ['dbname' => $database]));
            $test($connection, $environment);
        } finally {
            $connection?->close();
            // Only the random database created by this test is ever removed.
            $schema->dropDatabase($database);
            $admin->close();
        }
    }

    private function console(array $arguments, array $environment, bool $expectFailure = false): string
    {
        $project = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $project.'/bin/console', ...$arguments, '--env='.$environment['APP_ENV'], '--no-debug'], $project, $environment);
        $process->setTimeout(60);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();
        if ($expectFailure) {
            self::assertFalse($process->isSuccessful(), $output);
        } else {
            self::assertSame(0, $process->getExitCode(), $output);
        }

        return $output;
    }

    private function auditEntry(string $message): array
    {
        return ['id' => random_bytes(16), 'event_type' => 'license.updated', 'entity_type' => 'license',
            'entity_identifier' => 'legacy-license', 'entity_id' => null, 'message' => $message,
            'context' => '{"source":"legacy"}', 'ip_address' => null, 'user_agent' => null,
            'previous_hash' => null, 'entry_hash' => hash('sha256', $message),
            'created_at' => '2026-10-02 12:00:00', 'performed_by_id' => null];
    }
}

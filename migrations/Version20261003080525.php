<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003080525 extends AbstractMigration
{
    private const string COLUMNS = 'id, event_type, entity_type, entity_identifier, entity_id, message, context, ip_address, user_agent, previous_hash, entry_hash, created_at, performed_by_id';

    public function getDescription(): string
    {
        return 'Remove the non-portable recovery-code default and preserve legacy audit entries in audit_log';
    }

    public function isTransactional(): bool
    {
        // MySQL/MariaDB DDL commits implicitly; do not advertise an atomic DDL transaction.
        return false;
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('user')->getColumn('backup_code_hashes')->getDefault() !== null) {
            $this->addSql('ALTER TABLE user CHANGE backup_code_hashes backup_code_hashes JSON NOT NULL');
        }

        if (!$schema->hasTable('license_audit_log')) {
            // Existing installations may already have removed the obsolete table.
            return;
        }
        $this->abortIf(!$schema->hasTable('audit_log'), 'The current audit_log table must exist before migrating legacy entries.');

        // Permit identical entries copied by an earlier interrupted run, but never discard conflicting data.
        foreach ($this->connection->iterateAssociative('SELECT legacy.* FROM license_audit_log legacy INNER JOIN audit_log current_log ON current_log.id = legacy.id') as $legacy) {
            $current = $this->connection->fetchAssociative('SELECT * FROM audit_log WHERE id = ?', [$legacy['id']]);
            ksort($legacy);
            ksort($current);
            $this->abortIf($legacy !== $current, 'An audit identifier exists in both tables with different contents; resolve the conflict before retrying.');
        }
        $columns = self::COLUMNS;
        $selected = 'legacy.'.str_replace(', ', ', legacy.', $columns);
        $this->addSql('INSERT INTO audit_log ('.$columns.') SELECT '.$selected.' FROM license_audit_log legacy LEFT JOIN audit_log current_log ON current_log.id = legacy.id WHERE current_log.id IS NULL');
        if ($schema->getTable('license_audit_log')->hasForeignKey('FK_4EA0356B2E65C292')) {
            $this->addSql('ALTER TABLE license_audit_log DROP FOREIGN KEY `FK_4EA0356B2E65C292`');
        }
        $this->addSql('DROP TABLE license_audit_log');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE license_audit_log (id BINARY(16) NOT NULL, event_type VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_520_ci`, message VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_520_ci`, context JSON NOT NULL, ip_address VARCHAR(45) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_520_ci`, user_agent VARCHAR(512) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_520_ci`, created_at DATETIME NOT NULL, performed_by_id BINARY(16) DEFAULT NULL, entity_type VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_520_ci`, entity_identifier VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_520_ci`, entity_id VARCHAR(36) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_520_ci`, previous_hash VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_520_ci`, entry_hash VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_520_ci`, INDEX IDX_4EA0356B2E65C292 (performed_by_id), INDEX idx_audit_created (created_at), INDEX idx_audit_event (event_type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_520_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE license_audit_log ADD CONSTRAINT `FK_4EA0356B2E65C292` FOREIGN KEY (performed_by_id) REFERENCES user (id)');
        // Preserve all history if the following downgrade removes audit_log.
        $this->addSql('INSERT INTO license_audit_log ('.self::COLUMNS.') SELECT '.self::COLUMNS.' FROM audit_log');
    }
}

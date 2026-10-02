<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002130329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE license_audit_log DROP FOREIGN KEY `FK_4EA0356B460F904B`');
        $this->addSql('DROP INDEX IDX_4EA0356B460F904B ON license_audit_log');
        $this->addSql('ALTER TABLE license_audit_log ADD entity_type VARCHAR(100) NOT NULL, ADD entity_identifier VARCHAR(255) NOT NULL, ADD entity_id VARCHAR(36) DEFAULT NULL, ADD previous_hash VARCHAR(64) DEFAULT NULL, ADD entry_hash VARCHAR(64) NOT NULL, DROP license_id, CHANGE user_agent user_agent VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE license_audit_log ADD license_id BINARY(16) DEFAULT NULL, DROP entity_type, DROP entity_identifier, DROP entity_id, DROP previous_hash, DROP entry_hash, CHANGE user_agent user_agent VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE license_audit_log ADD CONSTRAINT `FK_4EA0356B460F904B` FOREIGN KEY (license_id) REFERENCES license (id)');
        $this->addSql('CREATE INDEX IDX_4EA0356B460F904B ON license_audit_log (license_id)');
    }
}

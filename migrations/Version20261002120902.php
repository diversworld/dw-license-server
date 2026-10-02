<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002120902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE license_audit_log (id BINARY(16) NOT NULL, event_type VARCHAR(100) NOT NULL, message VARCHAR(255) NOT NULL, context JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL, performed_by_id BINARY(16) DEFAULT NULL, license_id BINARY(16) DEFAULT NULL, INDEX idx_audit_created (created_at), INDEX idx_audit_event (event_type), INDEX IDX_4EA0356B2E65C292 (performed_by_id), INDEX IDX_4EA0356B460F904B (license_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE license_audit_log ADD CONSTRAINT FK_4EA0356B2E65C292 FOREIGN KEY (performed_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE license_audit_log ADD CONSTRAINT FK_4EA0356B460F904B FOREIGN KEY (license_id) REFERENCES license (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE license_audit_log DROP FOREIGN KEY FK_4EA0356B2E65C292');
        $this->addSql('ALTER TABLE license_audit_log DROP FOREIGN KEY FK_4EA0356B460F904B');
        $this->addSql('DROP TABLE license_audit_log');
    }
}

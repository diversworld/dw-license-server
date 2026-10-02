<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002130822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id BINARY(16) NOT NULL, event_type VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_identifier VARCHAR(255) NOT NULL, entity_id VARCHAR(36) DEFAULT NULL, message VARCHAR(255) NOT NULL, context JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(512) DEFAULT NULL, previous_hash VARCHAR(64) DEFAULT NULL, entry_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, performed_by_id BINARY(16) DEFAULT NULL, INDEX idx_audit_event (event_type), INDEX idx_audit_created (created_at), INDEX IDX_F6E1C0F52E65C292 (performed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F52E65C292 FOREIGN KEY (performed_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F52E65C292');
        $this->addSql('DROP TABLE audit_log');
    }
}

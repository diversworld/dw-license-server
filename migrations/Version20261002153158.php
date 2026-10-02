<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002153158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product token policies, mandatory TOTP and immutable license action history';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE license_action (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, action VARCHAR(20) NOT NULL, reason LONGTEXT NOT NULL, from_status VARCHAR(20) NOT NULL, to_status VARCHAR(20) NOT NULL, from_expires_at DATETIME DEFAULT NULL, to_expires_at DATETIME DEFAULT NULL, license_id BINARY(16) NOT NULL, performed_by_id BINARY(16) DEFAULT NULL, INDEX IDX_3D22E294460F904B (license_id), INDEX IDX_3D22E2942E65C292 (performed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE license_action ADD CONSTRAINT FK_3D22E294460F904B FOREIGN KEY (license_id) REFERENCES license (id)');
        $this->addSql('ALTER TABLE license_action ADD CONSTRAINT FK_3D22E2942E65C292 FOREIGN KEY (performed_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE product ADD token_lifetime_seconds INT DEFAULT 86400 NOT NULL, ADD grace_period_seconds INT DEFAULT 2592000 NOT NULL');
        $this->addSql('ALTER TABLE user ADD totp_secret VARCHAR(128) DEFAULT NULL, ADD backup_code_hashes JSON DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE license_action DROP FOREIGN KEY FK_3D22E294460F904B');
        $this->addSql('ALTER TABLE license_action DROP FOREIGN KEY FK_3D22E2942E65C292');
        $this->addSql('DROP TABLE license_action');
        $this->addSql('ALTER TABLE product DROP token_lifetime_seconds, DROP grace_period_seconds');
        $this->addSql('ALTER TABLE user DROP totp_secret, DROP backup_code_hashes');
    }
}

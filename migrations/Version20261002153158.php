<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002153158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product token policies, TOTP and immutable license action history; resume partially applied schema changes';
    }

    public function isTransactional(): bool
    {
        // MySQL/MariaDB DDL commits implicitly, including before a failed statement.
        return false;
    }

    public function up(Schema $schema): void
    {
        $actions = $schema->hasTable('license_action') ? $schema->getTable('license_action') : null;
        if ($actions === null) {
            $this->addSql('CREATE TABLE license_action (id BINARY(16) NOT NULL, created_at DATETIME NOT NULL, action VARCHAR(20) NOT NULL, reason LONGTEXT NOT NULL, from_status VARCHAR(20) NOT NULL, to_status VARCHAR(20) NOT NULL, from_expires_at DATETIME DEFAULT NULL, to_expires_at DATETIME DEFAULT NULL, license_id BINARY(16) NOT NULL, performed_by_id BINARY(16) DEFAULT NULL, INDEX IDX_3D22E294460F904B (license_id), INDEX IDX_3D22E2942E65C292 (performed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if ($actions === null || !$actions->hasForeignKey('FK_3D22E294460F904B')) {
            $this->addSql('ALTER TABLE license_action ADD CONSTRAINT FK_3D22E294460F904B FOREIGN KEY (license_id) REFERENCES license (id)');
        }
        if ($actions === null || !$actions->hasForeignKey('FK_3D22E2942E65C292')) {
            $this->addSql('ALTER TABLE license_action ADD CONSTRAINT FK_3D22E2942E65C292 FOREIGN KEY (performed_by_id) REFERENCES user (id) ON DELETE SET NULL');
        }

        $product = $schema->getTable('product');
        if (!$product->hasColumn('token_lifetime_seconds')) {
            $this->addSql('ALTER TABLE product ADD token_lifetime_seconds INT DEFAULT 86400 NOT NULL');
        }
        if (!$product->hasColumn('grace_period_seconds')) {
            $this->addSql('ALTER TABLE product ADD grace_period_seconds INT DEFAULT 2592000 NOT NULL');
        }

        $user = $schema->getTable('user');
        if (!$user->hasColumn('totp_secret')) {
            $this->addSql('ALTER TABLE user ADD totp_secret VARCHAR(128) DEFAULT NULL');
        }
        if (!$user->hasColumn('backup_code_hashes')) {
            // Add nullable first, then backfill without overwriting existing recovery codes.
            $this->addSql('ALTER TABLE user ADD backup_code_hashes JSON DEFAULT NULL');
        }
        $this->addSql('UPDATE user SET backup_code_hashes = ? WHERE backup_code_hashes IS NULL', ['[]']);
        $this->addSql('ALTER TABLE user CHANGE backup_code_hashes backup_code_hashes JSON NOT NULL');
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

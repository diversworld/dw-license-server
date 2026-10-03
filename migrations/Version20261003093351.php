<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003093351 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support encrypted authenticator storage and revocable user sessions';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        if (!$schema->getTable('user')->hasColumn('security_version')) { $this->addSql('ALTER TABLE user ADD security_version INT DEFAULT 0 NOT NULL'); }
        if ($schema->getTable('user')->getColumn('totp_secret')->getLength() !== 255) { $this->addSql('ALTER TABLE user CHANGE totp_secret totp_secret VARCHAR(255) DEFAULT NULL'); }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne("SELECT COUNT(*) FROM user WHERE totp_secret LIKE 'enc:v1:%'") > 0, 'Restore/decrypt encrypted authenticators explicitly before removing their storage support.');
        $this->addSql('ALTER TABLE user DROP security_version, CHANGE totp_secret totp_secret VARCHAR(128) DEFAULT NULL');
    }
}

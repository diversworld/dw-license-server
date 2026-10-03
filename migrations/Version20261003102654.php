<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003102654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persistent expiry reminder dispatch identifiers and asynchronous queue';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $exists = $schema->hasTable('reminder_delivery');
        if (!$exists) { $this->addSql('CREATE TABLE reminder_delivery (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, attempts INT NOT NULL, history JSON NOT NULL, delivered_at DATETIME DEFAULT NULL, expiry DATETIME NOT NULL, days_before INT NOT NULL, recipient VARCHAR(255) NOT NULL, locale VARCHAR(2) NOT NULL, license_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_reminder_dispatch (license_id, expiry, days_before, recipient), INDEX IDX_55F2EB86460F904B (license_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'); }
        if (!$schema->hasTable('messenger_messages')) { $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))'); }
        if (!$exists || !$schema->getTable('reminder_delivery')->hasForeignKey('FK_55F2EB86460F904B')) { $this->addSql('ALTER TABLE reminder_delivery ADD CONSTRAINT FK_55F2EB86460F904B FOREIGN KEY (license_id) REFERENCES license (id)'); }
        if (!$schema->getTable('customer')->hasColumn('reminder_locale')) { $this->addSql('ALTER TABLE customer ADD reminder_locale VARCHAR(2) DEFAULT \'de\' NOT NULL'); }
        if (!$schema->getTable('customer')->hasColumn('reminder_recipients')) { $this->addSql('ALTER TABLE customer ADD reminder_recipients JSON DEFAULT NULL'); }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM reminder_delivery') > 0 || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages') > 0, 'Retain or explicitly export notification history and pending messages before downgrade.');
        $this->addSql('ALTER TABLE reminder_delivery DROP FOREIGN KEY FK_55F2EB86460F904B');
        $this->addSql('DROP TABLE reminder_delivery');
        $this->addSql('DROP TABLE messenger_messages');
        $this->addSql('ALTER TABLE customer DROP reminder_locale, DROP reminder_recipients');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003101128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit customer assignments while preserving existing global access';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $exists = $schema->hasTable('user_customer');
        if (!$exists) { $this->addSql('CREATE TABLE user_customer (user_id BINARY(16) NOT NULL, customer_id BINARY(16) NOT NULL, INDEX IDX_61B46A09A76ED395 (user_id), INDEX IDX_61B46A099395C3F3 (customer_id), PRIMARY KEY (user_id, customer_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'); }
        if (!$exists || !$schema->getTable('user_customer')->hasForeignKey('FK_61B46A09A76ED395')) { $this->addSql('ALTER TABLE user_customer ADD CONSTRAINT FK_61B46A09A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE'); }
        if (!$exists || !$schema->getTable('user_customer')->hasForeignKey('FK_61B46A099395C3F3')) { $this->addSql('ALTER TABLE user_customer ADD CONSTRAINT FK_61B46A099395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE'); }
        if (!$schema->getTable('user')->hasColumn('global_access')) { $this->addSql('ALTER TABLE user ADD global_access TINYINT DEFAULT 1 NOT NULL'); }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM user WHERE global_access = 0') > 0 || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user_customer') > 0, 'Explicitly remove restricted accounts/assignments before removing customer isolation.');
        $this->addSql('ALTER TABLE user_customer DROP FOREIGN KEY FK_61B46A09A76ED395');
        $this->addSql('ALTER TABLE user_customer DROP FOREIGN KEY FK_61B46A099395C3F3');
        $this->addSql('DROP TABLE user_customer');
        $this->addSql('ALTER TABLE user DROP global_access');
    }
}

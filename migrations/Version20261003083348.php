<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003083348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reversible archive timestamps for customers, products and licenses';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        if (!$schema->getTable('customer')->hasColumn('deleted_at')) {
            $this->addSql('ALTER TABLE customer ADD deleted_at DATETIME DEFAULT NULL');
        }
        if (!$schema->getTable('license')->hasColumn('deleted_at')) {
            $this->addSql('ALTER TABLE license ADD deleted_at DATETIME DEFAULT NULL');
        }
        if (!$schema->getTable('product')->hasColumn('deleted_at')) {
            $this->addSql('ALTER TABLE product ADD deleted_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customer DROP deleted_at');
        $this->addSql('ALTER TABLE license DROP deleted_at');
        $this->addSql('ALTER TABLE product DROP deleted_at');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003103656 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve before/after details for controlled portal domain changes';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        if (!$schema->getTable('license_action')->hasColumn('details')) { $this->addSql('ALTER TABLE license_action ADD details JSON DEFAULT NULL'); }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM license_action WHERE details IS NOT NULL') > 0, 'Export and preserve domain-change history before removing details.');
        $this->addSql('ALTER TABLE license_action DROP details');
    }
}

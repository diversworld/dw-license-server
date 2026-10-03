<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002154946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store optional two-factor enrollment decision for each user';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('user')->hasColumn('two_factor_declined')) {
            $this->addSql('ALTER TABLE user ADD two_factor_declined TINYINT DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP two_factor_declined');
    }
}

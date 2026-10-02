<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Compatibility entry for the duplicate migration generated during development. */
final class Version20261002153213 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'No-op: these schema changes are already covered by Version20261002153158';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}

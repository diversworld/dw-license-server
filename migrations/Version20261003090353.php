<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003090353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add versioned concurrent audit-chain sequencing without rewriting legacy hashes';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('audit_chain_head')) {
            $this->addSql('CREATE TABLE audit_chain_head (id INT NOT NULL, sequence INT NOT NULL, entry_hash VARCHAR(64) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        $table = $schema->getTable('audit_log');
        foreach (['hash_version' => 'INT DEFAULT 1 NOT NULL', 'chain_sequence' => 'INT DEFAULT NULL', 'actor_identity' => 'VARCHAR(255) DEFAULT NULL'] as $column => $definition) {
            if (!$table->hasColumn($column)) { $this->addSql('ALTER TABLE audit_log ADD '.$column.' '.$definition); }
        }
        if (!$table->hasIndex('uniq_audit_sequence')) { $this->addSql('CREATE UNIQUE INDEX uniq_audit_sequence ON audit_log (chain_sequence)'); }
        $actorKey = null;
        foreach ($table->getForeignKeys() as $key) {
            if ($key->getLocalColumns() === ['performed_by_id']) { $actorKey = $key; break; }
        }
        if ($actorKey === null || strtoupper($actorKey->onDelete() ?? '') !== 'SET NULL') {
            if ($actorKey !== null) { $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY '.$actorKey->getName()); }
            $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F52E65C292 FOREIGN KEY (performed_by_id) REFERENCES user (id) ON DELETE SET NULL');
        }
        // Preserve every legacy row, including its hash, and anchor the first new entry to its tip.
        $this->addSql('INSERT INTO audit_chain_head (id, sequence, entry_hash) SELECT 1, COALESCE((SELECT MAX(chain_sequence) FROM audit_log), 0), (SELECT entry_hash FROM audit_log ORDER BY COALESCE(chain_sequence, 0) DESC, created_at DESC, id DESC LIMIT 1) WHERE NOT EXISTS (SELECT 1 FROM audit_chain_head WHERE id = 1)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE hash_version = 2') > 0, 'Cannot remove hash version metadata after version-2 audit entries have been written.');
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE audit_chain_head');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F52E65C292');
        $this->addSql('DROP INDEX uniq_audit_sequence ON audit_log');
        $this->addSql('ALTER TABLE audit_log DROP hash_version, DROP chain_sequence, DROP actor_identity');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT `FK_F6E1C0F52E65C292` FOREIGN KEY (performed_by_id) REFERENCES user (id)');
    }
}

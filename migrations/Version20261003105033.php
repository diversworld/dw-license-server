<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003105033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hash existing API credentials and add scoped idempotency and signed webhook delivery history';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        if (!$schema->hasTable('api_operation')) { $this->addSql('CREATE TABLE api_operation (id BINARY(16) NOT NULL, idempotency_key VARCHAR(128) NOT NULL, body_hash VARCHAR(64) NOT NULL, response JSON NOT NULL, credential_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_api_idempotency (credential_id, idempotency_key), INDEX IDX_645503162558A7A5 (credential_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'); }
        if (!$schema->hasTable('webhook_delivery')) { $this->addSql('CREATE TABLE webhook_delivery (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, attempts INT NOT NULL, history JSON NOT NULL, event_id BINARY(16) NOT NULL, payload JSON NOT NULL, endpoint_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_webhook_event_endpoint (endpoint_id, event_id), INDEX IDX_C97B6A3821AF7E36 (endpoint_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'); }
        if (!$schema->hasTable('webhook_endpoint')) { $this->addSql('CREATE TABLE webhook_endpoint (id BINARY(16) NOT NULL, active TINYINT NOT NULL, url VARCHAR(2048) NOT NULL, secret_ciphertext VARCHAR(255) NOT NULL, events JSON NOT NULL, customer_id BINARY(16) NOT NULL, INDEX IDX_3AB889539395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`'); }
        if (!$schema->hasTable('api_operation') || !$schema->getTable('api_operation')->hasForeignKey('FK_645503162558A7A5')) { $this->addSql('ALTER TABLE api_operation ADD CONSTRAINT FK_645503162558A7A5 FOREIGN KEY (credential_id) REFERENCES api_token (id)'); }
        if (!$schema->hasTable('webhook_delivery') || !$schema->getTable('webhook_delivery')->hasForeignKey('FK_C97B6A3821AF7E36')) { $this->addSql('ALTER TABLE webhook_delivery ADD CONSTRAINT FK_C97B6A3821AF7E36 FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoint (id)'); }
        if (!$schema->hasTable('webhook_endpoint') || !$schema->getTable('webhook_endpoint')->hasForeignKey('FK_3AB889539395C3F3')) { $this->addSql('ALTER TABLE webhook_endpoint ADD CONSTRAINT FK_3AB889539395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id)'); }
        if (!$schema->getTable('api_token')->hasColumn('token_hash')) { $this->addSql('ALTER TABLE api_token ADD token_hash VARCHAR(64) DEFAULT NULL'); }
        if (!$schema->getTable('api_token')->hasColumn('scopes')) { $this->addSql('ALTER TABLE api_token ADD scopes JSON DEFAULT NULL'); }
        if ($schema->getTable('api_token')->getColumn('token')->getNotnull()) { $this->addSql('ALTER TABLE api_token CHANGE token token VARCHAR(255) DEFAULT NULL'); }
        $this->addSql('UPDATE api_token SET token_hash = SHA2(token, 256) WHERE token IS NOT NULL AND token_hash IS NULL');
        $this->addSql('UPDATE api_token SET token = NULL WHERE token IS NOT NULL AND token_hash = SHA2(token, 256)');
        if (!$schema->getTable('api_token')->hasIndex('idx_api_token_hash')) { $this->addSql('CREATE INDEX idx_api_token_hash ON api_token (token_hash)'); }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM api_token WHERE token_hash IS NOT NULL') > 0 || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM api_operation') > 0 || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM webhook_endpoint') > 0, 'Hashed credentials cannot be reversed and integration history must be preserved; use a deliberate restored backup for rollback.');
        $this->addSql('ALTER TABLE api_operation DROP FOREIGN KEY FK_645503162558A7A5');
        $this->addSql('ALTER TABLE webhook_delivery DROP FOREIGN KEY FK_C97B6A3821AF7E36');
        $this->addSql('ALTER TABLE webhook_endpoint DROP FOREIGN KEY FK_3AB889539395C3F3');
        $this->addSql('DROP TABLE api_operation');
        $this->addSql('DROP TABLE webhook_delivery');
        $this->addSql('DROP TABLE webhook_endpoint');
        $this->addSql('DROP INDEX idx_api_token_hash ON api_token');
        $this->addSql('ALTER TABLE api_token DROP token_hash, DROP scopes, CHANGE token token VARCHAR(255) NOT NULL');
    }
}

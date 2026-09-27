<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927102119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activation (id INT NOT NULL, domain VARCHAR(255) NOT NULL, ip_adress VARCHAR(16) DEFAULT NULL, contao_version VARCHAR(6) DEFAULT NULL, module_version VARCHAR(255) DEFAULT NULL, active TINYINT DEFAULT NULL, activated_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, license_id BINARY(16) DEFAULT NULL, INDEX IDX_1C686077460F904B (license_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE api_token (id BINARY(16) NOT NULL, token VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, active TINYINT DEFAULT NULL, cerated_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, customer_id BINARY(16) DEFAULT NULL, INDEX IDX_7BA2F5EB9395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE customer (id BINARY(16) NOT NULL, company VARCHAR(255) NOT NULL, firstname VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, street VARCHAR(255) NOT NULL, zip VARCHAR(6) NOT NULL, city VARCHAR(255) NOT NULL, country VARCHAR(255) DEFAULT NULL, vat_id VARCHAR(20) DEFAULT NULL, active TINYINT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE license (id BINARY(16) NOT NULL, license_key VARCHAR(64) NOT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, max_domains INT NOT NULL, features JSON NOT NULL, expires_at DATETIME DEFAULT NULL, last_validation_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, customer_id BINARY(16) NOT NULL, product_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_5768F419C54B2 (license_key), INDEX IDX_5768F4199395C3F3 (customer_id), INDEX IDX_5768F4194584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE log_entry (id BINARY(16) NOT NULL, action VARCHAR(255) DEFAULT NULL, message LONGTEXT DEFAULT NULL, ip_adress VARCHAR(16) DEFAULT NULL, created_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE product (id BINARY(16) NOT NULL, slug VARCHAR(255) DEFAULT NULL, name VARCHAR(255) DEFAULT NULL, description LONGTEXT DEFAULT NULL, current_version VARCHAR(6) DEFAULT NULL, download_url VARCHAR(255) DEFAULT NULL, price NUMERIC(10, 2) DEFAULT NULL, active TINYINT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE update_release (id BINARY(16) NOT NULL, version VARCHAR(255) DEFAULT NULL, changelog LONGTEXT NOT NULL, package_url VARCHAR(255) NOT NULL, release_date DATETIME DEFAULT NULL, stable TINYINT DEFAULT NULL, product_id BINARY(16) DEFAULT NULL, INDEX IDX_D9F61AE44584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, firstname VARCHAR(255) NOT NULL, lastname VARCHAR(255) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE activation ADD CONSTRAINT FK_1C686077460F904B FOREIGN KEY (license_id) REFERENCES license (id)');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB9395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id)');
        $this->addSql('ALTER TABLE license ADD CONSTRAINT FK_5768F4199395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id)');
        $this->addSql('ALTER TABLE license ADD CONSTRAINT FK_5768F4194584665A FOREIGN KEY (product_id) REFERENCES product (id)');
        $this->addSql('ALTER TABLE update_release ADD CONSTRAINT FK_D9F61AE44584665A FOREIGN KEY (product_id) REFERENCES product (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activation DROP FOREIGN KEY FK_1C686077460F904B');
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EB9395C3F3');
        $this->addSql('ALTER TABLE license DROP FOREIGN KEY FK_5768F4199395C3F3');
        $this->addSql('ALTER TABLE license DROP FOREIGN KEY FK_5768F4194584665A');
        $this->addSql('ALTER TABLE update_release DROP FOREIGN KEY FK_D9F61AE44584665A');
        $this->addSql('DROP TABLE activation');
        $this->addSql('DROP TABLE api_token');
        $this->addSql('DROP TABLE customer');
        $this->addSql('DROP TABLE license');
        $this->addSql('DROP TABLE log_entry');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE update_release');
        $this->addSql('DROP TABLE user');
    }
}

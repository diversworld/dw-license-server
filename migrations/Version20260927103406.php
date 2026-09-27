<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260927103406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Complete license installation binding and repair generated activation identifiers.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activation ADD tenant VARCHAR(180) DEFAULT \'\' NOT NULL, CHANGE id id INT AUTO_INCREMENT NOT NULL, CHANGE ip_adress ip_adress VARCHAR(45) DEFAULT NULL, CHANGE contao_version contao_version VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_activation_license_domain ON activation (license_id, domain)');
        $this->addSql('ALTER TABLE api_token ADD expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE license ADD mode VARCHAR(10) DEFAULT \'online\' NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04AD989D9B62 ON product (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_activation_license_domain ON activation');
        $this->addSql('ALTER TABLE activation DROP tenant, CHANGE id id INT NOT NULL, CHANGE ip_adress ip_adress VARCHAR(16) DEFAULT NULL, CHANGE contao_version contao_version VARCHAR(6) DEFAULT NULL');
        $this->addSql('ALTER TABLE api_token DROP expires_at');
        $this->addSql('ALTER TABLE license DROP mode');
        $this->addSql('DROP INDEX UNIQ_D34A04AD989D9B62 ON product');
    }
}

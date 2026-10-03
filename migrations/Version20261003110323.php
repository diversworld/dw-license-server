<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261003110323 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add declarative product entitlements and plans; snapshot existing license rights without changing them';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        if (!$schema->hasTable('license_plan')) { $this->addSql('CREATE TABLE license_plan (id BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, duration_days INT NOT NULL, max_domains INT NOT NULL, features JSON NOT NULL, quotas JSON NOT NULL, updates_allowed TINYINT DEFAULT NULL, active TINYINT NOT NULL, product_id BINARY(16) NOT NULL, INDEX IDX_57B50E6E4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4'); }
        if (!$schema->hasTable('license_plan') || !$schema->getTable('license_plan')->hasForeignKey('FK_57B50E6E4584665A')) { $this->addSql('ALTER TABLE license_plan ADD CONSTRAINT FK_57B50E6E4584665A FOREIGN KEY (product_id) REFERENCES product (id)'); }
        if (!$schema->getTable('license')->hasColumn('entitlement_snapshot')) { $this->addSql('ALTER TABLE license ADD entitlement_snapshot JSON DEFAULT NULL'); }
        if (!$schema->getTable('license')->hasColumn('quotas')) { $this->addSql('ALTER TABLE license ADD quotas JSON DEFAULT NULL'); }
        if (!$schema->getTable('license')->hasColumn('plan_snapshot')) { $this->addSql('ALTER TABLE license ADD plan_snapshot JSON DEFAULT NULL'); }
        if (!$schema->getTable('license')->hasColumn('updates_allowed')) { $this->addSql('ALTER TABLE license ADD updates_allowed TINYINT DEFAULT NULL'); }
        if (!$schema->getTable('license')->hasColumn('updates_until')) { $this->addSql('ALTER TABLE license ADD updates_until DATETIME DEFAULT NULL'); }
        if (!$schema->getTable('product')->hasColumn('allowed_features')) { $this->addSql('ALTER TABLE product ADD allowed_features JSON DEFAULT NULL'); }
        if (!$schema->getTable('product')->hasColumn('required_features')) { $this->addSql('ALTER TABLE product ADD required_features JSON DEFAULT NULL'); }
        if (!$schema->getTable('product')->hasColumn('feature_quotas')) { $this->addSql('ALTER TABLE product ADD feature_quotas JSON DEFAULT NULL'); }
        if (!$schema->getTable('product')->hasColumn('max_installations')) { $this->addSql('ALTER TABLE product ADD max_installations INT DEFAULT 10000 NOT NULL'); }
        // Read only pre-existing columns and queue backfill after guarded DDL. This is also safe in --dry-run.
        foreach ($this->connection->fetchAllAssociative('SELECT id, slug FROM product') as $product) {
            $licenses = $this->connection->fetchAllAssociative('SELECT id, features, max_domains FROM license WHERE product_id = ?', [$product['id']]);
            $required = $product['slug'] === 'contao-issue-service-bundle' ? ['sla'] : [];
            $allowed = $required; $cap = 10000;
            foreach ($licenses as $license) {
                $features = json_decode($license['features'], true, flags: JSON_THROW_ON_ERROR);
                $allowed = array_values(array_unique(array_merge($allowed, $features)));
                $cap = max($cap, (int) $license['max_domains']);
                $snapshot = ['version' => 1, 'allowedFeatures' => $features, 'requiredFeatures' => $required,
                    'maxInstallations' => max(1, (int) $license['max_domains']), 'featureQuotas' => [],
                    'grantedFeatures' => $features, 'grantedQuotas' => [], 'grantedInstallations' => (int) $license['max_domains']];
                $this->addSql('UPDATE license SET entitlement_snapshot = ? WHERE id = ? AND entitlement_snapshot IS NULL', [json_encode($snapshot, JSON_THROW_ON_ERROR), $license['id']]);
            }
            $this->addSql('UPDATE product SET allowed_features = ? WHERE id = ? AND allowed_features IS NULL', [json_encode($allowed, JSON_THROW_ON_ERROR), $product['id']]);
            $this->addSql('UPDATE product SET required_features = ? WHERE id = ? AND required_features IS NULL', [json_encode($required, JSON_THROW_ON_ERROR), $product['id']]);
            $this->addSql('UPDATE product SET feature_quotas = ? WHERE id = ? AND feature_quotas IS NULL', ['[]', $product['id']]);
            $this->addSql('UPDATE product SET max_installations = GREATEST(max_installations, ?) WHERE id = ?', [$cap, $product['id']]);
        }

    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM license_plan') > 0 || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM license WHERE plan_snapshot IS NOT NULL OR quotas IS NOT NULL OR updates_allowed IS NOT NULL') > 0, 'Preserve issued plan rights and quotas; downgrade requires a reviewed backup restore.');
        $this->addSql('ALTER TABLE license_plan DROP FOREIGN KEY FK_57B50E6E4584665A');
        $this->addSql('DROP TABLE license_plan');
        $this->addSql('ALTER TABLE license DROP entitlement_snapshot, DROP quotas, DROP plan_snapshot, DROP updates_allowed, DROP updates_until');
        $this->addSql('ALTER TABLE product DROP allowed_features, DROP required_features, DROP feature_quotas, DROP max_installations');
    }
}

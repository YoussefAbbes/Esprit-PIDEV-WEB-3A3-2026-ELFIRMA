<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename culture.parcelleId to parcelle_id and keep FK in sync.';
    }

    public function up(Schema $schema): void
    {
        // Drop FK on parcelleId if it exists.
        $this->addSql("SET @fk_name := (SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'culture' AND COLUMN_NAME = 'parcelleId' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1)");
        $this->addSql("SET @sql := IF(@fk_name IS NOT NULL, CONCAT('ALTER TABLE culture DROP FOREIGN KEY `', @fk_name, '`'), 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        // Rename column if old name exists.
        $this->addSql("SET @has_old_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'culture' AND COLUMN_NAME = 'parcelleId')");
        $this->addSql("SET @sql := IF(@has_old_col > 0, 'ALTER TABLE culture CHANGE `parcelleId` `parcelle_id` INT DEFAULT NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        // Add FK on parcelle_id if missing.
        $this->addSql("SET @has_new_fk := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'culture' AND COLUMN_NAME = 'parcelle_id' AND REFERENCED_TABLE_NAME = 'parcelle')");
        $this->addSql("SET @sql := IF(@has_new_fk = 0, 'ALTER TABLE culture ADD CONSTRAINT FK_CULTURE_PARCELLE_ID FOREIGN KEY (`parcelle_id`) REFERENCES parcelle (`id`) ON DELETE SET NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        // Drop FK on parcelle_id if it exists.
        $this->addSql("SET @fk_name := (SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'culture' AND COLUMN_NAME = 'parcelle_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1)");
        $this->addSql("SET @sql := IF(@fk_name IS NOT NULL, CONCAT('ALTER TABLE culture DROP FOREIGN KEY `', @fk_name, '`'), 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        // Rename column back if new name exists.
        $this->addSql("SET @has_new_col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'culture' AND COLUMN_NAME = 'parcelle_id')");
        $this->addSql("SET @sql := IF(@has_new_col > 0, 'ALTER TABLE culture CHANGE `parcelle_id` `parcelleId` INT DEFAULT NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        // Add FK back on parcelleId if missing.
        $this->addSql("SET @has_old_fk := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'culture' AND COLUMN_NAME = 'parcelleId' AND REFERENCED_TABLE_NAME = 'parcelle')");
        $this->addSql("SET @sql := IF(@has_old_fk = 0, 'ALTER TABLE culture ADD CONSTRAINT FK_CULTURE_PARCELLEID FOREIGN KEY (`parcelleId`) REFERENCES parcelle (`id`) ON DELETE SET NULL', 'SELECT 1')");
        $this->addSql('PREPARE stmt FROM @sql');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }
}

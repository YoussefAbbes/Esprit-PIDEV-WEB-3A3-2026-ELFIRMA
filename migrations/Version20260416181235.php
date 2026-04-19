<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260416181235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create meetings table and other schema updates';
    }

    public function up(Schema $schema): void
    {
        // Create meetings table
        $this->addSql('CREATE TABLE IF NOT EXISTS meetings (
            id INT AUTO_INCREMENT NOT NULL,
            supplier_id INT DEFAULT NULL,
            meeting_link VARCHAR(500) NOT NULL,
            meeting_datetime DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY IDX_F515EFF2693F3D3E (supplier_id),
            CONSTRAINT FK_F515EFF2693F3D3E FOREIGN KEY (supplier_id) REFERENCES fournisseurs (id_f) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commande DROP FOREIGN KEY FK_6EEAA67D50EAE44');
        $this->addSql('DROP INDEX IDX_6EEAA67D50EAE44 ON commande');
        $this->addSql('ALTER TABLE commande DROP id_utilisateur');
        $this->addSql('ALTER TABLE equipement CHANGE etat etat VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE maintenance CHANGE statut statut VARCHAR(30) NOT NULL, CHANGE priorite priorite VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE image_u image_u VARCHAR(255) DEFAULT \'default.png\'');

        // Drop meetings table
        $this->addSql('DROP TABLE IF EXISTS meetings');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415125803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE equipement CHANGE etat etat VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE maintenance ADD technicien_id INT NOT NULL, DROP technicien, CHANGE statut statut VARCHAR(255) DEFAULT NULL, CHANGE priorite priorite VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE maintenance ADD CONSTRAINT FK_2F84F8E913457256 FOREIGN KEY (technicien_id) REFERENCES utilisateur (id_u)');
        $this->addSql('CREATE INDEX IDX_2F84F8E913457256 ON maintenance (technicien_id)');
        $this->addSql('ALTER TABLE utilisateur CHANGE image_u image_u VARCHAR(255) DEFAULT \'default.JPG\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE equipement CHANGE etat etat VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE maintenance DROP FOREIGN KEY FK_2F84F8E913457256');
        $this->addSql('DROP INDEX IDX_2F84F8E913457256 ON maintenance');
        $this->addSql('ALTER TABLE maintenance ADD technicien VARCHAR(100) NOT NULL, DROP technicien_id, CHANGE statut statut VARCHAR(30) NOT NULL, CHANGE priorite priorite VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE image_u image_u VARCHAR(255) DEFAULT \'default.png\'');
    }
}

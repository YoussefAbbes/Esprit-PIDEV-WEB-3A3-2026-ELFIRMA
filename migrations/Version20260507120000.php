<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add blameable fields (updated_at, created_by, updated_by) to irrigation_event and meetings tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE irrigation_event ADD updated_at DATETIME DEFAULT NULL, ADD created_by INT DEFAULT NULL, ADD updated_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE meetings ADD updated_at DATETIME DEFAULT NULL, ADD created_by INT DEFAULT NULL, ADD updated_by INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE irrigation_event DROP updated_at, DROP created_by, DROP updated_by');
        $this->addSql('ALTER TABLE meetings DROP updated_at, DROP created_by, DROP updated_by');
    }
}

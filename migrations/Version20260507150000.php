<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename table fournisseurs to fournisseur to follow Doctrine singular naming convention.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('RENAME TABLE fournisseurs TO fournisseur');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('RENAME TABLE fournisseur TO fournisseurs');
    }
}

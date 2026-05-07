<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename table ratings to rating to follow Doctrine singular naming convention.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('RENAME TABLE ratings TO rating');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('RENAME TABLE rating TO ratings');
    }
}

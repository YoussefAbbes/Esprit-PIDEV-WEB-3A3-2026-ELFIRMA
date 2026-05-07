<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename table meetings to meeting to follow Doctrine singular naming convention.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('RENAME TABLE meetings TO meeting');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('RENAME TABLE meeting TO meetings');
    }
}

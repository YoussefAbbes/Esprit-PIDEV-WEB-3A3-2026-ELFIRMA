<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create spin_reward and user_spin tables for the login spin-the-wheel feature';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE spin_reward (
                id INT AUTO_INCREMENT NOT NULL,
                label VARCHAR(100) NOT NULL,
                type VARCHAR(30) NOT NULL,
                code_prefix VARCHAR(30) DEFAULT NULL,
                discount_type VARCHAR(20) NOT NULL,
                discount_value DOUBLE PRECISION NOT NULL,
                color VARCHAR(7) NOT NULL,
                probability_weight INT NOT NULL,
                is_active TINYINT(1) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");

        $this->addSql("
            CREATE TABLE user_spin (
                id INT AUTO_INCREMENT NOT NULL,
                spin_reward_id INT DEFAULT NULL,
                utilisateur_id INT NOT NULL,
                generated_code VARCHAR(60) DEFAULT NULL,
                spun_at DATETIME NOT NULL,
                is_used TINYINT(1) NOT NULL,
                used_at DATETIME DEFAULT NULL,
                INDEX IDX_USER_SPIN_REWARD (spin_reward_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_USER_SPIN_REWARD FOREIGN KEY (spin_reward_id)
                    REFERENCES spin_reward (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_spin');
        $this->addSql('DROP TABLE spin_reward');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajouter la table des articles Wikipedia pour le système d'articles aléatoires
 */
final class Version20250903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wikipedia_articles table for random article selection';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE wikipedia_articles (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            url VARCHAR(500) NOT NULL,
            category VARCHAR(100) DEFAULT NULL,
            difficulty VARCHAR(20) DEFAULT "moyen",
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)",
            INDEX idx_category (category),
            INDEX idx_difficulty (difficulty),
            INDEX idx_active (is_active),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wikipedia_articles');
    }
}

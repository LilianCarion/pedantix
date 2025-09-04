<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration initiale pour Pedantix - Crée toutes les tables
 */
final class Version20250903140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migration initiale Pedantix : création des tables wikipedia_article, room et game_session';
    }

    public function up(Schema $schema): void
    {
        // Vérifier si les tables existent déjà avant de les créer

        // Table wikipedia_article
        if (!$schema->hasTable('wikipedia_article')) {
            $this->addSql('CREATE TABLE wikipedia_article (
                id INT AUTO_INCREMENT NOT NULL,
                title VARCHAR(500) NOT NULL,
                url VARCHAR(1000) NOT NULL,
                category VARCHAR(100) DEFAULT NULL,
                difficulty VARCHAR(50) DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX UNIQ_wikipedia_url (url),
                INDEX IDX_wikipedia_category (category),
                INDEX IDX_wikipedia_difficulty (difficulty),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        // Table room
        if (!$schema->hasTable('room')) {
            $this->addSql('CREATE TABLE room (
                id INT AUTO_INCREMENT NOT NULL,
                code VARCHAR(6) NOT NULL,
                title VARCHAR(500) NOT NULL,
                content LONGTEXT NOT NULL,
                url VARCHAR(1000) NOT NULL,
                words_to_find JSON NOT NULL,
                hints JSON NOT NULL,
                game_mode VARCHAR(50) NOT NULL DEFAULT \'competition\',
                global_found_words JSON NOT NULL,
                is_game_completed TINYINT(1) NOT NULL DEFAULT 0,
                completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX UNIQ_room_code (code),
                INDEX IDX_room_game_mode (game_mode),
                INDEX IDX_room_completed (is_game_completed),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        // Table game_session
        if (!$schema->hasTable('game_session')) {
            $this->addSql('CREATE TABLE game_session (
                id INT AUTO_INCREMENT NOT NULL,
                room_id INT NOT NULL,
                player_name VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                last_activity DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                found_words JSON NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                is_completed TINYINT(1) NOT NULL DEFAULT 0,
                completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                score INT NOT NULL DEFAULT 0,
                INDEX IDX_game_session_room (room_id),
                INDEX IDX_game_session_player (player_name, ip_address),
                INDEX IDX_game_session_completed (is_completed),
                INDEX IDX_game_session_score (score),
                INDEX IDX_game_session_activity (last_activity),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

            // Contrainte de clé étrangère seulement si les deux tables existent
            if ($schema->hasTable('room')) {
                $this->addSql('ALTER TABLE game_session ADD CONSTRAINT FK_game_session_room FOREIGN KEY (room_id) REFERENCES room (id)');
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Supprimer les contraintes de clé étrangère d'abord
        $this->addSql('ALTER TABLE game_session DROP FOREIGN KEY FK_game_session_room');

        // Supprimer les tables
        $this->addSql('DROP TABLE game_session');
        $this->addSql('DROP TABLE room');
        $this->addSql('DROP TABLE wikipedia_article');
    }
}

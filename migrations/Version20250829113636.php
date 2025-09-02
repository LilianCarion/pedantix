<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250829113636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE game_session (id INT AUTO_INCREMENT NOT NULL, room_id INT NOT NULL, player_name VARCHAR(255) NOT NULL, ip_address VARCHAR(45) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_activity DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', found_words JSON NOT NULL COMMENT \'(DC2Type:json)\', attempts INT NOT NULL, is_completed TINYINT(1) NOT NULL, completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', score INT NOT NULL, INDEX IDX_4586AAFB54177093 (room_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE room (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(10) NOT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, url VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_active TINYINT(1) NOT NULL, words_to_find JSON NOT NULL COMMENT \'(DC2Type:json)\', hints JSON NOT NULL COMMENT \'(DC2Type:json)\', UNIQUE INDEX UNIQ_729F519B77153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE game_session ADD CONSTRAINT FK_4586AAFB54177093 FOREIGN KEY (room_id) REFERENCES room (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_session DROP FOREIGN KEY FK_4586AAFB54177093');
        $this->addSql('DROP TABLE game_session');
        $this->addSql('DROP TABLE room');
    }
}

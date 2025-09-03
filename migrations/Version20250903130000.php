<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajouter les champs d'endgame à la table Room
 */
final class Version20250903130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add endgame fields to room table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room ADD is_game_completed TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE room ADD completed_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)"');
        $this->addSql('ALTER TABLE room ADD winner_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room DROP is_game_completed');
        $this->addSql('ALTER TABLE room DROP completed_at');
        $this->addSql('ALTER TABLE room DROP winner_id');
    }
}

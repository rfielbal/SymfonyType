<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des utilisateurs et des historiques de mots de passe et de connexions';
    }

    public function isTransactional(): bool
    {
        // MySQL/MariaDB valident implicitement les changements de structure (DDL).
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');

        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, nom VARCHAR(100) NOT NULL, prenom VARCHAR(100) NOT NULL, adr_rue VARCHAR(255) NOT NULL, ville VARCHAR(100) NOT NULL, cp VARCHAR(10) NOT NULL, date_inscription DATETIME NOT NULL, est_actif TINYINT NOT NULL, UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE historique_mdp (id INT AUTO_INCREMENT NOT NULL, date_remplacement DATETIME NOT NULL, ancien_mdp_hache VARCHAR(255) NOT NULL, user_id INT NOT NULL, INDEX IDX_9BC62EDA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE historique_connexion (id INT AUTO_INCREMENT NOT NULL, date_heure DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_C018B2D4A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE historique_mdp ADD CONSTRAINT FK_9BC62EDA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE historique_connexion ADD CONSTRAINT FK_C018B2D4A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');

        $this->addSql('DROP TABLE historique_connexion');
        $this->addSql('DROP TABLE historique_mdp');
        $this->addSql('DROP TABLE user');
    }
}

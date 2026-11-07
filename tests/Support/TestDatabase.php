<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/** Nettoyage réservé à MariaDB et à la base dédiée aux tests. */
final class TestDatabase
{
    public function __construct(
        private Connection $connection,
        private string $environment,
    ) {
    }

    /** Lecture seule : à appeler aussi avant les migrations de test. */
    public function assertSafe(): void
    {
        // Refuser la configuration dangereuse avant même la première requête.
        $params = $this->connection->getParams();
        if ($this->environment !== 'test'
            || ($params['dbname'] ?? null) !== 'dbSymfonyType_test'
            || ($params['driver'] ?? null) !== 'pdo_mysql') {
            throw new \RuntimeException('Nettoyage refusé : environnement test, pilote pdo_mysql et base dbSymfonyType_test obligatoires.');
        }

        // Vérifier aussi la base réellement sélectionnée et le moteur du serveur.
        $actual = $this->connection->fetchAssociative('SELECT DATABASE() AS db, VERSION() AS version');
        if (!$actual || $actual['db'] !== 'dbSymfonyType_test'
            || stripos($actual['version'], 'MariaDB') === false) {
            throw new \RuntimeException('Nettoyage refusé : la connexion réelle doit utiliser MariaDB / dbSymfonyType_test.');
        }
    }

    public function reset(): void
    {
        $this->assertSafe();

        // Enfants avant parent : les contraintes restent actives.
        // Pas de DROP, de TRUNCATE ni de modification des migrations.
        $this->connection->transactional(function (Connection $connection): void {
            $connection->executeStatement('DELETE FROM `historique_connexion`');
            $connection->executeStatement('DELETE FROM `historique_mdp`');
            $connection->executeStatement('DELETE FROM `user`');
        });
    }

    public function close(): void
    {
        $this->connection->close();
    }
}

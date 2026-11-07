<?php

namespace App\Tests\Unit;

use App\Tests\Support\TestDatabase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ces tests de refus n'ouvrent aucune connexion et ne touchent aucune base. */
final class TestDatabaseTest extends TestCase
{
    #[DataProvider('unsafeConfigurations')]
    public function testConfigurationDangereuseRefuseeAvantTouteRequete(string $environment, array $params): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn($params);
        $connection->expects(self::never())->method('fetchAssociative');
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('transactional');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nettoyage refusé');
        (new TestDatabase($connection, $environment))->reset();
    }

    public static function unsafeConfigurations(): iterable
    {
        yield 'environnement développement' => ['dev', ['driver' => 'pdo_mysql', 'dbname' => 'dbSymfonyType_test']];
        yield 'environnement production' => ['prod', ['driver' => 'pdo_mysql', 'dbname' => 'dbSymfonyType_test']];
        yield 'base de développement' => ['test', ['driver' => 'pdo_mysql', 'dbname' => 'dbSymfonyType']];
        yield 'suffixe ajouté deux fois' => ['test', ['driver' => 'pdo_mysql', 'dbname' => 'dbSymfonyType_test_test']];
        yield 'nom de base absent' => ['test', ['driver' => 'pdo_mysql']];
        yield 'pilote non autorisé' => ['test', ['driver' => 'pdo_pgsql', 'dbname' => 'dbSymfonyType_test']];
    }

    #[DataProvider('unsafeServers')]
    public function testConnexionReelleInattendueRefuseeAvantTouteEcriture(array|false $actual): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getParams')->willReturn(['driver' => 'pdo_mysql', 'dbname' => 'dbSymfonyType_test']);
        $connection->expects(self::once())->method('fetchAssociative')
            ->with('SELECT DATABASE() AS db, VERSION() AS version')->willReturn($actual);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('transactional');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connexion réelle');
        (new TestDatabase($connection, 'test'))->reset();
    }

    public static function unsafeServers(): iterable
    {
        yield 'base réelle différente' => [['db' => 'dbSymfonyType', 'version' => '11.4.5-MariaDB']];
        yield 'serveur MySQL au lieu de MariaDB' => [['db' => 'dbSymfonyType_test', 'version' => '8.4.0']];
        yield 'réponse absente' => [false];
    }
}

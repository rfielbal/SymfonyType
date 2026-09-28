<?php

namespace App\Tests\Integration;

use App\Entity\HistoriqueConnexion;
use App\Entity\HistoriqueMdp;
use App\Entity\User;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/** Tests du modèle dans SQLite en mémoire, sans accès aux bases des nuages. */
final class ModeleDonneesTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->em = new EntityManager($connection, $config);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        $this->em->close();
        $this->em->getConnection()->close();
    }

    private function creerUtilisateur(): User
    {
        return (new User())
            ->setEmail('test@example.test')
            ->setNom('Exemple')
            ->setPrenom('Test')
            ->setAdrRue('1 rue du Test')
            ->setVille('Ville de test')
            ->setCp('01000')
            ->setPassword(password_hash('MotDePasseDeTest!42', PASSWORD_BCRYPT));
    }

    public function testHistoriquesPersistesPuisSupprimesAvecUtilisateur(): void
    {
        $user = $this->creerUtilisateur();
        $ancien = (new HistoriqueMdp())->setUser($user)->setAncienMdpHache($user->getPassword());
        $connexion = (new HistoriqueConnexion())->setUser($user);
        foreach ([$user, $ancien, $connexion] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $id = $user->getId();
        $this->em->clear();

        $user = $this->em->find(User::class, $id);
        self::assertSame('01000', $user->getCp());
        self::assertTrue($user->isActif());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_mdp WHERE user_id = ?', [$id]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion WHERE user_id = ?', [$id]));

        $user->setEstActif(false);
        $this->em->flush();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_mdp'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));

        $this->em->remove($user);
        $this->em->flush();
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_mdp'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
    }

    public function testEmailUniqueEnBase(): void
    {
        $this->em->persist($this->creerUtilisateur());
        $this->em->flush();
        $this->em->persist($this->creerUtilisateur());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testConnexionSansUtilisateurRefusee(): void
    {
        $this->em->persist(new HistoriqueConnexion());
        $this->expectException(NotNullConstraintViolationException::class);
        $this->em->flush();
    }
}

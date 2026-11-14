<?php

// CYBER 2 — intégration Doctrine/MariaDB : aucune page HTTP n’est appelée ici.
// Vérifier les relations, la suppression des historiques et les contraintes de la base dédiée.

namespace App\Tests\Integration;

use App\Entity\HistoriqueConnexion;
use App\Entity\HistoriqueMdp;
use App\Entity\User;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\TestDatabase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Tests du modèle sur la base MariaDB dédiée dbSymfonyType_test. */
final class ModeleDonneesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ?TestDatabase $database = null;

    // Avant chaque test : démarrer Symfony et nettoyer uniquement la base MariaDB autorisée.
    protected function setUp(): void
    {
        static::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $database = new TestDatabase($this->em->getConnection(), static::$kernel->getEnvironment());
        $database->reset();
        $this->database = $database;
    }

    // Après chaque test : nettoyer les données et fermer la connexion, même si une assertion échoue.
    protected function tearDown(): void
    {
        try {
            $this->database?->reset();
        } finally {
            $this->database?->close();
            $this->database = null;
            parent::tearDown();
        }
    }

    // Préparer une entité fictive ; elle ne sera enregistrée qu’après persist() et flush().
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

    // Vérifier que suspension conserve les historiques et que suppression du compte les supprime.
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

    // Deux comptes avec le même email : la contrainte SQL doit provoquer l’exception attendue.
    public function testEmailUniqueEnBase(): void
    {
        $this->em->persist($this->creerUtilisateur());
        $this->em->flush();
        $this->em->persist($this->creerUtilisateur());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    // Une trace de connexion sans compte associé doit être refusée par la base.
    public function testConnexionSansUtilisateurRefusee(): void
    {
        $this->em->persist(new HistoriqueConnexion());
        $this->expectException(NotNullConstraintViolationException::class);
        $this->em->flush();
    }
}

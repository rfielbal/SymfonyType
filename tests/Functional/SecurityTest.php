<?php

// CYBER 2 — tests fonctionnels conservés pour éviter de perdre les protections déjà vérifiées.
// À repérer : inscription/connexion, historique, suspension, mots de passe, force brute, administration et CSRF.
// Cyber3SecurityTest.php complète ces parcours ; il ne remplace pas cette suite.
// login() soumet les identifiants ; loginUser() prépare directement une session pour tester les droits.

namespace App\Tests\Functional;

use App\Entity\HistoriqueMdp;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\TestDatabase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Tests sur la base MariaDB dédiée dbSymfonyType_test, déjà migrée. */
final class SecurityTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?TestDatabase $database = null;
    private const PASSWORD = 'Orbite!Nuage-7319';
    private const NEW_PASSWORD = 'Rivage!Comete-8264';

    // Avant CHAQUE cas : créer le client Symfony, vider la base dédiée et réinitialiser la limite de connexion.
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $database = new TestDatabase($this->em()->getConnection(), static::$kernel->getEnvironment());
        $database->reset();
        $this->database = $database;
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    // Après CHAQUE cas, même en échec : nettoyer les données fictives et fermer la connexion.
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

    // Outil Doctrine pour enregistrer ou relire les données ; ce n’est pas un test.
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    // Fixture : créer un compte fictif ; admin et active choisissent son rôle et son état de départ.
    private function user(string $email = 'user@example.test', bool $admin = false, bool $active = true): User
    {
        $user = (new User())->setEmail($email)->setNom('Exemple')->setPrenom('Test')
            ->setAdrRue('1 rue du Test')->setVille('Ville')->setCp('01000')
            ->setRoles($admin ? ['ROLE_ADMIN'] : ['ROLE_USER'])->setEstActif($active);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $this->em()->persist($user);
        $this->em()->flush();
        return $user;
    }

    // Soumettre les identifiants au vrai formulaire de connexion.
    private function login(string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'user@example.test', 'password' => $password]);
    }

    // Envoyer l’ancien mot de passe et la confirmation du nouveau via le formulaire.
    private function changePassword(string $old, string $new): void
    {
        $this->client->request('GET', '/profil');
        $this->client->submitForm('Mettre à jour', [
            'change_password[oldPassword]' => $old,
            'change_password[newPassword][first]' => $new,
            'change_password[newPassword][second]' => $new,
        ]);
    }

    // Cyber 2 — inscription, hachage vérifiable, rôle standard, connexion automatique et administration interdite.
    public function testInscriptionValideHachageEtConnexionAutomatique(): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Créer mon compte', [
            'registration[email]' => 'nouveau@example.test', 'registration[nom]' => 'Nom',
            'registration[prenom]' => 'Prénom', 'registration[adrRue]' => '1 rue du Test',
            'registration[ville]' => 'Ville', 'registration[cp]' => '01000',
            'registration[plainPassword][first]' => self::PASSWORD,
            'registration[plainPassword][second]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/profil');
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'nouveau@example.test']);
        self::assertNotSame(self::PASSWORD, $user->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD));
        self::assertSame(['ROLE_USER'], $user->getRoles());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    // Cyber 2 — refus global d’une requête invalide ; Cyber 3 teste ensuite chaque champ séparément.
    public function testInscriptionInvalideSansInsertion(): void
    {
        $this->client->request('POST', '/inscription', ['registration' => [
            'email' => 'invalide', 'nom' => '', 'prenom' => '', 'adrRue' => '', 'ville' => '', 'cp' => '',
            'plainPassword' => ['first' => 'faible', 'second' => 'different'],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
    }

    // Cyber 2 — une connexion crée une trace ; consulter le profil n’en crée pas une deuxième.
    public function testConnexionEnregistreHistoriqueEtProtegeAdministration(): void
    {
        $this->user();
        $this->login();
        self::assertResponseRedirects('/profil');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
        $this->client->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    // Cyber 2 — même avec le bon mot de passe, un compte suspendu ne doit pas ouvrir de session.
    public function testCompteSuspenduNePeutPasSeConnecter(): void
    {
        $this->user(active: false);
        $this->login();
        self::assertResponseRedirects('/connexion');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
    }

    // Cyber 2 — simuler une suspension en base puis vérifier qu’une session déjà ouverte est rejetée.
    public function testSuspensionInvalideSessionDejaOuverte(): void
    {
        $id = $this->user()->getId();
        $this->login();
        $this->em()->getConnection()->executeStatement('UPDATE user SET est_actif = 0 WHERE id = ?', [$id]);
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('http://localhost/connexion');
    }

    // Cyber 2 — préparer une ancienne archive, refuser sa réutilisation puis vérifier un changement valide.
    public function testAncienMotDePasseRefuseEtChangementValideArchive(): void
    {
        $user = $this->user();
        $oldPassword = 'Ancienne!Planete-6952';
        $oldHash = static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $oldPassword);
        $this->em()->persist((new HistoriqueMdp())->setUser($user)->setAncienMdpHache($oldHash));
        $this->em()->flush();
        $this->login();
        $this->changePassword(self::PASSWORD, $oldPassword);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ce mot de passe a déjà été utilisé');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_mdp'));
        $this->changePassword(self::PASSWORD, self::NEW_PASSWORD);
        self::assertResponseRedirects('/profil');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_mdp'));
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'user@example.test']);
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::NEW_PASSWORD));
    }

    // Cyber 2 — refuser le mot de passe déjà courant et un mot de passe actuel incorrect, sans archive ajoutée.
    public function testMotDePasseActuelEtMauvaiseConfirmationIdentiteRefuses(): void
    {
        $this->user();
        $this->login();
        $this->changePassword(self::PASSWORD, self::PASSWORD);
        self::assertSelectorTextContains('main', 'Ce mot de passe a déjà été utilisé');
        $this->changePassword('incorrect', self::NEW_PASSWORD);
        self::assertSelectorTextContains('main', 'Votre mot de passe actuel est incorrect');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_mdp'));
    }

    // Cyber 2 — protection contre la force brute : après trois échecs, même le bon mot de passe est temporairement refusé.
    public function testForceBruteBloqueAussiLeBonMotDePasseApresTroisEchecs(): void
    {
        // 1. PRÉPARER : un utilisateur valide ; setUp() a remis le compteur de tentatives à zéro.
        $this->user();
        // 2. AGIR : envoyer trois mauvais mots de passe, puis essayer le bon.
        for ($i = 0; $i < 3; ++$i) {
            $this->login('incorrect');
        }
        $this->login();
        // 3. VÉRIFIER : aucune connexion enregistrée et message de limitation des tentatives.
        self::assertResponseRedirects('/connexion');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'Too many failed login attempts');
    }

    // Cyber 2 — jeton CSRF faux refusé ; formulaires valides de suspension et suppression acceptés.
    public function testAdminCsrfSuppressionEtSuspension(): void
    {
        $admin = $this->user(admin: true);
        $id = $this->user('cible@example.test')->getId();
        $this->client->loginUser($admin);
        $this->client->request('POST', "/admin/utilisateur/$id/supprimer", ['_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->filter('form[action="/admin/utilisateur/'.$id.'/suspendre"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT est_actif FROM user WHERE id = ?', [$id]));
        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler->filter('form[action="/admin/utilisateur/'.$id.'/supprimer"]')->form());
        self::assertResponseRedirects('/admin');
        self::assertNull($this->em()->find(User::class, $id));
    }

    // Cyber 2 — créer/modifier un compte et ouvrir son historique vide ; ne vérifie pas l’affichage de plusieurs dates.
    public function testAdminCreationModificationEtHistorique(): void
    {
        $this->client->loginUser($this->user(admin: true));
        $this->client->request('GET', '/admin/utilisateur/nouveau');
        $this->client->submitForm('Enregistrer', [
            'user_admin[email]' => 'cree@example.test', 'user_admin[nom]' => 'Nom',
            'user_admin[prenom]' => 'Prénom', 'user_admin[adrRue]' => '1 rue du Test',
            'user_admin[ville]' => 'Ville', 'user_admin[cp]' => '01000',
            'user_admin[plainPassword]' => self::PASSWORD,
        ]);
        self::assertResponseRedirects('/admin');
        $id = $this->em()->getRepository(User::class)->findOneBy(['email' => 'cree@example.test'])->getId();
        $this->client->request('GET', "/admin/utilisateur/$id/modifier");
        $this->client->submitForm('Enregistrer', ['user_admin[nom]' => 'Modifié']);
        self::assertResponseRedirects('/admin');
        self::assertSame('Modifié', $this->em()->getConnection()->fetchOne('SELECT nom FROM user WHERE id = ?', [$id]));
        $this->client->request('GET', "/admin/utilisateur/$id/connexions");
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucune connexion enregistrée');
    }

    // Cyber 2 — empêcher l’administrateur de suspendre son propre compte par les deux formulaires.
    public function testAdminNePeutPasSeSuspendre(): void
    {
        $admin = $this->user(admin: true);
        $id = $admin->getId();
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin');
        $this->client->submit($crawler->filter('form[action="/admin/utilisateur/'.$id.'/suspendre"]')->form());
        self::assertResponseRedirects('/admin');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT est_actif FROM user WHERE id = ?', [$id]));
        $this->client->request('GET', "/admin/utilisateur/$id/modifier");
        $this->client->submitForm('Enregistrer', ['user_admin[estActif]' => false, 'user_admin[roles]' => ['ROLE_USER']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Vous ne pouvez pas suspendre votre propre compte');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT est_actif FROM user WHERE id = ?', [$id]));
    }

    // Cyber 2 — simuler un retrait de rôle en base : l’ancienne session doit être invalidée.
    public function testRetraitRoleAdminInvalideSession(): void
    {
        $admin = $this->user(admin: true);
        $this->login();
        $this->em()->getConnection()->executeStatement('UPDATE user SET roles = ? WHERE id = ?', ['["ROLE_USER"]', $admin->getId()]);
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('http://localhost/connexion');
    }

    // Cyber 2 — sans jeton, la déconnexion est refusée ; le lien valide termine la session.
    public function testDeconnexionAvecJetonEtRefusSansJeton(): void
    {
        $this->user();
        $this->login();
        $this->client->request('GET', '/deconnexion');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        $this->client->clickLink('Déconnexion');
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('http://localhost/connexion');
    }
}

<?php

namespace App\Tests\Functional;

use App\Entity\HistoriqueMdp;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Base SQLite temporaire : aucun compte réel ni base du nuage n'est modifié. */
final class SecurityTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $databaseFile;
    private const PASSWORD = 'Orbite!Nuage-7319';
    private const NEW_PASSWORD = 'Rivage!Comete-8264';

    protected function setUp(): void
    {
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'symfonytype-test-');
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///'.$this->databaseFile;
        $this->client = static::createClient();
        $em = $this->em();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unlink($this->databaseFile);
        unset($_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

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

    private function login(string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => 'user@example.test', 'password' => $password]);
    }

    private function changePassword(string $old, string $new): void
    {
        $this->client->request('GET', '/profil');
        $this->client->submitForm('Mettre à jour', [
            'change_password[oldPassword]' => $old,
            'change_password[newPassword][first]' => $new,
            'change_password[newPassword][second]' => $new,
        ]);
    }

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

    public function testInscriptionInvalideSansInsertion(): void
    {
        $this->client->request('POST', '/inscription', ['registration' => [
            'email' => 'invalide', 'nom' => '', 'prenom' => '', 'adrRue' => '', 'ville' => '', 'cp' => '',
            'plainPassword' => ['first' => 'faible', 'second' => 'different'],
        ]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
    }

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

    public function testCompteSuspenduNePeutPasSeConnecter(): void
    {
        $this->user(active: false);
        $this->login();
        self::assertResponseRedirects('/connexion');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
    }

    public function testSuspensionInvalideSessionDejaOuverte(): void
    {
        $id = $this->user()->getId();
        $this->login();
        $this->em()->getConnection()->executeStatement('UPDATE user SET est_actif = 0 WHERE id = ?', [$id]);
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('http://localhost/connexion');
    }

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

    public function testForceBruteBloqueAussiLeBonMotDePasseApresTroisEchecs(): void
    {
        $this->user();
        for ($i = 0; $i < 3; ++$i) {
            $this->login('incorrect');
        }
        $this->login();
        self::assertResponseRedirects('/connexion');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', 'Too many failed login attempts');
    }

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

    public function testRetraitRoleAdminInvalideSession(): void
    {
        $admin = $this->user(admin: true);
        $this->login();
        $this->em()->getConnection()->executeStatement('UPDATE user SET roles = ? WHERE id = ?', ['["ROLE_USER"]', $admin->getId()]);
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('http://localhost/connexion');
    }

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

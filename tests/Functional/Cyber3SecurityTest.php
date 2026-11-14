<?php

// CYBER 3 — 01 à 04 : connexion/session ; 05 à 06 : administration ; 09 à 12 : inscription ; 13 à 15 : mots de passe/compte.
// Les scénarios 07 et 08 sont dans ProfileSecurityTest.php ; les anciens tests restent dans SecurityTest.php.
// Lire un cas : données de départ, requête ou formulaire envoyé, puis assertions sur HTTP et la base.
// HTTP : 200 = accès réussi ; 302 = redirection ; 403 = accès interdit ; 422 = formulaire refusé.
// Les constantes ci-dessous sont des données fictives de test, jamais des identifiants du serveur.

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\TestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cyber 3, scénarios 1-6 et 9-15. Les scénarios 7-8 sont dans ProfileSecurityTest.
 * Chaque situation part de ses propres données dans dbSymfonyType_test (MariaDB).
 * Les tests Cyber 2 de SecurityTest restent exécutés avec cette suite.
 */
final class Cyber3SecurityTest extends WebTestCase
{
    private const ALICE = 'alice@example.test';
    private const BOB = 'bob@example.test';
    private const ADMIN = 'admin@example.test';
    private const PASSWORD_A = 'Orbite!Nuage-7319';
    private const PASSWORD_B = 'Rivage!Comete-8264';
    private const PASSWORD_C = 'Lumiere!Saturne-9407';
    private const COMPROMISED_PASSWORD = 'Fuite!Comete-7392';

    private KernelBrowser $client;
    private ?TestDatabase $database = null;

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

    // Fixture : créer un compte connu en base ; persist le prépare, flush l’enregistre. Ne teste pas l’inscription.
    private function user(string $email = self::ALICE, string $password = self::PASSWORD_A, bool $admin = false): User
    {
        $user = (new User())->setEmail($email)->setNom('Exemple')->setPrenom('Alice')
            ->setAdrRue('1 rue du Test')->setVille('Arras')->setCp('62000')
            ->setRoles($admin ? ['ROLE_ADMIN'] : ['ROLE_USER'])->setEstActif(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    // Soumettre le vrai formulaire de connexion : ici le mot de passe est réellement vérifié.
    private function login(string $email = self::ALICE, string $password = self::PASSWORD_A): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['email' => $email, 'password' => $password]);
    }

    // Utiliser le lien de déconnexion fourni par Symfony, avec son jeton CSRF.
    private function logout(): void
    {
        $this->client->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        $this->client->clickLink('Déconnexion');
        self::assertResponseRedirects('/');
    }

    // Trois preuves du refus : retour à la connexion, message d’erreur et profil toujours inaccessible.
    private function assertLoginRefused(): void
    {
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash.error');
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('http://localhost/connexion');
    }

    /** Valeurs valides par défaut : un seul champ est altéré dans chaque cas négatif. */
    // Envoyer une inscription valide par défaut ; $changes remplace uniquement les champs du cas testé.
    private function register(array $changes = []): void
    {
        $this->client->request('GET', '/inscription');
        $this->client->submitForm('Créer mon compte', array_replace([
            'registration[email]' => self::ALICE,
            'registration[nom]' => 'Exemple',
            'registration[prenom]' => 'Alice',
            'registration[adrRue]' => '1 rue du Test',
            'registration[ville]' => 'Arras',
            'registration[cp]' => '62000',
            'registration[plainPassword][first]' => self::PASSWORD_A,
            'registration[plainPassword][second]' => self::PASSWORD_A,
        ], $changes));
    }

    // Vérifier le refus 422, l’erreur sur le BON champ et l’absence de compte/connexion enregistrés.
    private function assertRegistrationRefused(string $field): void
    {
        self::assertResponseStatusCodeSame(422);
        // L'erreur doit concerner le champ testé, pas seulement un jeton CSRF invalide.
        self::assertGreaterThan(0, $this->client->getCrawler()->filterXPath(
            '//input[@id="registration_'.$field.'"]/parent::*//li'
        )->count(), 'Une erreur de validation est attendue pour '.$field);
        self::assertSame(0, $this->em()->getRepository(User::class)->count([]));
        self::assertSame(0, $this->historyCount());
    }

    // Envoyer le mot de passe actuel et deux saisies identiques du nouveau, via le formulaire du profil.
    private function changePassword(string $old, string $new): void
    {
        $this->client->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Mettre à jour', [
            'change_password[oldPassword]' => $old,
            'change_password[newPassword][first]' => $new,
            'change_password[newPassword][second]' => $new,
        ]);
    }

    // Nombre de connexions enregistrées ; la base est propre au début de chaque test.
    private function historyCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion');
    }

    // Nombre d’anciens mots de passe archivés sous forme de hachages.
    private function passwordHistoryCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_mdp');
    }

    // Relire le hachage actuel d’Alice : comparer avant/après révèle une modification réelle.
    private function storedPassword(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT password FROM user WHERE email = ?', [self::ALICE]);
    }

    // Depuis /admin, soumettre le formulaire de suspension/réactivation avec son jeton CSRF.
    private function toggleUser(int $id): void
    {
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action="/admin/utilisateur/'.$id.'/suspendre"]')->form());
        self::assertResponseRedirects('/admin');
    }

    #[DataProvider('protectedPages')]
    // Scénario 01 — visiteur sans session : espace personnel et URL directe de modification refusés.
    public function test01AccesAnonymeEtUrlDirecteProtegeeRefuses(bool $edition): void
    {
        $id = $this->user()->getId();
        $this->client->request('GET', $edition ? '/profil/'.$id.'/modifier' : '/profil');
        self::assertResponseRedirects('http://localhost/connexion');
        self::assertSame(0, $this->historyCount());
    }

    // DataProvider : chaque yield fournit les arguments d’une exécution indépendante du test au-dessus.
    public static function protectedPages(): iterable
    {
        yield 'espace personnel sans connexion' => [false];
        yield 'URL de modification saisie directement sans connexion' => [true];
    }

    // Scénarios 01 et 02 — connexion valide puis deux pages protégées, avec une seule authentification.
    public function test02ConnexionValideEtSessionConserveeSurDeuxPagesProtegees(): void
    {
        $id = $this->user()->getId();
        $this->login();
        self::assertResponseRedirects('/profil');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful(); // Scénario 1 : Alice connectée accède à son espace.
        self::assertSelectorTextContains('main', self::ALICE);
        $this->client->request('GET', '/profil/'.$id.'/modifier');
        self::assertResponseIsSuccessful();
        self::assertSame(self::ALICE, static::getContainer()->get('security.token_storage')->getToken()->getUser()->getUserIdentifier());
        self::assertSame(1, $this->historyCount()); // Une seule authentification, malgré plusieurs pages.
    }

    #[DataProvider('incorrectCredentials')]
    // Scénario 03 — chaque couple identifiant/mot de passe incorrect doit être refusé.
    public function test03IdentifiantsIncorrectsRefuses(string $email, string $password): void
    {
        // 1. PRÉPARER : Alice et Bob ont des mots de passe différents et connus.
        $this->user();
        $this->user(self::BOB, self::PASSWORD_B);
        // 2. AGIR : essayer le couple fourni par le DataProvider.
        $this->login($email, $password);
        // 3. VÉRIFIER : refus d’accès et aucune connexion réussie enregistrée.
        $this->assertLoginRefused();
        self::assertSame(0, $this->historyCount());
    }

    // Trois cas séparés : mauvais mot de passe, mot de passe de Bob avec Alice, compte inconnu.
    public static function incorrectCredentials(): iterable
    {
        yield 'mot de passe incorrect pour Alice' => [self::ALICE, 'Incorrect!9281'];
        yield 'mot de passe de Bob avec identifiant Alice' => [self::ALICE, self::PASSWORD_B];
        yield 'identifiant inexistant' => ['inconnu@example.test', self::PASSWORD_A];
    }

    // Scénario 04 — après déconnexion, rejouer l’ancien cookie ne doit pas restaurer la session.
    public function test04DeconnexionInvalideAussiAncienCookieDeSession(): void
    {
        $this->user();
        $this->login();
        $this->client->request('GET', '/profil');
        self::assertResponseIsSuccessful();
        $sessionName = $this->client->getRequest()->getSession()->getName();
        $cookie = $this->client->getCookieJar()->get($sessionName);
        self::assertNotNull($cookie, 'Le cookie authentifié doit exister avant sa capture.');
        // Garder une copie du cookie AVANT que la déconnexion ne le supprime ou le modifie.
        $oldCookie = clone $cookie;
        self::assertNotSame('', $oldCookie->getValue());

        $this->client->clickLink('Déconnexion');
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('http://localhost/connexion');

        // Rejouer réellement la valeur capturée AVANT la déconnexion.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set($oldCookie);
        $this->client->request('GET', '/profil');
        self::assertResponseRedirects('http://localhost/connexion');
        self::assertSame(1, $this->historyCount());
    }

    #[DataProvider('administrationAccess')]
    // Scénario 05 — comparer administrateur, utilisateur standard et visiteur sur la même page /admin.
    public function test05AccesAdministrationSelonAuthentificationEtRole(?bool $admin, int $status): void
    {
        if ($admin !== null) {
            $this->client->loginUser($this->user(admin: $admin));
        }
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame($status);
        if ($admin === null) {
            self::assertResponseRedirects('http://localhost/connexion');
        } elseif ($admin) {
            self::assertSelectorTextContains('h1', 'Gestion des comptes');
        } else {
            // Répéter l'accès direct sans passer par un bouton de navigation.
            $this->client->request('GET', 'http://localhost/admin');
            self::assertResponseStatusCodeSame(403);
        }
    }

    // Arguments fournis au test : rôle (true/false/null), puis code HTTP attendu (200/403/302).
    public static function administrationAccess(): iterable
    {
        yield 'administrateur autorisé' => [true, 200];
        yield 'Alice ROLE_USER refusée, y compris URL directe' => [false, 403];
        yield 'visiteur redirigé vers connexion' => [null, 302];
    }

    // Scénario 06 — l’administrateur voit Bob, modifie son prénom et suspend son compte.
    public function test06AdministrateurListeModifieEtSuspendBob(): void
    {
        $admin = $this->user(self::ADMIN, admin: true);
        $id = $this->user(self::BOB)->getId();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', self::BOB);
        $this->client->request('GET', '/admin/utilisateur/'.$id.'/modifier');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Enregistrer', ['user_admin[prenom]' => 'Robert']);
        self::assertResponseRedirects('/admin');
        self::assertSame('Robert', $this->em()->getConnection()->fetchOne('SELECT prenom FROM user WHERE id = ?', [$id]));
        $this->toggleUser($id);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT est_actif FROM user WHERE id = ?', [$id]));
    }

    #[DataProvider('forbiddenAdminActions')]
    // Scénario 06 — isoler le contrôle des droits avec un formulaire valide ; Bob doit rester inchangé.
    public function test06AliceNePeutNiSuspendreNiSupprimerBob(string $action): void
    {
        $alice = $this->user();
        $id = $this->user(self::BOB)->getId();
        $admin = $this->user(self::ADMIN, admin: true);
        $before = $this->em()->getConnection()->fetchAssociative('SELECT * FROM user WHERE id = ?', [$id]);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->filter('form[action="/admin/utilisateur/'.$id.'/'.$action.'"]')->form();
        // Conserver le jeton CSRF valide dans la session, puis remplacer seulement
        // l'identité par Alice : un refus CSRF ne doit pas masquer un défaut de rôle.
        $this->client->loginUser($alice);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->em()->getConnection()->fetchAssociative('SELECT * FROM user WHERE id = ?', [$id]));
        self::assertSame(3, $this->em()->getRepository(User::class)->count([]));
    }

    // Deux cas indépendants : tentative de suspension, puis de suppression.
    public static function forbiddenAdminActions(): iterable
    {
        yield 'suspension HTTP directe' => ['suspendre'];
        yield 'suppression HTTP directe' => ['supprimer'];
    }

    #[DataProvider('mandatoryFields')]
    // Scénario 09 — vider un seul champ par exécution ; tous les autres restent valides.
    public function test09ChaqueChampObligatoireVideRefuseSansInsertion(string $field): void
    {
        // 1. PRÉPARER : si le mot de passe est testé, vider aussi sa confirmation.
        $changes = $field === 'plainPassword_first'
            ? ['registration[plainPassword][first]' => '', 'registration[plainPassword][second]' => '']
            : ['registration['.$field.']' => ''];
        // 2. AGIR : envoyer le formulaire avec ce champ vide.
        $this->register($changes);
        // 3. VÉRIFIER : erreur ciblée, zéro compte et zéro connexion.
        $this->assertRegistrationRefused($field);
    }

    // Liste des champs obligatoires : chaque valeur devient un cas PHPUnit distinct.
    public static function mandatoryFields(): iterable
    {
        foreach (['nom', 'prenom', 'adrRue', 'cp', 'plainPassword_first', 'email', 'ville'] as $field) {
            yield $field.' vide' => [$field];
        }
    }

    #[DataProvider('invalidRegistrationData')]
    // Scénario 10 — injecter une valeur invalide et vérifier son erreur sans création de compte.
    public function test10ChaqueDonneeInvalideRefuseeSansInsertion(string $field, string $value): void
    {
        // 1. PRÉPARER : remplacer uniquement le champ désigné par la valeur invalide.
        $changes = $field === 'plainPassword_first'
            ? ['registration[plainPassword][first]' => $value, 'registration[plainPassword][second]' => $value]
            : ['registration['.$field.']' => $value];
        // 2. AGIR : envoyer le vrai formulaire d’inscription.
        $this->register($changes);
        // 3. VÉRIFIER : le refus vient du champ testé, et aucun compte n’est enregistré.
        $this->assertRegistrationRefused($field);
        if ($value === self::COMPROMISED_PASSWORD) {
            self::assertSelectorTextContains('main', 'Ce mot de passe apparaît dans une fuite de données');
        }
    }

    // Pour ajouter un cas : ajouter un yield avec [champ, valeur] ; le nom avant => apparaît dans le résultat.
    public static function invalidRegistrationData(): iterable
    {
        yield 'code postal alphabétique ABCDE' => ['cp', 'ABCDE'];
        yield 'code postal de quatre chiffres' => ['cp', '1234'];
        yield 'code postal de six chiffres' => ['cp', '123456'];
        yield 'email invalide' => ['email', 'alice-invalide'];
        yield 'nom dépassant 100 caractères' => ['nom', str_repeat('N', 101)];
        yield 'mot de passe trop court' => ['plainPassword_first', 'Ab!3'];
        // HIBP simulé en environnement test ; le vrai validateur Symfony reste actif.
        yield 'mot de passe compromis' => ['plainPassword_first', self::COMPROMISED_PASSWORD];
    }

    // Scénario 11 — vérifier le compte, son hachage et une nouvelle connexion après déconnexion.
    public function test11InscriptionValideCreeCompteHachePuisPermetNouvelleConnexion(): void
    {
        $this->register();
        self::assertResponseRedirects('/profil');
        self::assertSame(1, $this->em()->getRepository(User::class)->count([]));
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => self::ALICE]);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Alice', $user->getPrenom());
        self::assertSame('62000', $user->getCp());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertNotSame(self::PASSWORD_A, $user->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, self::PASSWORD_A));
        $this->logout();
        $this->login(); // Vraie connexion après déconnexion, pas seulement auto-login d'inscription.
        self::assertResponseRedirects('/profil');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->historyCount());
    }

    // Scénario 12 — plusieurs refus gardent la base vide ; la correction crée un seul compte.
    public function test12RefusSuccessifsPuisCorrectionNeCreeQuUnSeulCompte(): void
    {
        $this->register(['registration[cp]' => 'ABCDE']);
        $this->assertRegistrationRefused('cp');
        $this->register(['registration[nom]' => '']);
        $this->assertRegistrationRefused('nom');
        $this->register(['registration[plainPassword][first]' => 'abc', 'registration[plainPassword][second]' => 'abc']);
        $this->assertRegistrationRefused('plainPassword_first');
        $this->register();
        self::assertResponseRedirects('/profil');
        self::assertSame(1, $this->em()->getRepository(User::class)->count([]));
        $this->logout();
        $this->register(); // Un nouvel envoi identique ne crée pas de doublon.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Cette adresse email est déjà utilisée');
        self::assertSame(1, $this->em()->getRepository(User::class)->count([]));
    }

    // Scénario 13 — parcours complet : connexion, suspension par admin, refus, réactivation et nouvelle connexion.
    public function test13CompteActifPuisSuspenduPuisReactiveParAdministrateur(): void
    {
        $id = $this->user()->getId();
        $this->user(self::ADMIN, admin: true);
        $this->login();
        self::assertResponseRedirects('/profil');
        $this->logout();

        $this->login(self::ADMIN);
        self::assertResponseRedirects('/profil');
        $this->toggleUser($id);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT est_actif FROM user WHERE id = ?', [$id]));
        $this->logout();
        // Compte désormais suspendu : les mêmes bons identifiants doivent être refusés.
        $this->login();
        $this->assertLoginRefused();
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion WHERE user_id = ?', [$id]));

        $this->login(self::ADMIN);
        self::assertResponseRedirects('/profil');
        $this->toggleUser($id);
        // Après la deuxième action de l’administrateur, le compte doit redevenir actif.
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT est_actif FROM user WHERE id = ?', [$id]));
        $this->logout();
        $this->login();
        self::assertResponseRedirects('/profil');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM historique_connexion WHERE user_id = ?', [$id]));
    }

    // Scénario 14 — effectuer réellement A puis B puis C ; A et B doivent ensuite être refusés.
    public function test14CycleReelDeMotsDePasseABCRefuseReutilisationDeAEtB(): void
    {
        $this->user();
        $this->login();
        self::assertResponseRedirects('/profil');
        // Créer l’historique par les vrais changements de formulaire, pas par insertion directe en base.
        $this->changePassword(self::PASSWORD_A, self::PASSWORD_B);
        self::assertResponseRedirects('/profil');
        self::assertSame(1, $this->passwordHistoryCount());
        $this->changePassword(self::PASSWORD_B, self::PASSWORD_C);
        self::assertResponseRedirects('/profil');
        self::assertSame(2, $this->passwordHistoryCount());
        $currentHash = $this->storedPassword();

        // Essayer chacun des deux anciens mots de passe ; C et les deux archives doivent rester inchangés.
        foreach ([self::PASSWORD_A, self::PASSWORD_B] as $oldPassword) {
            $this->changePassword(self::PASSWORD_C, $oldPassword);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('main', 'Ce mot de passe a déjà été utilisé');
            self::assertSame($currentHash, $this->storedPassword());
            self::assertSame(2, $this->passwordHistoryCount());
        }
        $this->logout();
        $this->login(password: self::PASSWORD_C);
        self::assertResponseRedirects('/profil');
    }

    // Scénario 15 — mauvais mot de passe actuel : message attendu, hachage inchangé, aucune archive ajoutée.
    public function test15MotDePasseActuelIncorrectNeModifieRien(): void
    {
        $this->user();
        $this->login();
        $before = $this->storedPassword();
        $this->changePassword('Mauvais!Mot-1234', self::PASSWORD_B);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Votre mot de passe actuel est incorrect');
        self::assertSame($before, $this->storedPassword());
        self::assertSame(0, $this->passwordHistoryCount());
    }

    #[DataProvider('invalidNewPasswords')]
    // Scénario 15 — nouveau mot de passe faible ou compromis : aucun changement enregistré.
    public function test15NouveauMotDePasseNonConformeNeModifieRien(string $password, string $message): void
    {
        $this->user();
        $this->login();
        $before = $this->storedPassword();
        $this->changePassword(self::PASSWORD_A, $password);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', $message);
        self::assertSame($before, $this->storedPassword());
        self::assertSame(0, $this->passwordHistoryCount());
    }

    // Chaque cas fournit le mot de passe refusé et le message d’erreur attendu.
    public static function invalidNewPasswords(): iterable
    {
        yield 'nouveau mot de passe faible' => ['abc', 'Utilisez au moins 10 caractères'];
        yield 'nouveau mot de passe compromis' => [self::COMPROMISED_PASSWORD, 'Ce mot de passe apparaît dans une fuite de données'];
    }

    // Scénario 15 — changement valide : ancien hachage archivé, nouveau mot de passe accepté, ancien refusé.
    public function test15ChangementAutorisePermetNouveauMotDePasseEtRefuseAncien(): void
    {
        $this->user();
        $this->login();
        $oldHash = $this->storedPassword();
        $this->changePassword(self::PASSWORD_A, self::PASSWORD_B);
        self::assertResponseRedirects('/profil');
        self::assertNotSame($oldHash, $this->storedPassword());
        self::assertSame($oldHash, $this->em()->getConnection()->fetchOne('SELECT ancien_mdp_hache FROM historique_mdp'));
        self::assertSame(1, $this->passwordHistoryCount());
        $this->logout();

        $this->login(password: self::PASSWORD_B);
        self::assertResponseRedirects('/profil');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $this->logout();
        $this->login(password: self::PASSWORD_A);
        $this->assertLoginRefused();
        self::assertSame(2, $this->historyCount());
    }
}

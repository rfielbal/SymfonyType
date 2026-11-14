<?php

// CYBER 3 — scénarios 7 (profil d’autrui) et 8 (rôles et habilitations).
// Repères : user() prépare les comptes ; profileData() lit le formulaire ; account() relit la base.
// loginUser() prépare une session, sans tester le formulaire de connexion.
// Une assertion compare un résultat attendu à celui obtenu : assertSame(attendu, obtenu).
// Pour l’oral : rechercher testScenario07AliceNePeutPasOuvrirProfilBob ou testScenario08AliceModifieSonPrenomSansChangerSesRoles.

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\TestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Scénarios 7 et 8 : autorisation sur la cible et absence d'élévation de privilèges. */
final class ProfileSecurityTest extends WebTestCase
{
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

    // Fixture : compte fictif réellement enregistré dans MariaDB de test ; admin=true donne ROLE_ADMIN.
    private function user(string $prenom = 'Alice', bool $admin = false): User
    {
        $user = (new User())->setEmail(strtolower($prenom).'@example.test')
            ->setNom('Exemple')->setPrenom($prenom)->setAdrRue('1 rue du Test')
            ->setVille('Arras')->setCp('62000')->setEstActif(true)
            ->setRoles($admin ? ['ROLE_ADMIN'] : ['ROLE_USER']);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, 'Orbite!Nuage-7319'));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Relire SQL évite de confondre une entité modifiée en mémoire avec une écriture en base. */
    // Photo de la ligne SQL : comparer avant/après vérifie que les données sont restées identiques.
    private function account(int $id): array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM user WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    // Ouvrir le formulaire autorisé et récupérer tous ses champs, dont le jeton CSRF de la session.
    private function profileData(int $id): array
    {
        $crawler = $this->client->request('GET', "/profil/$id/modifier");
        self::assertResponseIsSuccessful();

        return $crawler->selectButton('Enregistrer les coordonnées')->form()->getPhpValues();
    }

    // Scénario 07 — Alice modifie ses coordonnées ; email, rôle, mot de passe et état restent inchangés.
    public function testScenario07AliceModifieSonPropreProfil(): void
    {
        // 1. PRÉPARER : garder une copie du compte avant la modification.
        $alice = $this->user();
        $id = $alice->getId();
        $before = $this->account($id);
        $this->client->loginUser($alice);
        $this->client->request('GET', '/profil');
        $this->client->clickLink('Modifier mes coordonnées');
        self::assertResponseIsSuccessful();
        // 2. AGIR : envoyer le vrai formulaire ; les noms profile[...] désignent ses champs.
        $this->client->submitForm('Enregistrer les coordonnées', [
            'profile[nom]' => 'Martin', 'profile[prenom]' => 'Alicia',
            'profile[adrRue]' => '2 rue du Centre', 'profile[ville]' => 'Lille', 'profile[cp]' => '59000',
        ]);
        self::assertResponseRedirects("/profil/$id/modifier");
        // 3. VÉRIFIER : relire la base, contrôler les nouvelles valeurs puis les champs sensibles.
        $after = $this->account($id);
        foreach (['nom' => 'Martin', 'prenom' => 'Alicia', 'adr_rue' => '2 rue du Centre', 'ville' => 'Lille', 'cp' => '59000'] as $field => $value) {
            self::assertSame($value, $after[$field]);
        }
        foreach (['email', 'roles', 'password', 'est_actif'] as $field) {
            self::assertSame($before[$field], $after[$field]);
        }
    }

    // Scénario 07 — IDOR/BOLA : être connecté ne donne aucun droit sur le profil de Bob.
    public function testScenario07AliceNePeutPasOuvrirProfilBob(): void
    {
        // 1. PRÉPARER : créer Alice et Bob, puis mémoriser les données de Bob.
        $alice = $this->user();
        $bobId = $this->user('Bob')->getId();
        $before = $this->account($bobId);
        // 2. AGIR : Alice demande le formulaire appartenant à Bob (GET = lecture de la page).
        $this->client->loginUser($alice);
        $this->client->request('GET', "/profil/$bobId/modifier");
        // 3. VÉRIFIER : accès interdit (403), puis données de Bob inchangées en base avec assertSame().
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->account($bobId));
    }

    // Scénario 07 — même URL de modification, mais identifiant de Bob : l’accès doit être refusé.
    public function testScenario07RemplacementIdentifiantDansUrlRefuse(): void
    {
        $alice = $this->user();
        $bobId = $this->user('Bob')->getId();
        $before = $this->account($bobId);
        $this->client->loginUser($alice);
        $this->profileData($alice->getId());
        // Même chemin de modification, seul l'identifiant de la cible change.
        $this->client->request('GET', "/profil/$bobId/modifier");
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->account($bobId));
    }

    // Scénario 07 — envoyer directement un POST ne doit ni contourner les droits ni modifier Bob.
    public function testScenario07RequeteDirecteSurBobRefuseeSansEcriture(): void
    {
        $alice = $this->user();
        $bobId = $this->user('Bob')->getId();
        $before = $this->account($bobId);
        $this->client->loginUser($alice);
        // Jeton valide de la session d'Alice : l'échec doit provenir de l'autorisation sur Bob.
        $data = $this->profileData($alice->getId());
        $data['profile']['prenom'] = 'Pirate';
        $this->client->request('POST', "/profil/$bobId/modifier", $data);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->account($bobId));
    }

    // Scénario 07 — cas autorisé : un administrateur peut modifier Bob.
    public function testScenario07AdministrateurModifieProfilBob(): void
    {
        $admin = $this->user('Admin', true);
        $bobId = $this->user('Bob')->getId();
        $this->client->loginUser($admin);
        $this->profileData($bobId);
        $this->client->submitForm('Enregistrer les coordonnées', ['profile[prenom]' => 'Robert']);
        self::assertResponseRedirects("/profil/$bobId/modifier");
        self::assertSame('Robert', $this->account($bobId)['prenom']);
    }

    // Scénario 08 — changer le prénom doit fonctionner sans donner de nouveaux droits.
    public function testScenario08AliceModifieSonPrenomSansChangerSesRoles(): void
    {
        // 1. PRÉPARER : créer Alice et mémoriser ses rôles actuels.
        $alice = $this->user();
        $id = $alice->getId();
        $roles = $this->account($id)['roles'];
        $this->client->loginUser($alice);
        $this->profileData($id);
        // 2. AGIR : seule la valeur du prénom est remplacée ; les autres champs restent ceux du formulaire.
        $this->client->submitForm('Enregistrer les coordonnées', ['profile[prenom]' => 'Alicia']);
        // 3. VÉRIFIER : redirection après enregistrement, prénom modifié, rôles identiques et /admin toujours interdit.
        self::assertResponseRedirects("/profil/$id/modifier");
        $after = $this->account($id);
        self::assertSame('Alicia', $after['prenom']);
        self::assertSame($roles, $after['roles']);
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('forbiddenProfileFields')]
    // Scénario 08 et compléments — un champ sensible ajouté au formulaire doit être refusé.
    public function testScenario08ChampSensibleInjecteRefuseSansEcriture(string $field, mixed $value): void
    {
        $alice = $this->user();
        $id = $alice->getId();
        $before = $this->account($id);
        $this->client->loginUser($alice);
        $data = $this->profileData($id);
        $data['profile']['prenom'] = 'Pirate';
        $data['profile'][$field] = $value;
        $this->client->request('POST', "/profil/$id/modifier", $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->account($id));
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    // Jeux de données : chaque yield lance un cas indépendant avec un champ injecté différent.
    public static function forbiddenProfileFields(): iterable
    {
        yield 'auto-attribution ROLE_ADMIN' => ['roles', ['ROLE_ADMIN']];
        yield 'identifiant dans le corps' => ['id', 999999];
        yield 'hachage du mot de passe' => ['password', 'hachage-injecte'];
        yield 'statut du compte' => ['estActif', false];
    }

    // Scénario 08 — roles placé à côté du formulaire doit rester sans effet sur les droits.
    public function testScenario08ParametreRolesHorsFormulaireSansElevation(): void
    {
        $alice = $this->user();
        $id = $alice->getId();
        $before = $this->account($id);
        $this->client->loginUser($alice);
        $data = $this->profileData($id);
        $data['roles'] = ['ROLE_ADMIN'];
        $this->client->request('POST', "/profil/$id/modifier", $data);
        self::assertResponseRedirects("/profil/$id/modifier");
        self::assertSame($before, $this->account($id));
        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);
    }

    // Scénario 08 — Alice ne peut changer les rôles de Bob, ni via son profil ni via l’administration.
    public function testScenario08AliceNePeutPasModifierRolesBob(): void
    {
        $alice = $this->user();
        $bobId = $this->user('Bob')->getId();
        $before = $this->account($bobId);
        $this->client->loginUser($alice);
        $data = $this->profileData($alice->getId());
        $data['profile']['roles'] = ['ROLE_ADMIN'];
        $this->client->request('POST', "/profil/$bobId/modifier", $data);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->account($bobId));
        // Vérifier aussi le véritable point d'entrée qui accepte les rôles des administrateurs.
        $this->client->request('POST', "/admin/utilisateur/$bobId/modifier", [
            'user_admin' => ['roles' => ['ROLE_ADMIN']],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->account($bobId));
    }

    // Scénario 08 — vérifier le rôle enregistré puis son effet réel sur l’accès à /admin.
    public function testScenario08AdministrateurAttribueRoleAutoriseBob(): void
    {
        $admin = $this->user('Admin', true);
        $bobId = $this->user('Bob')->getId();
        $this->client->loginUser($admin);
        $this->client->request('GET', "/admin/utilisateur/$bobId/modifier");
        $this->client->submitForm('Enregistrer', ['user_admin[roles]' => ['ROLE_USER', 'ROLE_ADMIN']]);
        self::assertResponseRedirects('/admin');
        $roles = json_decode($this->account($bobId)['roles'], true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('ROLE_ADMIN', $roles);
        // Le droit attribué est effectivement appliqué lors d'une nouvelle session.
        $this->em()->clear();
        $this->client->loginUser($this->em()->find(User::class, $bobId));
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
    }

    // Complément — même un administrateur doit respecter la liste des rôles autorisés.
    public function testAdministrateurNePeutPasAttribuerRoleHorsListe(): void
    {
        $admin = $this->user('Admin', true);
        $bobId = $this->user('Bob')->getId();
        $before = $this->account($bobId);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', "/admin/utilisateur/$bobId/modifier");
        $data = $crawler->selectButton('Enregistrer')->form()->getPhpValues();
        $data['user_admin']['roles'] = ['ROLE_SUPER_ADMIN'];
        $this->client->request('POST', "/admin/utilisateur/$bobId/modifier", $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->account($bobId));
    }

    #[DataProvider('invalidCsrfTokens')]
    // Complément CSRF — être propriétaire du profil ne suffit pas si le jeton du formulaire est invalide.
    public function testModificationProfilSansJetonCsrfValideRefusee(?string $token): void
    {
        $alice = $this->user();
        $id = $alice->getId();
        $before = $this->account($id);
        $this->client->loginUser($alice);
        $data = $this->profileData($id);
        $data['profile']['prenom'] = 'Pirate';
        if ($token === null) {
            unset($data['profile']['_token']);
        } else {
            $data['profile']['_token'] = $token;
        }
        $this->client->request('POST', "/profil/$id/modifier", $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->account($id));
    }

    // Deux exécutions : champ du jeton absent, puis jeton falsifié.
    public static function invalidCsrfTokens(): iterable
    {
        yield 'jeton absent' => [null];
        yield 'jeton falsifié' => ['faux'];
    }
}

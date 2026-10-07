# Sécurité — SymfonyType

Cette documentation décrit les contrôles de sécurité, leurs fichiers d’implémentation et les limites de validation.

## 1. Inscription, connexion et validation des informations

- `src/Entity/User.php` : nom, prénom, adresse, ville, code postal et email ; `NotBlank`, `Length`, `Email` et `UniqueEntity` contrôlent les saisies. La contrainte unique SQL protège aussi l'email. Le code postal reste du texte.
- `src/Form/RegistrationType.php` : formulaire lié à l'utilisateur ; le mot de passe en clair est un champ non mappé, pas une colonne.
- `src/Controller/RegistrationController.php` : `isSubmitted()` et `isValid()` avant toute insertion, attribution de `ROLE_USER` uniquement, hachage puis `flush()`.
- `config/packages/security.yaml` et `src/Controller/SecurityController.php` : Symfony charge le compte par email, vérifie le mot de passe et crée la session. Le contrôleur affiche la page ; Symfony intercepte le POST de connexion.

L'email sert d'identifiant de connexion.

## 2. Sécurité du mot de passe

- `src/Security/PasswordPolicy.php` : une liste commune de contraintes utilisée par les trois formulaires (`RegistrationType`, `ChangePasswordType`, `UserAdminType`). Minimum 10 caractères, minuscule, majuscule, chiffre, caractère spécial, estimation de robustesse et `NotCompromisedPassword`.
- `config/packages/security.yaml` : hachage `auto`, via le hasher Symfony. Les valeurs de test réduites ne concernent jamais la production.
- `NotCompromisedPassword` consulte les fuites connues de Have I Been Pwned. Ce service ne reçoit qu'un préfixe d'empreinte ; il ne reçoit pas le mot de passe en clair. Cela ne garantit pas l'absence de toute fuite inconnue. L'accès Internet doit être disponible sur le serveur ; le contrôle reste actif en cas d'erreur en production.

## 3. Changement et non-réutilisation des anciens mots de passe

- `src/Form/ChangePasswordType.php` : ancien mot de passe contrôlé par `UserPassword`, nouveau saisi deux fois et validé par la politique commune.
- `src/Controller/ProfileController.php` : comparaison avec le hachage actuel puis tous les hachages de `HistoriqueMdp`. `verify($oldHash, $newPassword)` vérifie un candidat contre un hachage précis ; comparer deux hachages directement ne convient pas.
- `src/Entity/HistoriqueMdp.php` : ancien hachage, utilisateur et date du remplacement, jamais de texte en clair.
- Un seul `flush()` enregistre l'archivage et le nouveau hachage dans une transaction Doctrine. Si le formulaire est invalide, rien n'est enregistré.
- Le rehash technique de `UserRepository::upgradePassword()` n'est pas un changement du mot de passe choisi : il ne crée pas d'entrée historique.

Le mot de passe actuel et tous les anciens mots de passe sont refusés comme nouveau mot de passe.

## 4. Protection contre la force brute

- `config/packages/security.yaml` : `login_throttling`, maximum 3 essais / 15 minutes pour la limite liée à l'identifiant et l'adresse IP. Symfony applique aussi une limite globale par IP. Il ne s'agit pas d'une suspension permanente du compte.

Après trois échecs, une nouvelle tentative est refusée temporairement, même avec le bon mot de passe. Ce comportement est couvert par un test fonctionnel.

## 5. Comptes, rôles, habilitations et suspension

- `src/Controller/AdminController.php` : liste, ajout, modification, suspension/réactivation et suppression. Toutes les actions contrôlent `ROLE_ADMIN` côté serveur.
- `src/Form/UserAdminType.php` : champs administrables, choix limité aux deux rôles connus. `ROLE_ADMIN` hérite de `ROLE_USER` ; les permissions sont fixes et définies dans le code.
- `config/packages/security.yaml` : les URL `/admin` et `/profil` sont protégées. Masquer un lien dans Twig est seulement de la présentation.
- `src/Security/UserChecker.php` : refuse une nouvelle connexion si `estActif` est faux.
- `src/Entity/User.php::isEqualTo()` : Symfony recharge le compte à chaque requête et invalide la session si l'état, le rôle, l'email ou le mot de passe ont changé. La suspension prend donc aussi effet sur une session déjà ouverte dès sa requête suivante.
- POST et jetons CSRF pour les modifications sensibles ; déconnexion avec jeton. Un administrateur ne peut pas se supprimer, se suspendre ou se retirer son propre rôle administrateur.

L'administration renvoie 403 aux utilisateurs sans rôle administrateur. La suppression d'un compte supprime ses historiques (`ON DELETE CASCADE`), tandis que la suspension les conserve.

## 6. Historique complet des connexions

- `src/EventSubscriber/LoginHistorySubscriber.php` : écoute `LoginSuccessEvent`, enregistre une ligne pour chaque connexion réussie.
- `src/Entity/HistoriqueConnexion.php` : utilisateur et date/heure.
- `AdminController::logins()` et `templates/admin/logins.html.twig` : liste complète triée, affichée en heure de Paris et accessible uniquement à l'admin.

Chaque connexion réussie ajoute une ligne. La navigation et les échecs de connexion n'ajoutent pas d'entrée.

## Versionnement et déploiement

Le code et les migrations sont versionnés ; `.env.local` et `vendor/` sont exclus. Une installation utilise `composer install`, sa propre configuration de base et les migrations (voir [BASE-DE-DONNEES.md](BASE-DE-DONNEES.md)).

La protection de branche avec approbation obligatoire et le déploiement GitHub Actions par SSH ne sont pas encore configurés. Les tests locaux ne constituent pas un workflow CI.

## Vérifications et limites

```bash
php bin/phpunit
php bin/console lint:yaml config
php bin/console lint:twig templates
php bin/console lint:container
```

Les tests fonctionnels utilisent une base SQLite temporaire isolée et le vrai noyau Symfony : inscription, connexion, droits, historique, anciens mots de passe, limitation, suspension, sessions, administration et CSRF. Aucun compte réel n'est modifié.

Les tests de non-compromission simulent la réponse HTTP HIBP : ils vérifient le rejet et le préfixe envoyé, pas la disponibilité réelle du service. Le service externe est désactivé uniquement dans les parcours automatisés `test`, puis testé séparément avec ces réponses simulées.

La migration MariaDB, l'accès réel à HIBP, Apache et le déploiement doivent encore être vérifiés sur le serveur cible. L'environnement Docker initial du dépôt pointe toujours vers PostgreSQL ; il n'est pas configuré pour MariaDB.

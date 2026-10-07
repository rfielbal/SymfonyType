# Retrouver les exigences du sujet

Référence : Cybersecu-AP2.pdf, page 1 et annotations en bas de page. Les numéros ci-dessous sont des repères de lecture ajoutés au code, pas une numérotation du professeur. Rechercher `EXIGENCE` dans l'éditeur pour retrouver les commentaires.

## 1. Inscription, connexion et validation des informations

- `src/Entity/User.php` : nom, prénom, adresse, ville, code postal et email ; `NotBlank`, `Length`, `Email` et `UniqueEntity` contrôlent les saisies. La contrainte unique SQL protège aussi l'email. Le code postal reste du texte.
- `src/Form/RegistrationType.php` : formulaire lié à l'utilisateur ; le mot de passe en clair est un champ non mappé, pas une colonne.
- `src/Controller/RegistrationController.php` : `isSubmitted()` et `isValid()` avant toute insertion, attribution de `ROLE_USER` uniquement, hachage puis `flush()`.
- `config/packages/security.yaml` et `src/Controller/SecurityController.php` : Symfony charge le compte par email, vérifie le mot de passe et crée la session. Le contrôleur affiche la page ; Symfony intercepte le POST de connexion.

À montrer : inscription valide, saisies refusées, connexion et déconnexion. L'email comme identifiant est un choix du projet.

## 2. Sécurité du mot de passe

- `src/Security/PasswordPolicy.php` : une liste commune de contraintes utilisée par les trois formulaires (`RegistrationType`, `ChangePasswordType`, `UserAdminType`). Minimum 10 caractères, minuscule, majuscule, chiffre, caractère spécial, estimation de robustesse et `NotCompromisedPassword`.
- `config/packages/security.yaml` : hachage `auto`, via le hasher Symfony. Les valeurs de test réduites ne concernent jamais la production.
- `NotCompromisedPassword` consulte les fuites connues de Have I Been Pwned. Ce service ne reçoit qu'un préfixe d'empreinte ; il ne reçoit pas le mot de passe en clair. Cela ne garantit pas l'absence de toute fuite inconnue. L'accès Internet doit être disponible sur le serveur ; nous ne désactivons pas le contrôle en cas d'erreur en production.

Les seuils sont des choix documentés du projet, l'énoncé ne les chiffre pas. À montrer : un mot de passe faible refusé, un mot de passe répondant aux règles accepté et un cas de compromission refusé.

## 3. Changement et non-réutilisation des anciens mots de passe

- `src/Form/ChangePasswordType.php` : ancien mot de passe contrôlé par `UserPassword`, nouveau saisi deux fois et validé par la politique commune.
- `src/Controller/ProfileController.php` : comparaison avec le hachage actuel puis tous les hachages de `HistoriqueMdp`. `verify($oldHash, $newPassword)` vérifie un candidat contre un hachage précis ; comparer deux hachages directement ne convient pas.
- `src/Entity/HistoriqueMdp.php` : ancien hachage, utilisateur et date du remplacement, jamais de texte en clair.
- Un seul `flush()` enregistre l'archivage et le nouveau hachage dans une transaction Doctrine. Si le formulaire est invalide, rien n'est enregistré.
- Le rehash technique de `UserRepository::upgradePassword()` n'est pas un changement du mot de passe choisi : il ne crée pas d'entrée historique.

À montrer : A → B → tentative de A refusée. Le mot de passe actuel est aussi refusé comme nouveau mot de passe. Aucun nombre limite d'anciens mots de passe n'est imposé par l'énoncé : tous sont vérifiés.

## 4. Protection contre la force brute

- `config/packages/security.yaml` : `login_throttling`, maximum 3 essais / 15 minutes pour la limite liée à l'identifiant et l'adresse IP. Symfony applique aussi une limite globale par IP. Il ne s'agit pas d'une suspension permanente du compte.

À montrer : après trois échecs, une nouvelle tentative est refusée temporairement, même avec le bon mot de passe. Le test fonctionnel reproduit ce cas.

## 5. Comptes, rôles, habilitations et suspension

- `src/Controller/AdminController.php` : liste, ajout, modification, suspension/réactivation et suppression. Toutes les actions contrôlent `ROLE_ADMIN` côté serveur.
- `src/Form/UserAdminType.php` : champs administrables, choix limité aux deux rôles connus. `ROLE_ADMIN` hérite de `ROLE_USER` ; les permissions sont fixes et définies dans le code.
- `config/packages/security.yaml` : les URL `/admin` et `/profil` sont protégées. Masquer un lien dans Twig est seulement de la présentation.
- `src/Security/UserChecker.php` : refuse une nouvelle connexion si `estActif` est faux.
- `src/Entity/User.php::isEqualTo()` : Symfony recharge le compte à chaque requête et invalide la session si l'état, le rôle, l'email ou le mot de passe ont changé. La suspension prend donc aussi effet sur une session déjà ouverte dès sa requête suivante.
- POST et jetons CSRF pour les modifications sensibles ; déconnexion avec jeton. Un administrateur ne peut pas se supprimer, se suspendre ou se retirer son propre rôle administrateur.

À montrer : un utilisateur reçoit 403 sur l'administration ; l'admin peut gérer un autre compte ; un compte suspendu ne peut plus accéder à son profil. Supprimer un compte supprime ses historiques (clé étrangère `ON DELETE CASCADE`), alors que suspendre les conserve : c'est notre règle de conservation.

## 6. Historique complet des connexions

- `src/EventSubscriber/LoginHistorySubscriber.php` : écoute `LoginSuccessEvent`, enregistre une ligne pour chaque connexion réussie.
- `src/Entity/HistoriqueConnexion.php` : utilisateur et date/heure.
- `AdminController::logins()` et `templates/admin/logins.html.twig` : liste complète triée, affichée en heure de Paris et accessible uniquement à l'admin.

À montrer : une connexion ajoute une ligne ; naviguer de page en page n'en ajoute pas. Les échecs de connexion ne sont pas confondus avec les connexions réussies.

## 7. GitHub, réutilisation, pull requests et Actions — étape suivante

Le code et les migrations sont versionnés ; `.env.local` et `vendor/` sont exclus. Une autre installation utilise `composer install`, sa propre configuration de base et les migrations (voir `BASE-DE-DONNEES.md`).

Les annotations du sujet et les précisions données demandent :

1. Une branche de travail et une pull request relue par le binôme, qui approuve ou demande des changements.
2. Une protection de `main` exigeant l'approbation, selon les possibilités du dépôt.
3. Un accès SSH de GitHub Actions vers le serveur commun 5183, avec une clé dédiée. La clé privée sera un secret GitHub, jamais un fichier du dépôt ; la clé publique autorisera le compte de déploiement.
4. Un workflow `.github/workflows/deploy.yml` mettant le serveur à jour après un push sur `main`, donc notamment après la fusion de la PR.

Ces éléments ne sont PAS encore configurés. Les tests locaux ci-dessous ne constituent pas un workflow CI. Le sujet n'impose pas explicitement leur automatisation dans GitHub Actions.

## 8. Documentation, captures et oral — à compléter pendant la mise en place

Ce guide donne les fichiers et le fonctionnement à expliquer. Il restera à capturer une PR réellement relue, sa fusion, l'exécution du workflow et la mise à jour réelle de 5183. Préparer ensuite le diaporama et une démonstration des parcours utilisateur/admin et d'une PR avec déploiement. Ne pas présenter une étape prévue comme une étape terminée.

## Vérifications et limites

```bash
php bin/phpunit
php bin/console lint:yaml config
php bin/console lint:twig templates
php bin/console lint:container
```

Les tests fonctionnels utilisent une base SQLite temporaire isolée et le vrai noyau Symfony : inscription, connexion, droits, historique, anciens mots de passe, limitation, suspension, sessions, administration et CSRF. Aucun compte réel n'est modifié.

Les tests de non-compromission simulent la réponse HTTP HIBP : ils vérifient le rejet et le préfixe envoyé, pas la disponibilité réelle du service. Le service externe est désactivé uniquement dans les parcours automatisés `test`, puis testé séparément avec ces réponses simulées.

La migration MariaDB, l'accès réel à HIBP, Apache et le déploiement doivent encore être vérifiés sur le serveur cible. L'environnement Docker initial du dépôt pointe toujours vers PostgreSQL ; il n'est pas prêt pour notre installation MariaDB. Cette étape de sécurité ne modifie pas l'infrastructure.

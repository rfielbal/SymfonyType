# Base de données — SymfonyType

## Ce qui est prêt

Symfony 7.4, Doctrine et trois entités :

- `User` : identité, email unique, hachage actuel, rôles JSON, date d'inscription et statut actif.
- `HistoriqueMdp` : ancien hachage, date de remplacement, utilisateur obligatoire.
- `HistoriqueConnexion` : date d'une connexion réussie, utilisateur obligatoire.

Les deux historiques utilisent une relation `ManyToOne` vers `User`. Les clés étrangères sont dans les tables d'historique, sans table d'association. Une suppression définitive d'utilisateur supprime ses historiques (`ON DELETE CASCADE`). Une suspension doit les conserver.

La base de sécurité a été générée avec :

```bash
php bin/console make:user User --is-entity --identity-property-name=email --with-password
```

Réponses équivalentes en mode interactif : `User`, stockage Doctrine oui, identifiant `email`, mot de passe oui. Cette commande a déjà été exécutée : ne pas la relancer après le pull.

Les champs métier et les historiques ont ensuite été ajoutés. Le SQL de la migration initiale est issu du schéma Doctrine pour MariaDB. Il a été préparé hors connexion : il n'a pas encore été appliqué sur le nuage.

## Fonctionnalités et exigences

L'inscription, la connexion, le profil, les contrôles d'accès, la suspension, les historiques et l'administration sont implémentés. Voir [EXIGENCES-DU-SUJET.md](EXIGENCES-DU-SUJET.md) pour retrouver chaque exigence, son code et les limites de validation.

`UserRepository::upgradePassword()` renouvelle un hachage technique sans changer le mot de passe choisi : ce renouvellement ne doit pas être ajouté à l'historique des changements de mot de passe.

## Installation sur le nuage de développement

Depuis le dossier du projet :

```bash
git pull --ff-only
composer install
```

Créer ou modifier `.env.local` sans écraser les autres réglages existants :

```bash
nano .env.local
```

Ajouter ou adapter cette ligne (toutes les valeurs en majuscules sont à remplacer) :

```dotenv
DATABASE_URL="mysql://UTILISATEUR_BDD:MOT_DE_PASSE_ENCODE@HOTE_BDD:3306/NOM_BASE?charset=utf8mb4"
```

Utiliser les accès **base de données** du nuage, pas automatiquement les accès SSH. Le nom suggéré est `symfony_type`, mais un nom attribué par l'établissement est prioritaire. Le mot de passe doit être encodé pour une URL si nécessaire (`@` devient `%40`, `#` devient `%23`, etc.). Ne pas partager cette ligne ni versionner `.env.local`. Aucun véritable identifiant n'est fourni dans le dépôt.

Doctrine détecte la version du serveur lors de la connexion. `mysql --version` affiche seulement la version du client. Pour confirmer la version du serveur, depuis le client SQL connecté :

```sql
SELECT VERSION();
```

Avec DBAL 4, une version MariaDB confirmée peut être ajoutée à l'URL, par exemple `&serverVersion=10.3.39-MariaDB` **uniquement si le serveur annonce cette version**. Le client observé était 10.3.39 ; la version réelle du serveur reste à vérifier. Les versions MariaDB anciennes peuvent provoquer un avertissement de support de Doctrine : ne pas modifier le serveur pédagogique sans l'administrateur.

Si aucune base n'existe et si le compte a le droit de la créer :

```bash
php bin/console doctrine:database:create
```

Si une base vide vous a déjà été attribuée, ignorer cette commande et utiliser son nom dans `.env.local`. Si la création est refusée, demander une base ou les droits nécessaires à l'enseignant ; ne pas passer en root.

Créer les tables avec la migration fournie :

```bash
php bin/console doctrine:migrations:migrate
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:status
```

Lire puis confirmer la demande de migration. Ne pas exécuter `make:migration` pour cette première installation : la migration est déjà fournie. Ne pas utiliser `doctrine:schema:update --force` en parallèle des migrations.

Résultat attendu : les trois tables métier et la table technique `doctrine_migration_versions`. La validation doit confirmer un mapping valide et un schéma synchronisé.

## Comprendre le mot de passe

`User.password` contient le hachage actuel. Lors d'un changement, l'application vérifie le nouveau candidat contre le hachage actuel et chaque ancien hachage. Si le changement est accepté, elle archive l'ancien hachage et enregistre le nouveau ensemble dans une transaction. Aucun mot de passe en clair ne sera conservé.

## Deux nuages distincts

Cette étape concerne le nuage de développement. Le nuage de déploiement aura ses propres accès et son propre `.env.local`. GitHub Actions, SSH et le déploiement seront traités séparément. Un pull récupère le code, pas la base ni les secrets.

## Vérifications locales

```bash
php bin/phpunit
php bin/console lint:container
php bin/console lint:yaml config
```

Les tests utilisent `pdo_sqlite` et une base en mémoire pour vérifier les contraintes du modèle et la suppression en cascade. Ils ne prouvent pas l'exécution de la migration MariaDB sur le nuage. La vérification `doctrine:schema:validate` sur ce dernier reste nécessaire après migration.

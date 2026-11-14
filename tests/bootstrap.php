<?php

// POINT D’ENTRÉE — PHPUnit charge ce fichier avant les tests, via phpunit.dist.xml.
// Charger Symfony et sa configuration test ; refuser un autre environnement avant d’exécuter la suite.

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if (($_SERVER['APP_ENV'] ?? null) !== 'test') {
    throw new RuntimeException('Les tests exigent APP_ENV=test.');
}

// Un secret propre à chaque exécution, jamais écrit ni versionné.
// Les redémarrages du KernelBrowser conservent cette valeur dans le processus.
if (empty($_SERVER['APP_SECRET'])) {
    $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = bin2hex(random_bytes(32));
    putenv('APP_SECRET='.$_SERVER['APP_SECRET']);
}

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}

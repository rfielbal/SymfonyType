<?php

// OUTIL DE TEST — réponse prévisible du service de mots de passe compromis, sans requête Internet.
// Le mot de passe fictif ci-dessous est signalé compromis ; les autres reçoivent une réponse sans correspondance.

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Simulation HIBP réservée à l'environnement test : aucune requête Internet. */
final class PwnedPasswordHttpClient
{
    public const COMPROMISED_PASSWORD = 'Fuite!Comete-7392';

    // Construire le client simulé injecté en environnement test ; conserver le format attendu par le validateur.
    public static function create(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url): MockResponse {
            if ($method !== 'GET' || !preg_match('~^https://api\.pwnedpasswords\.com/range/[A-F0-9]{5}$~', $url)) {
                throw new \LogicException('Requête HIBP inattendue dans le test.');
            }

            $hash = strtoupper(sha1(self::COMPROMISED_PASSWORD));
            $body = str_ends_with($url, substr($hash, 0, 5))
                ? substr($hash, 5).':12'
                : str_repeat('0', 35).':0';

            return new MockResponse($body);
        });
    }
}

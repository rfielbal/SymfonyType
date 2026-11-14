<?php

// CYBER 2 — tests unitaires des contraintes de mot de passe, sans navigateur ni base de données.
// MockHttpClient simule seulement le service externe de compromission ; le vrai validateur reste exécuté.

namespace App\Tests\Unit;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

final class PasswordPolicyTest extends TestCase
{
    // Mot de passe conforme aux règles de forme, mais signalé compromis : vérifier précisément cette erreur.
    public function testToutesLesReglesEtRefusMotDePasseCompromis(): void
    {
        $password = 'Orbite!Nuage-7319';
        $hash = strtoupper(sha1($password));
        // Réponse HIBP simulée : test déterministe sans envoyer de secret ni appeler Internet.
        $http = new MockHttpClient(function ($method, $url) use ($hash) {
            self::assertSame('https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5), $url);
            return new MockResponse(substr($hash, 5).':12');
        });
        $factory = new ConstraintValidatorFactory([
            NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator($http),
        ]);
        $validator = Validation::createValidatorBuilder()->setConstraintValidatorFactory($factory)->getValidator();
        $errors = $validator->validate($password, PasswordPolicy::constraints());
        self::assertCount(1, $errors);
        self::assertSame(NotCompromisedPassword::COMPROMISED_PASSWORD_ERROR, $errors[0]->getCode());
    }

    // Service simulé sans fuite : zéro erreur pour le mot de passe fort, plusieurs erreurs pour « abc ».
    public function testMotDePasseFortAccepteEtFaibleRefuse(): void
    {
        $http = new MockHttpClient(fn () => new MockResponse('00000000000000000000000000000000000:0'));
        $factory = new ConstraintValidatorFactory([
            NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator($http),
        ]);
        $validator = Validation::createValidatorBuilder()->setConstraintValidatorFactory($factory)->getValidator();
        self::assertCount(0, $validator->validate('Orbite!Nuage-7319', PasswordPolicy::constraints()));
        self::assertGreaterThanOrEqual(4, count($validator->validate('abc', PasswordPolicy::constraints())));
    }
}

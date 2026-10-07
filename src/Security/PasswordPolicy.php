<?php

namespace App\Security;

use Symfony\Component\Validator\Constraints as Assert;

/** EXIGENCE 2 — Règles communes à l'inscription, au changement et à la création admin. */
final class PasswordPolicy
{
    public static function constraints(): array
    {
        return [
            new Assert\NotBlank(message: 'Saisissez un mot de passe.'),
            new Assert\Length(min: 10, max: 4096, minMessage: 'Utilisez au moins {{ limit }} caractères.'),
            new Assert\Regex(pattern: '/[a-z]/', message: 'Ajoutez une minuscule.'),
            new Assert\Regex(pattern: '/[A-Z]/', message: 'Ajoutez une majuscule.'),
            new Assert\Regex(pattern: '/\d/', message: 'Ajoutez un chiffre.'),
            new Assert\Regex(pattern: '/[^a-zA-Z0-9]/', message: 'Ajoutez un caractère spécial.'),
            new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Choisissez un mot de passe plus difficile à deviner.'),
            // Vérification des fuites connues sans envoyer le mot de passe en clair.
            new Assert\NotCompromisedPassword(message: 'Ce mot de passe apparaît dans une fuite de données. Choisissez-en un autre.'),
        ];
    }
}

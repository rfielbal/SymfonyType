<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

// EXIGENCE 5 — Un compte suspendu ne peut pas ouvrir de nouvelle session.
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->isActif()) {
            throw new DisabledException('Ce compte est suspendu. Contacte un administrateur.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}

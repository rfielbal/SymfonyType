<?php

namespace App\EventSubscriber;

use App\Entity\HistoriqueConnexion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginHistorySubscriber
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    // EXIGENCE 6 — Une ligne datée pour chaque connexion réussie (pas chaque page visitée).
    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->entityManager->persist((new HistoriqueConnexion())->setUser($user));
        $this->entityManager->flush();
    }
}

<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $hasher, Security $security): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        // EXIGENCES 1 et 2 — Valider côté serveur, imposer ROLE_USER puis hacher avant insertion.
        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setRoles(['ROLE_USER']);
            $user->setPassword($hasher->hashPassword($user, $plainPassword));
            $entityManager->persist($user);
            $entityManager->flush();

            // Connexion Symfony après inscription : la session et l’historique suivent le même parcours qu’une connexion classique.
            $this->addFlash('success', 'Ton compte est créé. Tu es maintenant connecté.');
            return $security->login($user, 'form_login', 'main') ?? $this->redirectToRoute('app_profile');
        }

        return $this->render('registration/register.html.twig', ['registrationForm' => $form]);
    }
}

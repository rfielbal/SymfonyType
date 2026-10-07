<?php

namespace App\Controller;

use App\Entity\HistoriqueConnexion;
use App\Entity\User;
use App\Form\UserAdminType;
use App\Repository\HistoriqueConnexionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'users', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->render('admin/index.html.twig', ['users' => $users->findBy([], ['id' => 'DESC'])]);
    }

    #[Route('/utilisateur/nouveau', name: 'user_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $hasher): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = new User();
        $form = $this->createForm(UserAdminType::class, $user, ['include_password' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $entityManager->persist($user);
            $entityManager->flush();
            $this->addFlash('success', 'Le compte a été créé.');
            return $this->redirectToRoute('admin_users');
        }
        return $this->render('admin/form.html.twig', ['form' => $form, 'title' => 'Créer un compte']);
    }

    #[Route('/utilisateur/{id}/modifier', name: 'user_edit', methods: ['GET', 'POST'])]
    public function edit(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $form = $this->createForm(UserAdminType::class, $user);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Le compte a été mis à jour.');
            return $this->redirectToRoute('admin_users');
        }
        return $this->render('admin/form.html.twig', ['form' => $form, 'title' => 'Modifier un compte']);
    }

    #[Route('/utilisateur/{id}/suspendre', name: 'user_toggle', methods: ['POST'])]
    public function toggle(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('toggle-user-'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $user->setEstActif(!$user->isActif());
        $entityManager->flush();
        $this->addFlash('success', $user->isActif() ? 'Le compte a été réactivé.' : 'Le compte a été suspendu.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/utilisateur/{id}/supprimer', name: 'user_delete', methods: ['POST'])]
    public function delete(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('delete-user-'.$user->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($this->getUser() === $user) {
            $this->addFlash('error', 'Tu ne peux pas supprimer le compte avec lequel tu es connecté.');
            return $this->redirectToRoute('admin_users');
        }
        $entityManager->remove($user);
        $entityManager->flush();
        $this->addFlash('success', 'Le compte et ses historiques ont été supprimés.');
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/utilisateur/{id}/connexions', name: 'user_logins', methods: ['GET'])]
    public function logins(User $user, HistoriqueConnexionRepository $history): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        return $this->render('admin/logins.html.twig', [
            'user' => $user,
            'logins' => $history->findBy(['user' => $user], ['dateHeure' => 'DESC', 'id' => 'DESC']),
        ]);
    }
}

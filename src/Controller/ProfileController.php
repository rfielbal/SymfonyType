<?php

namespace App\Controller;

use App\Entity\HistoriqueMdp;
use App\Entity\User;
use App\Form\ChangePasswordType;
use App\Repository\HistoriqueMdpRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Form\FormError;

final class ProfileController extends AbstractController
{
    #[Route('/profil', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager, HistoriqueMdpRepository $history, UserPasswordHasherInterface $hasher, PasswordHasherFactoryInterface $hasherFactory): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = (string) $form->get('newPassword')->getData();
            // EXIGENCE 3 — Vérifier le candidat contre le hachage actuel et TOUS les anciens.
            $passwordVerifier = $hasherFactory->getPasswordHasher($user);
            $hashes = [$user->getPassword()];
            foreach ($history->findBy(['user' => $user]) as $oldPassword) {
                $hashes[] = $oldPassword->getAncienMdpHache();
            }

            foreach ($hashes as $oldHash) {
                if ($oldHash && $passwordVerifier->verify($oldHash, $newPassword)) {
                    $form->get('newPassword')->get('first')->addError(new FormError('Ce mot de passe a déjà été utilisé. Choisis-en un nouveau.'));
                    break;
                }
            }

            if ($form->get('newPassword')->getErrors(true)->count() === 0) {
                // Un seul flush : archivage et nouveau hachage sont enregistrés dans la même transaction.
                $entityManager->persist((new HistoriqueMdp())->setUser($user)->setAncienMdpHache($user->getPassword()));
                $user->setPassword($hasher->hashPassword($user, $newPassword));
                $entityManager->flush();
                $this->addFlash('success', 'Le mot de passe a été modifié.');
                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('profile/index.html.twig', ['changePasswordForm' => $form]);
    }
}

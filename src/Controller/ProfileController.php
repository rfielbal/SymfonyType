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
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProfileController extends AbstractController
{
    #[Route('/profil', name: 'app_profile')]
    public function index(Request $request, EntityManagerInterface $entityManager, HistoriqueMdpRepository $history, UserPasswordHasherInterface $hasher, ValidatorInterface $validator): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = (string) $form->get('newPassword')->getData();
            $hashes = [$user->getPassword()];
            foreach ($history->findBy(['user' => $user]) as $oldPassword) {
                $hashes[] = $oldPassword->getAncienMdpHache();
            }

            foreach ($hashes as $oldHash) {
                if ($oldHash && $hasher->isPasswordValid($user, $newPassword, $oldHash)) {
                    $form->get('newPassword')->addError(new \Symfony\Component\Form\FormError('Ce mot de passe a déjà été utilisé. Choisis-en un nouveau.'));
                    break;
                }
            }

            $violations = $validator->validate($newPassword, [
                new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Choisis un mot de passe plus difficile à deviner.'),
                new Assert\NotCompromisedPassword(message: 'Ce mot de passe apparaît dans une fuite de données. Choisis-en un autre.'),
            ]);
            foreach ($violations as $violation) {
                $form->get('newPassword')->addError(new \Symfony\Component\Form\FormError($violation->getMessage()));
            }

            if ($form->get('newPassword')->getErrors(true)->count() === 0) {
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

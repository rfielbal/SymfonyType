<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('oldPassword', PasswordType::class, [
                'mapped' => false,
                'label_attr' => ['class' => 'fw-bold'],
                'attr' => ['autocomplete' => 'old-password', 'class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'Rentre toutes les infos (mot de passe actuel).']),
                    new UserPassword(['message' => 'Ton mot de passe actuel est incorrect.']),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'label_attr' => ['class' => 'fw-bold'],
                'attr' => ['autocomplete' => 'new-password', 'class' => 'form-control'],
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
                'first_options' => ['label' => 'Nouveau mot de passe'],
                'second_options' => ['label' => 'Confirmer le mot de passe'],
                'label_attr' => ['class' => 'fw-bold'],
                'attr' => ['autocomplete' => 'new-password', 'class' => 'form-control'],
                'constraints' => [
                    new NotBlank(['message' => 'Rentre toutes les infos (nouveau mot de passe).']),
                    new Length(
                        min: 10,
                        minMessage: 'Veuillez mettre le nombre de caractère requis: 10',
                        max: 4096,
                    ),
                    new Assert\Regex(
                        pattern: '/[^a-zA-Z0-9]/',
                        message: 'Veuillez mettre un caractère spécial comme par exemple: !, @, #, ?'
                    ),
                    new Assert\Regex(
                        pattern: '/\d/',
                        message: 'Veuillez mettre au minimum un chiffre'
                    ),
                    new Assert\Regex(
                        pattern: '/[a-z]/',
                        message: 'Veuillez mettre au minimum un caractère en minuscule'
                    ),
                    new Assert\Regex(
                        pattern: '/[A-Z]/',
                        message: 'Veuillez mettre au minimum un caractère en majuscule'
                    ),
                ],
            ])
        ;
    }
}

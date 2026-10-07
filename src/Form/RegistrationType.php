<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail', 'attr' => ['autocomplete' => 'email']])
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('prenom', TextType::class, ['label' => 'Prénom'])
            ->add('adrRue', TextType::class, ['label' => 'Adresse'])
            ->add('ville', TextType::class, ['label' => 'Ville'])
            ->add('cp', TextType::class, ['label' => 'Code postal'])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'label' => 'Mot de passe',
                'first_options' => ['label' => 'Mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmer le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Choisis un mot de passe.']),
                    new Assert\Length(min: 10, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.', max: 4096),
                    new Assert\Regex(pattern: '/[a-z]/', message: 'Ajoute au moins une minuscule.'),
                    new Assert\Regex(pattern: '/[A-Z]/', message: 'Ajoute au moins une majuscule.'),
                    new Assert\Regex(pattern: '/\d/', message: 'Ajoute au moins un chiffre.'),
                    new Assert\Regex(pattern: '/[^a-zA-Z0-9]/', message: 'Ajoute au moins un caractère spécial.'),
                    new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Choisis un mot de passe plus difficile à deviner.'),
                    new Assert\NotCompromisedPassword(message: 'Ce mot de passe apparaît dans une fuite de données. Choisis-en un autre.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}

<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'Adresse e-mail'])
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('prenom', TextType::class, ['label' => 'Prénom'])
            ->add('adrRue', TextType::class, ['label' => 'Adresse'])
            ->add('ville', TextType::class, ['label' => 'Ville'])
            ->add('cp', TextType::class, ['label' => 'Code postal'])
            ->add('roles', ChoiceType::class, [
                'label' => 'Rôles',
                'choices' => ['Utilisateur' => 'ROLE_USER', 'Administrateur' => 'ROLE_ADMIN'],
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('estActif', CheckboxType::class, ['label' => 'Compte actif', 'required' => false]);

        if ($options['include_password']) {
            $builder->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Mot de passe initial',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Length(min: 10, max: 4096),
                    new Assert\Regex(pattern: '/[a-z]/'),
                    new Assert\Regex(pattern: '/[A-Z]/'),
                    new Assert\Regex(pattern: '/\d/'),
                    new Assert\Regex(pattern: '/[^a-zA-Z0-9]/'),
                    new Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM),
                    new Assert\NotCompromisedPassword(),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class, 'include_password' => false]);
        $resolver->setAllowedTypes('include_password', 'bool');
    }
}

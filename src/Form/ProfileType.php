<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Liste blanche des seules coordonnées modifiables depuis un profil. */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, ['label' => 'Nom'])
            ->add('prenom', TextType::class, ['label' => 'Prénom'])
            ->add('adrRue', TextType::class, ['label' => 'Adresse'])
            ->add('ville', TextType::class, ['label' => 'Ville'])
            ->add('cp', TextType::class, ['label' => 'Code postal']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            // Un paramètre profile[roles], id, password ou estActif invalide la demande.
            'allow_extra_fields' => false,
        ]);
    }
}

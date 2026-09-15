<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserRolesType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $choices = [
            'Utilisateur' => 'ROLE_USER',
            'Éditeur' => 'ROLE_EDITOR',
            'Administrateur' => 'ROLE_ADMIN',
        ];

        if ($options['can_manage_super_admin']) {
            $choices['Super Administrateur'] = 'ROLE_SUPER_ADMIN';
        }

        $builder->add('roles', ChoiceType::class, [
            'choices' => $choices,
            'multiple' => true,
            'expanded' => true,
        ]);
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => User::class,
            'can_manage_super_admin' => false,
        ]);

        $resolver->setAllowedTypes(
            'can_manage_super_admin',
            'bool'
        );
    }
}
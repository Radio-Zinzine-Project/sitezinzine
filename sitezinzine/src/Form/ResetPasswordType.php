<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'label' => 'Nouveau mot de passe',
            'attr' => ['autocomplete' => 'new-password'],
            'constraints' => [
                new NotBlank(),
                new Length(min: 8, max: 4096),
            ],
        ]);

        $builder->add('passwordConfirm', PasswordType::class, [
            'label' => 'Confirmer le mot de passe',
            'attr' => ['autocomplete' => 'new-password'],
            'mapped' => false,
            'constraints' => [
                new NotBlank(),
            ],
        ]);
    }
}

<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User $user */
        $user = $options['data'];
        $builder
            ->add('pseudo', TextType::class, [
                'required' => false,
                'label' => 'Pseudo (nom affiché)',
                'attr' => [
                    'autocomplete' => 'nickname',
                    'maxlength' => 60,
                ],
            ])
            ->add('newEmail', EmailType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Nouvel email (optionnel)',
                'data' => $user->getPendingEmail() ?? $user->getEmail(),
                'attr' => ['autocomplete' => 'email'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}

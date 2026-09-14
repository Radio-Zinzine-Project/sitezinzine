<?php

namespace App\Form;

use App\Entity\ProgrammationRuleSlot;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ProgrammationRuleSlotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('recurrenceType', ChoiceType::class, [
                'label' => 'Type de récurrence',
                'choices' => [
                    'Hebdomadaire' => ProgrammationRuleSlot::RECURRENCE_WEEKLY,
                    'Mensuelle' => ProgrammationRuleSlot::RECURRENCE_MONTHLY,
                ],
                'placeholder' => false,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Choice(
                        choices: [
                            ProgrammationRuleSlot::RECURRENCE_WEEKLY,
                            ProgrammationRuleSlot::RECURRENCE_MONTHLY,
                        ],
                    ),
                ],
            ])

            ->add('monthlyOccurrence', ChoiceType::class, [
                'label' => 'Position dans le mois',
                'required' => false,
                'placeholder' => 'Choisir une occurrence',
                'choices' => [
                    '1er' => ProgrammationRuleSlot::MONTHLY_FIRST,
                    '2e' => ProgrammationRuleSlot::MONTHLY_SECOND,
                    '3e' => ProgrammationRuleSlot::MONTHLY_THIRD,
                    '4e' => ProgrammationRuleSlot::MONTHLY_FOURTH,
                    'Dernier' => ProgrammationRuleSlot::MONTHLY_LAST,
                ],
            ])

            ->add('monthInterval', ChoiceType::class, [
                'label' => 'Rythme mensuel',
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'Tous les mois' => 1,
                    'Tous les 2 mois' => 2,
                    'Tous les 3 mois' => 3,
                    'Tous les 4 mois' => 4,
                ],
                'empty_data' => '1',
            ])

            ->add('dayOfWeek', ChoiceType::class, [
                'label' => 'Jour',
                'placeholder' => 'Choisir un jour',
                'choices' => [
                    'Mardi' => 2,
                    'Mercredi' => 3,
                    'Jeudi' => 4,
                    'Vendredi' => 5,
                    'Samedi' => 6,
                    'Dimanche' => 7,
                    'Lundi' => 1,
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Range(
                        min: 1,
                        max: 7,
                    ),
                ],
                'help' => 'Affichage en semaine radio : mardi → lundi.',
            ])

            ->add('startTime', TimeType::class, [
                'label' => 'Heure de début',
                'input' => 'datetime_immutable',
                'widget' => 'choice',
                'with_seconds' => false,
                'minutes' => [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55],
                'constraints' => [
                    new Assert\NotBlank(),
                ],
            ])

            ->add('durationMinutes', IntegerType::class, [
                'label' => 'Durée (minutes)',
                'attr' => [
                    'min' => 1,
                ],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                ],
            ])

            ->add('broadcastRank', ChoiceType::class, [
                'label' => 'Ordre de diffusion',
                'placeholder' => false,
                'choices' => [
                    '1re diffusion' => 1,
                    '1re rediffusion' => 2,
                    '2e rediffusion' => 3,
                    '3e rediffusion' => 4,
                ],
                'help' => 'Choisis s’il s’agit de la diffusion principale ou d’une rediffusion.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(),
                    new Assert\Choice(
                        choices: [1, 2, 3, 4],
                    ),
                ],
            ])

            ->add('weekOffset', ChoiceType::class, [
                'label' => 'Décalage en semaines radio',
                'placeholder' => false,
                'choices' => [
                    'Même semaine radio' => 0,
                    'Semaine radio suivante' => 1,
                    '2 semaines radio plus tard' => 2,
                    '3 semaines radio plus tard' => 3,
                    '4 semaines radio plus tard' => 4,
                ],
                'help' => 'Choisis à combien de semaines radio de distance cette diffusion doit être placée.',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Choice(
                        choices: [0, 1, 2, 3, 4],
                    ),
                ],
            ])

            ->add('weekParity', ChoiceType::class, [
                'label' => 'Rythme hebdomadaire',
                'required' => false,
                'placeholder' => 'Toutes les semaines',
                'choices' => [
                    'Semaines paires' => ProgrammationRuleSlot::WEEK_PARITY_EVEN,
                    'Semaines impaires' => ProgrammationRuleSlot::WEEK_PARITY_ODD,
                ],
                'help' => 'Laisser vide pour une diffusion toutes les semaines.',
                'constraints' => [
                    new Assert\Choice(
                        choices: [
                            ProgrammationRuleSlot::WEEK_PARITY_EVEN,
                            ProgrammationRuleSlot::WEEK_PARITY_ODD,
                            null,
                        ],
                    ),
                ],
            ])

            ->add('isActive', CheckboxType::class, [
                'label' => 'Actif',
                'required' => false,
            ])

            ->add('Sauvegarder', SubmitType::class, [
                'label' => 'Sauvegarder',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProgrammationRuleSlot::class,
        ]);
    }
}
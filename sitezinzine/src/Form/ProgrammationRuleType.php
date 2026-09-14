<?php

namespace App\Form;

use SortDirection;

use App\Entity\Categories;
use App\Entity\ProgrammationRule;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProgrammationRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var ProgrammationRule|null $rule */
        $rule = $options['data'] ?? null;
        $isEdit = $rule instanceof ProgrammationRule && $rule->getId() !== null;

        $builder
            ->add('category', EntityType::class, [
                'class' => Categories::class,
                'choice_label' => 'titre',
                'label' => 'Catégorie',
                'placeholder' => 'Choisir une catégorie',
                'required' => true,
                'disabled' => $isEdit,
                'query_builder' => function ($repository) {
                    return $repository->createQueryBuilder('c')
                        ->andWhere('c.softDelete = :softDelete')
                        ->andWhere('c.active = :active')
                        ->setParameter('softDelete', false)
                        ->setParameter('active', true)
                        ->orderBy('c.titre', SortDirection::Ascending);
                },
            ])
            ->add('validFrom', DateType::class, [
                'label' => 'Valide à partir du',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('validUntil', DateType::class, [
                'label' => 'Valide jusqu’au',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Règle active',
                'required' => false,
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Sauvegarder',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProgrammationRule::class,
        ]);
    }
}
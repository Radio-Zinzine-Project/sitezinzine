<?php

namespace App\Form;

use App\Entity\CategorieTagImage;
use App\Entity\Categories;
use App\Repository\CategoriesRepository;
use SortDirection;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class CategorieTagImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('categorie', EntityType::class, [
                'class' => Categories::class,
                'choice_label' => 'titre',
                'placeholder' => 'Choisir une catégorie',
                'query_builder' => function (CategoriesRepository $repo) {
                    return $repo->createQueryBuilder('c')
                        ->orderBy('c.titre', SortDirection::Ascending);
                },
                'choice_attr' => function ($categorie) {
                    if (!$categorie->isActive()) {
                        return [
                            'style' => 'color:#999;',
                        ];
                    }

                    return [];
                },
            ])
            ->add('annee', IntegerType::class, [
                'attr' => [
                    'min' => 1980,
                    'max' => 2100,
                ],
            ])
            ->add('imageFile', FileType::class, [
                'required' => false,
                'mapped' => true,
                'constraints' => [
                    new File(
                        maxSize: '2M',
                        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
                        mimeTypesMessage: 'Formats autorisés : JPG, PNG, WEBP.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategorieTagImage::class,
        ]);
    }
}
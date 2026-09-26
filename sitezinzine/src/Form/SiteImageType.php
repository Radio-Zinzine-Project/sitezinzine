<?php

namespace App\Form;

use App\Entity\SiteImage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SiteImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var SiteImage|null $siteImage */
        $siteImage = $options['data'];

        $isEdit = null !== $siteImage?->getId();

        $builder
            ->add('key', TextType::class, [
                'label' => 'Clé technique',
                'disabled' => $isEdit,
                'help' => $isEdit
                    ? 'La clé technique ne peut pas être modifiée après la création.'
                    : 'Identifiant technique unique, par exemple : playlist_day.',
                'attr' => [
                    'placeholder' => 'playlist_day',
                ],
            ])

            ->add('label', TextType::class, [
                'label' => 'Nom',
                'attr' => [
                    'placeholder' => 'Playlist de jour',
                ],
            ])

            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'help' => 'Indique où cette image est utilisée sur le site.',
                'attr' => [
                    'rows' => 4,
                ],
            ])

            ->add('thumbnailFile', FileType::class, [
                'label' => $isEdit ? 'Remplacer l’image' : 'Image',
                'required' => false,
                'mapped' => true,
                'help' => 'Formats autorisés : JPG, PNG, WEBP. Taille maximale : 2 Mo.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SiteImage::class,
        ]);
    }
}
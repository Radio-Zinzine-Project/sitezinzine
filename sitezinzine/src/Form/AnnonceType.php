<?php

namespace App\Form;

use App\Entity\Annonce;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AnnonceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Values stables (ne pas changer la casse)
        $typeChoices = [
            'Concert' => 'Concert',
            'Spectacle' => 'Spectacle',
            'Exposition' => 'Exposition',
            'Festival' => 'Festival',
            'Cinéma' => 'Cinéma',
            'Randonnée' => 'Randonnée',
            'Conférence - Débat' => 'Conférence - Débat',
            'Stage - Cours - Atelier' => 'Stage - Cours - Atelier',
            'Rassemblement - Manifestation' => 'Rassemblement - Manifestation',
            'Autre' => '__autre__',
        ];

        $departements = [
            'Alpes-de-Haute-Provence' => '04',
            'Hautes-Alpes' => '05',
            'Alpes-Maritimes' => '06',
            'Bouches-du-Rhône' => '13',
            'Var' => '83',
            'Vaucluse' => '84',
        ];

        /** @var Annonce|null $annonce */
        $annonce = $options['data'] ?? null;

        $existingType = $annonce?->getType();
        $existingType = is_string($existingType) ? trim($existingType) : null;

        // Si le type existant n'est pas dans les values, on passe en "Autre" + préremplissage
        $knownValues = array_values($typeChoices);
        $isCustomType = $existingType && !in_array($existingType, $knownValues, true);

        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'attr' => ['maxlength' => 100],
            ])
            ->add('organisateur', TextType::class, [
                'label' => 'Organisateur',
                'attr' => ['maxlength' => 100],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => $typeChoices,
                'placeholder' => "Sélectionnez un type d'évènement",
                'data' => $isCustomType ? '__autre__' : ($existingType ?: null),
                'required' => false,
            ])
            ->add('autreType', TextType::class, [
                'label' => 'Autre type',
                'required' => false,
                'mapped' => false,
                'data' => $isCustomType ? $existingType : '',
                'attr' => ['maxlength' => 50],
            ])
            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'attr' => ['maxlength' => 50],
            ])
            ->add('departement', ChoiceType::class, [
                'label' => 'Département',
                'choices' => $departements,
                'placeholder' => 'Sélectionnez un département',
                'required' => false,
            ])
            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'attr' => ['maxlength' => 50],
            ])
            ->add('dateDebut', DateTimeType::class, [
                'input' => 'datetime',
                'label' => 'Date de début',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'yyyy-MM-dd',
                'attr' => ['data-controller' => 'flatpickr'],
                'required' => false,
            ])
            ->add('dateFin', DateTimeType::class, [
                'input' => 'datetime',
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'yyyy-MM-dd',
                'attr' => ['data-controller' => 'flatpickr'],
                'required' => false,
            ])
            ->add('horaire', TextType::class, [
                'label' => 'Horaires',
                'attr' => ['maxlength' => 50],
                'required' => false,
            ])
            ->add('prix', TextType::class, [
                'label' => 'Prix',
                'attr' => ['maxlength' => 50],
                'required' => false,
            ])
            ->add('presentation', TextareaType::class, [
                'label' => 'Présentation',
                'required' => false,
                'empty_data' => '',
                // pas de "hidden-textarea" ici : si tu veux masquer, fais-le côté Twig/CSS
            ])
            ->add('contact', TextType::class, [
                'label' => 'Contact',
                'attr' => ['maxlength' => 100],
                'required' => false,
            ])
            ->add('thumbnailFile', FileType::class, [
                'required' => false,
                'label' => 'Ajouter une image :',
            ]);

        if ($options['show_valid']) {
            $builder->add('valid', CheckboxType::class, [
                'label' => 'Valide',
                'required' => false,
            ]);
        }

        // ✅ IMPORTANT : pas de SubmitType ici
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Annonce::class,
            'show_valid' => false,
        ]);
    }
}

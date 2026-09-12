<?php

namespace App\Form;

use App\Entity\Evenement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EvenementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Evenement|null $evenement */
        $evenement = $options['data'] ?? null;

        $type = $evenement?->getType();
        $existingType = $type !== null ? trim($type) : null;

        $choices = [
            'Emission' => 'Emission',
            'Fete' => 'Fete',
            'Studio Mobile' => 'Studio Mobile',
            'Table Ronde' => 'Table Ronde',
            'Rassemblement - Manifestation' => 'Rassemblement - Manifestation',
            'Autre' => 'autre',
        ];

        /*
         * Permet de reconnaître les types connus même si les anciennes
         * données ne respectent pas exactement la casse utilisée aujourd'hui.
         */
        $knownTypes = [
            'emission' => 'Emission',
            'fete' => 'Fete',
            'studio mobile' => 'Studio Mobile',
            'table ronde' => 'Table Ronde',
            'rassemblement - manifestation' => 'Rassemblement - Manifestation',
        ];

        if ($existingType !== null && $existingType !== '') {
            $normalizedKey = strtolower($existingType);

            if (isset($knownTypes[$normalizedKey])) {
                $existingType = $knownTypes[$normalizedKey];
            }
        }

        $autreTypeValue = '';
        $typeValue = $existingType;

        /*
         * Si le type enregistré n'appartient pas aux types connus,
         * le select affiche "Autre" et le champ autreType reprend
         * la valeur enregistrée.
         */
        if (
            $existingType !== null
            && $existingType !== ''
            && !in_array($existingType, $choices, true)
        ) {
            $typeValue = 'autre';
            $autreTypeValue = $type ?? '';
        }

        $departements = [
            'Alpes-de-Haute-Provence' => '04',
            'Alpes-Maritimes' => '06',
            'Bouches-du-Rhône' => '13',
            'Hautes-Alpes' => '05',
            'Var' => '83',
            'Vaucluse' => '84',
        ];

        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'attr' => [
                    'maxlength' => 100,
                ],
            ])

            ->add('organisateur', TextType::class, [
                'label' => 'Organisateur',
                'required' => false,
                'attr' => [
                    'maxlength' => 100,
                ],
            ])

            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => [
                    'maxlength' => 50,
                ],
            ])

            ->add('departement', ChoiceType::class, [
                'label' => 'Département',
                'required' => false,
                'choices' => $departements,
                'placeholder' => 'Sélectionnez un département',
                'data' => $evenement?->getDepartement() ?? '',
            ])

            ->add('adresse', TextType::class, [
                'required' => false,
                'label' => 'Adresse',
                'attr' => [
                    'maxlength' => 50,
                ],
            ])

            ->add('dateDebut', DateTimeType::class, [
                'input' => 'datetime',
                'label' => 'Date de début',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'yyyy-MM-dd',
                'attr' => [
                    'data-controller' => 'flatpickr',
                ],
            ])

            ->add('dateFin', DateTimeType::class, [
                'input' => 'datetime',
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'yyyy-MM-dd',
                'attr' => [
                    'data-controller' => 'flatpickr',
                ],
            ])

            ->add('horaire', TextType::class, [
                'required' => false,
                'label' => 'Horaires',
                'attr' => [
                    'maxlength' => 50,
                ],
            ])

            ->add('prix', TextType::class, [
                'required' => false,
                'label' => 'Prix',
                'attr' => [
                    'maxlength' => 50,
                ],
            ])

            ->add('presentation', TextareaType::class, [
                'label' => 'Présentation',
                'empty_data' => '',
                'required' => false,
                'attr' => [
                    'class' => 'hidden-textarea',
                ],
            ])

            ->add('contact', TextType::class, [
                'required' => false,
                'label' => 'Contact',
                'attr' => [
                    'maxlength' => 100,
                ],
            ])

            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => $choices,
                'placeholder' => 'Sélectionnez un type d\'évènement',
                'data' => $typeValue,
                'choice_label' => fn($choice, $key, $value) => $key,
                'choice_value' => fn($choice) => $choice !== null
                    ? strtolower($choice)
                    : null,
                'attr' => [
                    'maxlength' => 50,
                ],
            ])

            ->add('autreType', TextType::class, [
                'label' => 'Autre type',
                'required' => false,
                'mapped' => false,
                'data' => $autreTypeValue,
                'constraints' => [
                    new Assert\Length(
                        max: 50,
                        maxMessage: 'Le type ne doit pas dépasser {{ limit }} caractères.'
                    ),
                ],
                'attr' => [
                    'style' => $autreTypeValue !== ''
                        ? 'display:block;'
                        : 'display:none;',
                    'maxlength' => 50,
                ],
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

        $builder->add('Sauvegarder', SubmitType::class);

        /*
         * autreType est volontairement unmapped.
         *
         * Lors de la soumission, si "Autre" est sélectionné,
         * sa valeur devient le véritable type de l'évènement.
         *
         * Cette logique étant dans le FormType, elle fonctionne
         * aussi bien en création qu'en édition.
         */
        $builder->addEventListener(
            FormEvents::SUBMIT,
            function (FormEvent $event): void {
                $evenement = $event->getData();
                $form = $event->getForm();

                if (!$evenement instanceof Evenement) {
                    return;
                }

                if ($evenement->getType() !== 'autre') {
                    return;
                }

                $autreType = $form->get('autreType')->getData();

                if (!is_string($autreType)) {
                    return;
                }

                $autreType = trim($autreType);

                if ($autreType === '') {
                    return;
                }

                $evenement->setType($autreType);
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Evenement::class,
            'show_valid' => false,
        ]);
    }
}

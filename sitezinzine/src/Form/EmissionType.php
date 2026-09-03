<?php

namespace App\Form;

use App\Entity\Categories;
use App\Entity\InviteOldAnimateur;
use App\Entity\Emission;
use App\Entity\Theme;
use App\Entity\Editeur;
use App\Entity\User;
use App\Repository\CategoriesRepository;
use App\Repository\ThemeRepository;
use App\Repository\InviteOldAnimateurRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmissionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('categorie', EntityType::class, [
                'class' => Categories::class,
                'required' => false,
                'placeholder' => 'Sélectionnez une catégorie',
                'choice_label' => function (Categories $categorie) {
                    $label = $categorie->getTitre();

                    if (!$categorie->isActive()) {
                        $label .= ' (inactive)';
                    }

                    if ($categorie->isSoftDelete()) {
                        $label .= ' (supprimée)';
                    }

                    return $label;
                },
                'label' => 'Catégorie',
                'query_builder' => function (CategoriesRepository $er) use ($options): QueryBuilder {
                    /** @var Emission|null $emission */
                    $emission = $options['data'] ?? null;
                    $currentCategorie = $emission?->getCategorie();

                    $qb = $er->createQueryBuilder('c');

                    if ($currentCategorie !== null) {
                        $qb
                            ->where('(c.active = true AND c.softDelete = false) OR c.id = :currentId')
                            ->setParameter('currentId', $currentCategorie->getId());
                    } else {
                        $qb->where('c.active = true AND c.softDelete = false');
                    }

                    return $qb->orderBy('c.titre', 'ASC');
                },
                'choice_attr' => static function (Categories $categorie): array {
                    return [
                        'data-editeur-id' => $categorie->getEditeur() ?? '',
                        'data-duree' => $categorie->getDuree() ?? '',
                    ];
                },
                'attr' => [
                    'data-emission-form-target' => 'categorie',
                    'data-action' => 'change->emission-form#syncCategoryDefaults',
                ],
            ])
            ->add('theme', EntityType::class, [
                'class' => Theme::class,
                'placeholder' => 'Sélectionnez un thème  (obligatoire)',
                'choice_label' => 'name',
                'label' => 'Thème',
                'query_builder' => function (ThemeRepository $ert): QueryBuilder {
                    return $ert->createQueryBuilder('v')
                        ->orderBy('v.name', 'ASC');
                },
            ])
            ->add('editeur', EntityType::class, [
                'class' => Editeur::class,
                'choice_label' => 'name',
                'label' => 'Éditeur',
                'required' => false,
                'placeholder' => 'Sélectionnez un éditeur',
                'attr' => [
                    'data-emission-form-target' => 'editeur',
                ],
            ])
            ->add('invites', EntityType::class, [
                'class' => InviteOldAnimateur::class,
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'label' => 'Invité·es',
                'choice_label' => fn(InviteOldAnimateur $a) => (string) $a,
                'query_builder' => fn(InviteOldAnimateurRepository $er): QueryBuilder
                => $er->createQueryBuilder('i')
                    ->andWhere('i.ancienanimateur = 0 OR i.ancienanimateur IS NULL')
                    ->orderBy('i.lastName', 'ASC'),
            ])
            ->add('inviteOldAnimateurs', EntityType::class, [
                'class' => InviteOldAnimateur::class,
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'label' => 'Ancien·nes animateur·ices',
                'choice_label' => fn(InviteOldAnimateur $a) => (string) $a,
                'query_builder' => fn(InviteOldAnimateurRepository $er): QueryBuilder
                => $er->createQueryBuilder('i')
                    ->andWhere('i.ancienanimateur = 1')
                    ->orderBy('i.firstName', 'ASC')
                    ->addOrderBy('i.lastName', 'ASC'),
            ])
            ->add('titre', TextType::class, [
                'label' => 'Titre de l\'émission  (obligatoire)',
            ])
            ->add('keyword', TextType::class, [
                'required' => false,
                'label' => 'Mot(s) clé(s)',
            ])
            ->add('ref', TextType::class, [
                'label' => 'Créateur/trice',
                'help' => 'À terme remplacé par “Utilisateur·ices”. Pour l’instant, laisse ce champ le temps de corriger les données.',
                'required' => false,
            ])
            ->add('users', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'username',
                'label' => 'Utilisateur·ices',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'query_builder' => fn(UserRepository $ur): QueryBuilder
                => $ur->createQueryBuilder('u')->orderBy('u.username', 'ASC'),
            ])
            ->add('duree', IntegerType::class, [
                'label' => 'Durée (obligatoire)',
                'attr' => [
                    'data-emission-form-target' => 'duree',
                ],
            ])
            ->add('isLive', CheckboxType::class, [
                'label' => 'Émission en direct',
                'required' => false,
            ])
            ->add('url', UrlType::class, [
                'required' => false,
                'default_protocol' => 'http',
                'label' => 'Url de l\'émission',
                'empty_data' => '',
            ])
            ->add('descriptif', TextareaType::class, [
                'empty_data' => 'Description à remplir',
                'label' => 'Descriptif (obligatoire)',
                'required' => false,
            ])
            ->add('thumbnailFile', FileType::class, [
                'required' => false,
                'label' => 'Ajouter une image :',
                'upload_max_size_message' => fn() => 'Fichier trop lourd. Taille max : {{ limit }} {{ suffix }}.',
            ]);

        if ($options['with_mp3']) {
            $builder
                ->add('thumbnailFileMp3', FileType::class, [
                    'required' => false,
                    'label' => 'Ajouter un Mp3 :',
                ])
                ->add('deleteMp3', CheckboxType::class, [
                    'required' => false,
                    'mapped' => false,
                    'label' => 'Supprimer le fichier MP3 actuel',
                ]);
        }

        if ($options['data'] instanceof Emission && $options['data']->isPendingCompletion()) {
            $builder->add('markAsCompleted', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Cette fiche est finalisée',
            ]);
        }

        $builder->add('Sauvegarder', SubmitType::class);

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
            $emission = $event->getData();
            $form = $event->getForm();

            if (!$emission instanceof Emission) {
                return;
            }

            $invites = [];
            $anciens = [];

            foreach ($emission->getInviteOldAnimateurs() as $person) {
                if ($person->isAncienanimateur()) {
                    $anciens[] = $person;
                } else {
                    $invites[] = $person;
                }
            }

            $form->get('invites')->setData($invites);
            $form->get('inviteOldAnimateurs')->setData($anciens);
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $emission = $event->getData();
            $form = $event->getForm();

            if (!$emission instanceof Emission) {
                return;
            }

            foreach ($emission->getInviteOldAnimateurs()->toArray() as $person) {
                $emission->removeInviteOldAnimateur($person);
            }

            $invites = $form->get('invites')->getData() ?? [];
            $anciens = $form->get('inviteOldAnimateurs')->getData() ?? [];

            foreach ($invites as $p) {
                $emission->addInviteOldAnimateur($p);
            }

            foreach ($anciens as $p) {
                $emission->addInviteOldAnimateur($p);
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->autoKeyword(...));
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($options) {
            $data = $event->getData();

            if (empty($data['ref']) && !empty($options['current_user_identifier'])) {
                $data['ref'] = $options['current_user_identifier'];
                $event->setData($data);
            }
        });
    }

    public function autoKeyword(PreSubmitEvent $event): void
    {
        $data = $event->getData();

        if (empty($data['keyword'])) {
            $data['keyword'] = 'Keyword';
            $event->setData($data);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Emission::class,
            'allow_extra_fields' => true,
            'current_user_identifier' => null,
            'with_mp3' => false,
        ]);

        $resolver->setAllowedTypes('with_mp3', 'bool');
    }
}

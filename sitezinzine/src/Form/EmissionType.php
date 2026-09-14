<?php

namespace App\Form;

use SortDirection;

use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\InviteOldAnimateur;
use App\Entity\Theme;
use App\Entity\User;
use App\Repository\CategoriesRepository;
use App\Repository\InviteOldAnimateurRepository;
use App\Repository\ThemeRepository;
use App\Service\EmissionUserChoicesProvider;
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
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmissionType extends AbstractType
{
    public function __construct(
        private readonly CategoriesRepository $categoriesRepository,
        private readonly EmissionUserChoicesProvider $userChoicesProvider,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('categorie', EntityType::class, [
                'class' => Categories::class,
                'required' => false,
                'placeholder' => 'Sélectionnez une catégorie',
                'choice_label' => function (Categories $categorie): string {
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
                'query_builder' => function (CategoriesRepository $repository) use ($options): QueryBuilder {
                    /** @var Emission|null $emission */
                    $emission = $options['data'] ?? null;

                    /** @var User|null $currentUser */
                    $currentUser = $options['current_user'];

                    $canManageAllCategories = $options['can_manage_all_categories'];
                    $currentCategorie = $emission?->getCategorie();

                    $qb = $repository->createQueryBuilder('c');

                    if ($canManageAllCategories) {
                        if ($currentCategorie !== null) {
                            $qb
                                ->where(
                                    '(c.active = true AND c.softDelete = false)
                                     OR c.id = :currentId'
                                )
                                ->setParameter(
                                    'currentId',
                                    $currentCategorie->getId()
                                );
                        } else {
                            $qb
                                ->where('c.active = true')
                                ->andWhere('c.softDelete = false');
                        }

                        return $qb->orderBy(
                            'c.titre',
                            SortDirection::Ascending
                        );
                    }

                    if ($currentUser instanceof User) {
                        $qb
                            ->leftJoin('c.users', 'category_user')
                            ->distinct();

                        if ($currentCategorie !== null) {
                            $qb
                                ->where(
                                    '(c.active = true
                                      AND c.softDelete = false
                                      AND category_user = :currentUser)
                                     OR c.id = :currentId'
                                )
                                ->setParameter(
                                    'currentUser',
                                    $currentUser
                                )
                                ->setParameter(
                                    'currentId',
                                    $currentCategorie->getId()
                                );
                        } else {
                            $qb
                                ->where('c.active = true')
                                ->andWhere('c.softDelete = false')
                                ->andWhere('category_user = :currentUser')
                                ->setParameter(
                                    'currentUser',
                                    $currentUser
                                );
                        }

                        return $qb->orderBy(
                            'c.titre',
                            SortDirection::Ascending
                        );
                    }

                    return $qb
                        ->andWhere('1 = 0')
                        ->orderBy(
                            'c.titre',
                            SortDirection::Ascending
                        );
                },
                'choice_attr' => static function (Categories $categorie): array {
                    return [
                        'data-editeur-id' => $categorie->getEditeur()?->getId() ?? '',
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
                'query_builder' => function (ThemeRepository $repository): QueryBuilder {
                    return $repository
                        ->createQueryBuilder('v')
                        ->orderBy(
                            'v.name',
                            SortDirection::Ascending
                        );
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
                'choice_label' => fn (InviteOldAnimateur $animateur): string => (string) $animateur,
                'query_builder' => fn (InviteOldAnimateurRepository $repository): QueryBuilder
                    => $repository
                        ->createQueryBuilder('i')
                        ->andWhere(
                            'i.ancienanimateur = 0 OR i.ancienanimateur IS NULL'
                        )
                        ->orderBy(
                            'i.lastName',
                            SortDirection::Ascending
                        ),
            ])
            ->add('inviteOldAnimateurs', EntityType::class, [
                'class' => InviteOldAnimateur::class,
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'label' => 'Ancien·nes animateur·ices',
                'choice_label' => fn (InviteOldAnimateur $animateur): string => (string) $animateur,
                'query_builder' => fn (InviteOldAnimateurRepository $repository): QueryBuilder
                    => $repository
                        ->createQueryBuilder('i')
                        ->andWhere('i.ancienanimateur = 1')
                        ->orderBy(
                            'i.firstName',
                            SortDirection::Ascending
                        )
                        ->addOrderBy(
                            'i.lastName',
                            SortDirection::Ascending
                        ),
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
                'upload_max_size_message' => fn () => 'Fichier trop lourd. Taille max : {{ limit }} {{ suffix }}.',
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

        if (
            $options['data'] instanceof Emission
            && $options['data']->isPendingCompletion()
        ) {
            $builder->add('markAsCompleted', CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Cette fiche est finalisée',
            ]);
        }

        /*
         * Construction initiale du champ users.
         */
        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            function (FormEvent $event) use ($options): void {
                $emission = $event->getData();

                if (!$emission instanceof Emission) {
                    return;
                }

                $this->addUsersField(
                    $event->getForm(),
                    $emission,
                    $emission->getCategorie(),
                    $options['can_manage_all_users']
                );
            }
        );

        /*
         * Séparation de la relation InviteOldAnimateur dans les
         * deux champs non mappés du formulaire.
         */
        $builder->addEventListener(
            FormEvents::POST_SET_DATA,
            function (FormEvent $event): void {
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
            }
        );

        /*
         * Reconstruction des choix users selon la catégorie réellement
         * soumise, avant le mapping Symfony.
         *
         * Cette étape est également la protection serveur contre
         * l'envoi manuel d'un user qui n'est pas autorisé.
         */
        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (FormEvent $event) use ($options): void {
                $submittedData = $event->getData();
                $emission = $event->getForm()->getData();

                if (
                    !$emission instanceof Emission
                    || !is_array($submittedData)
                ) {
                    return;
                }

                $categorie = null;
                $categorieId = $submittedData['categorie'] ?? null;

                if (
                    is_scalar($categorieId)
                    && (string) $categorieId !== ''
                ) {
                    $categorie = $this->categoriesRepository->find(
                        $categorieId
                    );
                }

                $this->addUsersField(
                    $event->getForm(),
                    $emission,
                    $categorie,
                    $options['can_manage_all_users']
                );
            }
        );

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            $this->autoKeyword(...)
        );

        /*
         * Reconstitution de la collection InviteOldAnimateur
         * et attribution des utilisateurs par défaut lors d'une création.
         */
        $builder->addEventListener(
            FormEvents::SUBMIT,
            function (FormEvent $event) use ($options): void {
                $emission = $event->getData();
                $form = $event->getForm();

                if (!$emission instanceof Emission) {
                    return;
                }

                foreach (
                    $emission->getInviteOldAnimateurs()->toArray()
                    as $person
                ) {
                    $emission->removeInviteOldAnimateur($person);
                }

                $invites = $form->get('invites')->getData() ?? [];
                $anciens = $form
                    ->get('inviteOldAnimateurs')
                    ->getData() ?? [];

                foreach ($invites as $person) {
                    if ($person instanceof InviteOldAnimateur) {
                        $emission->addInviteOldAnimateur($person);
                    }
                }

                foreach ($anciens as $person) {
                    if ($person instanceof InviteOldAnimateur) {
                        $emission->addInviteOldAnimateur($person);
                    }
                }

                /*
                 * Aucun fallback en édition.
                 *
                 * À la création :
                 *
                 * - ADMIN / SUPER_ADMIN :
                 *   si aucun utilisateur n'a été choisi explicitement,
                 *   tous les utilisateurs actuellement associés à la
                 *   catégorie sont ajoutés à l'émission.
                 *
                 * - USER / EDITOR :
                 *   si aucun utilisateur n'a été choisi explicitement,
                 *   l'utilisateur connecté est ajouté uniquement s'il
                 *   appartient actuellement à la catégorie.
                 */
                $currentUser = $options['current_user'];
                $categorie = $emission->getCategorie();
                $isCreation = $emission->getId() === null;

                if (
                    $isCreation
                    && $categorie instanceof Categories
                    && $emission->getUsers()->isEmpty()
                ) {
                    if ($options['can_manage_all_categories']) {
                        foreach ($categorie->getUsers() as $categoryUser) {
                            if ($categoryUser instanceof User) {
                                $emission->addUser($categoryUser);
                            }
                        }
                    } elseif (
                        $currentUser instanceof User
                        && $this->userChoicesProvider->isCurrentCategoryUser(
                            $currentUser,
                            $categorie
                        )
                    ) {
                        $emission->addUser($currentUser);
                    }
                }
            }
        );

        $builder->add('Sauvegarder', SubmitType::class);
    }

    private function addUsersField(
        FormBuilderInterface|FormInterface $form,
        Emission $emission,
        ?Categories $categorie,
        bool $canManageAllUsers
    ): void {
        $choices = $this->userChoicesProvider->getChoices(
            $categorie,
            $emission,
            $canManageAllUsers
        );

        $form->add('users', EntityType::class, [
            'class' => User::class,
            'choices' => $choices,
            'choice_label' => fn (User $user): string
                => $this->userChoicesProvider->getLabel(
                    $user,
                    $categorie,
                    $emission
                ),
            'group_by' => fn (User $user): string
                => $this->userChoicesProvider->getGroupLabel(
                    $user,
                    $categorie,
                    $emission
                ),
            'choice_attr' => fn (User $user): array => [
                'data-association-status'
                    => $this->userChoicesProvider->getStatus(
                        $user,
                        $categorie,
                        $emission
                    ),
            ],
            'label' => 'Utilisateur·ices',
            'required' => false,
            'multiple' => true,
            'expanded' => false,
            'attr' => [
                'data-emission-form-target' => 'users',
            ],
        ]);
    }

    public function autoKeyword(PreSubmitEvent $event): void
    {
        $data = $event->getData();

        if (!is_array($data)) {
            return;
        }

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
            'current_user' => null,
            'can_manage_all_categories' => false,
            'can_manage_all_users' => false,
            'with_mp3' => false,
        ]);

        $resolver->setAllowedTypes(
            'current_user',
            [User::class, 'null']
        );

        $resolver->setAllowedTypes(
            'can_manage_all_categories',
            'bool'
        );

        $resolver->setAllowedTypes(
            'can_manage_all_users',
            'bool'
        );

        $resolver->setAllowedTypes(
            'with_mp3',
            'bool'
        );
    }
}
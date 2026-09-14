<?php

namespace App\Form;

use SortDirection;

use App\Entity\Categories;
use App\Entity\User;
use App\Entity\InviteOldAnimateur;
use App\Repository\CategoriesRepository;
use App\Repository\InviteOldAnimateurRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use App\Entity\Editeur;
use App\Repository\EditeurRepository;

class CategorieType extends AbstractType
{
    public function __construct(
        private CategoriesRepository $categoriesRepository,
        private Security $security,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $editeursRaw = $this->categoriesRepository->findDistinctEditeursWithNames();

        $editeursChoices = [];
        foreach ($editeursRaw as $row) {
            $editeursChoices[$row['name']] = (int) $row['id'];
        }
        /** @var Categories|null $categorie */
        $categorie = $builder->getData();
        $isEdit = $categorie && $categorie->getId() !== null;

        $isSuperAdmin = $this->security->isGranted('ROLE_SUPER_ADMIN');

        // En create: tout le monde peut saisir le slug
        // En edit: seul super admin peut modifier
        $slugDisabled = $isEdit && !$isSuperAdmin;

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) {
            $data = $event->getData() ?? [];

            // si l'utilisateur ne sélectionne rien, le navigateur n'envoie pas la clé
            $data['users'] = $data['users'] ?? [];
            $data['inviteOldAnimateurs'] = $data['inviteOldAnimateurs'] ?? [];

            $event->setData($data);
        });

        $builder
            ->add('titre', TextType::class, [
                'empty_data' => 'Nouvelle catégorie',
                'label' => 'Titre de la catégorie',
            ])

            ->add('slug', TextType::class, [
                'required' => false, // BDD nullable pour l’instant
                'label' => 'Code catégorie (3 lettres)',
                'disabled' => $slugDisabled,
                'attr' => [
                    'maxlength' => 3,
                    'style' => 'text-transform: uppercase;',
                    'autocomplete' => 'off',
                ],
                'help' => 'Ex: SOC, ECO, POL… Utilisé pour ranger les MP3 : /uploads/mp3/<CODE>/<YYYY>/<MM>/',
            ])

            ->add('editeur', EntityType::class, [
                'class' => Editeur::class,
                'label' => 'Éditeur',
                'placeholder' => 'Choisir un éditeur',
                'required' => true,
                'choice_label' => 'name',
                'query_builder' => fn(EditeurRepository $er) => $er
                    ->createQueryBuilder('e')
                    ->orderBy('e.name', SortDirection::Ascending),
            ])

            ->add('duree', IntegerType::class, [
                'label' => 'Durée'
            ])

            ->add('descriptif', TextareaType::class, [
                'required' => true,
                'label' => 'Descriptif',
                'empty_data' => '',
                'attr' => [
                    'class' => 'form-control tinymce',
                    'rows' => 10,
                ],
            ])

            // ✅ Users (ManyToMany)
            ->add('users', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'username',
                'multiple' => true,
                'required' => false,
                'error_bubbling' => false,
                'label' => 'Utilisateur·ices (comptes)',
            ])

            // ✅ Anciens animateurs
            ->add('inviteOldAnimateurs', EntityType::class, [
                'class' => InviteOldAnimateur::class,
                'multiple' => true,
                'required' => false,
                'error_bubbling' => false,
                'label' => 'Ancien·nes animateur·ices',
                'choice_label' => fn(InviteOldAnimateur $a) => (string) $a,
                'query_builder' => fn(InviteOldAnimateurRepository $repo): QueryBuilder
                => $repo->createQueryBuilder('a')
                    ->andWhere('a.ancienanimateur = 1')
                    ->orderBy('a.lastName', SortDirection::Ascending),
            ])


            ->add('thumbnailFile', FileType::class, [
                'required' => false,
                'label' => 'Ajouter une image :'
            ])

            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => 'Cocher si la catégorie est active'
            ])

            ->add('Sauvegarder', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Categories::class,
            'validation_groups' => ['Default', 'admin'],
        ]);
    }
}

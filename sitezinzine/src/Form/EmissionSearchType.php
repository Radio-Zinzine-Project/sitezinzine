<?php

namespace App\Form;

use SortDirection;

use App\Entity\Categories;
use App\Entity\Theme;
use App\Repository\CategoriesRepository;
use App\Repository\ThemeRepository;
use App\Repository\UserRepository;
use App\Repository\InviteOldAnimateurRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmissionSearchType extends AbstractType
{
    public function __construct(
        private UserRepository $userRepository,
        private InviteOldAnimateurRepository $inviteOldAnimateurRepository
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = $this->buildPeopleChoices();

        $builder
            ->add('titre', TextType::class, [
                'required' => false,
                'label' => 'Rechercher par mot',
                'attr' => [
                    'placeholder' => 'Rechercher par mot'
                ]
            ])

            ->add('dateDebut', DateTimeType::class, [
                'input' => 'datetime',
                'required' => false,
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
                'required' => false,
                'label' => 'Date de fin',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'yyyy-MM-dd',
                'attr' => [
                    'data-controller' => 'flatpickr',
                ],
            ])

            ->add('categorie', EntityType::class, [
                'class' => Categories::class,
                'required' => false,
                'placeholder' => 'Sélectionnez une catégorie',
                'choice_label' => 'titre',
                'label' => 'Catégorie',
                'query_builder' => fn (CategoriesRepository $er): QueryBuilder =>
                    $er->createQueryBuilder('c')->orderBy('c.titre', SortDirection::Ascending),
            ])

            ->add('theme', EntityType::class, [
                'class' => Theme::class,
                'required' => false,
                'placeholder' => 'Sélectionnez un thème',
                'choice_label' => 'name',
                'label' => 'Thème',
                'query_builder' => fn (ThemeRepository $er): QueryBuilder =>
                    $er->createQueryBuilder('t')->orderBy('t.name', SortDirection::Ascending),
            ])

            ->add('personne', ChoiceType::class, [
                'required' => false,
                'label' => 'Animateurice',
                'placeholder' => 'Sélectionnez une personne',
                'choices' => $choices,

                // 👇 important pour le CSS
                'choice_attr' => function ($value, $key, $index) {
                    return str_starts_with($value, 'old:')
                        ? ['class' => 'old-animateur']
                        : ['class' => 'user'];
                }
            ]);
    }

    private function buildPeopleChoices(): array
    {
        $choices = [];

        // USERS
        $users = $this->userRepository->createQueryBuilder('u')
            ->orderBy('u.username', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        foreach ($users as $user) {
            $choices[$user->getDisplayName()] = 'user:' . $user->getId();
        }

        // ANCIENS ANIMATEURS
        $olds = $this->inviteOldAnimateurRepository->createQueryBuilder('o')
            ->orderBy('o.firstName', SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        foreach ($olds as $old) {
            $choices[$old->getFirstName()] = 'old:' . $old->getId();
        }

        // TRI GLOBAL
        ksort($choices, SORT_NATURAL | SORT_FLAG_CASE);

        return $choices;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }
}
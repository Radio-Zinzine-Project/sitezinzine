<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Emission;
use App\Form\EmissionType;
use App\Tests\Support\FormIntegrationTestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

class EmissionTypeTest extends FormIntegrationTestCase
{
    /** @dataProvider conditionalFields */
    public function testMp3AndCompletionFieldsFollowOptions(bool $mp3, bool $pending): void
    {
        $emission = (new Emission())->setIsPendingCompletion($pending);
        $form = $this->form(EmissionType::class, $emission, ['with_mp3' => $mp3]);
        self::assertSame($mp3, $form->has('thumbnailFileMp3'));
        self::assertSame($mp3, $form->has('deleteMp3'));
        self::assertSame($pending, $form->has('markAsCompleted'));
        if ($mp3) {
            self::assertFalse($form->get('deleteMp3')->getConfig()->getMapped());
        }
        if ($pending) {
            self::assertFalse($form->get('markAsCompleted')->getConfig()->getMapped());
        }
    }

    public static function conditionalFields(): iterable
    {
        yield 'standard' => [false, false];
        yield 'mp3 et fiche incomplète' => [true, true];
    }

    public function testCategoryChoicesKeepCurrentArchivedCategoryButExcludeOthers(): void
    {
        $active = $this->category();
        $archived = $this->category(false, true);
        $other = $this->category(false, true);

        $emission = (new Emission())->setCategorie($archived);

        $form = $this->form(
            EmissionType::class,
            $emission,
            ['can_manage_all_categories' => true]
        );

        $choices = $form->get('categorie')->createView()->vars['choices'];

        $labels = [];

        foreach ($choices as $choice) {
            $labels[$choice->value] = $choice->label;
        }

        self::assertArrayHasKey($active->getId(), $labels);
        self::assertArrayHasKey($archived->getId(), $labels);
        self::assertArrayNotHasKey($other->getId(), $labels);

        self::assertStringContainsString(
            '(inactive)',
            $labels[$archived->getId()]
        );

        self::assertStringContainsString(
            '(supprimée)',
            $labels[$archived->getId()]
        );

        $newForm = $this->form(
            EmissionType::class,
            new Emission(),
            ['can_manage_all_categories' => true]
        );

        self::assertNotContains(
            (string) $archived->getId(),
            $this->choiceValues($newForm->get('categorie'))
        );
    }

    public function testPeopleAreSeparatedInitiallyAndRecombinedOnSubmission(): void
    {
        $guest = $this->guest(false);
        $old = $this->guest(true);
        $removed = $this->guest(false);

        $emission = $this->emission()
            ->addInviteOldAnimateur($removed)
            ->addInviteOldAnimateur($old);

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'can_manage_all_categories' => true,
            ]
        );

        self::assertSame([$removed], $form->get('invites')->getData());
        self::assertSame([$old], $form->get('inviteOldAnimateurs')->getData());

        self::assertNotContains(
            (string) $old->getId(),
            $this->choiceValues($form->get('invites'))
        );

        self::assertNotContains(
            (string) $guest->getId(),
            $this->choiceValues($form->get('inviteOldAnimateurs'))
        );

        $form->submit(
            $this->payload($emission) + [
                'invites' => [(string) $guest->getId()],
                'inviteOldAnimateurs' => [(string) $old->getId()],
            ]
        );

        $this->assertValidForm($form);

        self::assertCount(2, $emission->getInviteOldAnimateurs());
        self::assertTrue($emission->getInviteOldAnimateurs()->contains($guest));
        self::assertTrue($emission->getInviteOldAnimateurs()->contains($old));
        self::assertFalse($emission->getInviteOldAnimateurs()->contains($removed));
    }

    /** @dataProvider ownership */
    public function testCurrentUserFallbackAndKeywordDefault(bool $explicitOwner, string $keyword): void
    {
        $current = $this->user();
        $selected = $this->user();

        /*
     * On utilise une émission fixture uniquement pour récupérer
     * une catégorie et un thème persistés et valides.
     */
        $fixture = $this->emission();

        $categorie = $fixture->getCategorie();
        $theme = $fixture->getTheme();

        self::assertNotNull($categorie);
        self::assertNotNull($theme);

        /*
     * Les deux utilisateurs sont actuellement rattachés
     * à la catégorie : ils doivent donc être des choix autorisés.
     */
        $categorie->addUser($current);
        $categorie->addUser($selected);

        /*
     * Important :
     * cette émission n'est PAS persistée.
     *
     * Son ID reste donc null et le formulaire est bien testé
     * dans le contexte d'une création.
     */
        $emission = (new Emission())
            ->setCategorie($categorie)
            ->setTheme($theme);

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'current_user' => $current,
            ]
        );

        $data = $this->payload($emission) + [
            'keyword' => $keyword,
        ];

        if ($explicitOwner) {
            $data['users'] = [
                (string) $selected->getId(),
            ];
        }

        $form->submit($data);

        $this->assertValidForm($form);

        self::assertSame(
            '' === $keyword ? 'Keyword' : $keyword,
            $emission->getKeyword()
        );

        self::assertCount(
            1,
            $emission->getUsers()
        );

        self::assertTrue(
            $emission->getUsers()->contains(
                $explicitOwner ? $selected : $current
            )
        );
    }

    public static function ownership(): iterable
    {
        yield 'propriétaire par défaut' => [false, ''];
        yield 'propriétaire explicite' => [true, 'culture'];
    }

    public function testOmittedPeopleClearExistingAssociationsAndRequireAnOwner(): void
    {
        $emission = $this->emission()->addInviteOldAnimateur($this->guest());
        $form = $this->form(EmissionType::class, $emission);
        $form->submit($this->payload($emission));
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('users')->getErrors()));
        self::assertTrue($emission->getInviteOldAnimateurs()->isEmpty());
        self::assertTrue($emission->getUsers()->isEmpty());
    }
    public function testInvalidDurationIsRejected(): void
    {
        $emission = $this->emission();
        $form = $this->form(EmissionType::class, $emission);
        $form->submit(array_replace($this->payload($emission), ['duree' => '240']));
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('duree')->getErrors()));
    }
    public function testInvalidCurrentUserOptionIsRejected(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->form(EmissionType::class, new Emission(), ['current_user' => 'not-a-user']);
    }
    private function payload(Emission $emission): array
    {
        return [
            'titre' => 'Émission test',
            'categorie' => (string) $emission->getCategorie()->getId(),
            'theme' => (string) $emission->getTheme()->getId(),
            'duree' => '60',
            'descriptif' => 'Description'
        ];
    }

    public function testCategoryChoiceExposesEditorIdInDataAttribute(): void
    {
        $category = $this->category();

        $editor = $category->getEditeur();

        self::assertNotNull($editor);
        self::assertNotNull($editor->getId());

        $emission = (new Emission())
            ->setCategorie($category);

        $form = $this->form(
            EmissionType::class,
            $emission,
            ['can_manage_all_categories' => true]
        );

        $choices = $form
            ->get('categorie')
            ->createView()
            ->vars['choices'];

        $matchingChoice = null;

        foreach ($choices as $choice) {
            if ((string) $choice->value === (string) $category->getId()) {
                $matchingChoice = $choice;
                break;
            }
        }

        self::assertNotNull(
            $matchingChoice,
            'La catégorie de test doit être présente dans les choix du formulaire.'
        );

        self::assertArrayHasKey(
            'data-editeur-id',
            $matchingChoice->attr
        );

        self::assertSame(
            $editor->getId(),
            $matchingChoice->attr['data-editeur-id']
        );
    }

    public function testCategoryChoicesAreRestrictedToCurrentUsersCategories(): void
    {
        $currentUser = $this->user();
        $otherUser = $this->user();

        $allowedCategory = $this->category();
        $forbiddenCategory = $this->category();

        $this->associateCategoryUser($allowedCategory, $currentUser);
        $this->associateCategoryUser($forbiddenCategory, $otherUser);

        $form = $this->form(
            EmissionType::class,
            new Emission(),
            [
                'current_user' => $currentUser,
                'can_manage_all_categories' => false,
            ]
        );

        $choices = $this->choiceValues($form->get('categorie'));

        self::assertContains(
            (string) $allowedCategory->getId(),
            $choices
        );

        self::assertNotContains(
            (string) $forbiddenCategory->getId(),
            $choices
        );
    }

    public function testCategoryChoicesAreNotRestrictedForCategoryManagers(): void
    {
        $currentUser = $this->user(['ROLE_ADMIN']);

        $firstCategory = $this->category();
        $secondCategory = $this->category();

        $form = $this->form(
            EmissionType::class,
            new Emission(),
            [
                'current_user' => $currentUser,
                'can_manage_all_categories' => true,
            ]
        );

        $choices = $this->choiceValues($form->get('categorie'));

        self::assertContains(
            (string) $firstCategory->getId(),
            $choices
        );

        self::assertContains(
            (string) $secondCategory->getId(),
            $choices
        );
    }

    public function testHistoricalCategoryRemainsAvailableWhenUserIsNoLongerAssociated(): void
    {
        $currentUser = $this->user();

        $historicalCategory = $this->category();
        $currentCategory = $this->category();
        $unrelatedCategory = $this->category();

        /*
     * L'utilisateur ne travaille plus sur la catégorie historique.
     * Il travaille maintenant sur une autre catégorie.
     */
        $this->associateCategoryUser($currentCategory, $currentUser);

        $emission = $this->emission()
            ->setCategorie($historicalCategory);

        $this->em->flush();

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'current_user' => $currentUser,
                'can_manage_all_categories' => false,
            ]
        );

        $choices = $this->choiceValues($form->get('categorie'));

        self::assertContains(
            (string) $historicalCategory->getId(),
            $choices,
            'La catégorie historique de l\'émission doit rester disponible.'
        );

        self::assertContains(
            (string) $currentCategory->getId(),
            $choices,
            'Les catégories actuellement associées au user doivent être disponibles.'
        );

        self::assertNotContains(
            (string) $unrelatedCategory->getId(),
            $choices,
            'Une catégorie sans lien avec le user ne doit pas être proposée.'
        );
    }

    public function testSubmittingUnauthorizedCategoryIsRejected(): void
    {
        $currentUser = $this->user();

        $allowedCategory = $this->category();
        $forbiddenCategory = $this->category();

        $this->associateCategoryUser($allowedCategory, $currentUser);

        $emission = $this->emission()
            ->setCategorie($allowedCategory);

        $this->em->flush();

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'current_user' => $currentUser,
                'can_manage_all_categories' => false,
            ]
        );

        $data = array_replace(
            $this->payload($emission),
            [
                'categorie' => (string) $forbiddenCategory->getId(),
            ]
        );

        $form->submit($data);

        self::assertFalse($form->isValid());

        self::assertGreaterThan(
            0,
            count($form->get('categorie')->getErrors(true)),
            'Une catégorie non autorisée doit être refusée à la soumission.'
        );

        self::assertNotSame(
            $forbiddenCategory->getId(),
            $emission->getCategorie()?->getId()
        );
    }

    /** @dataProvider administrativeUserModes */
    public function testAdminAndSuperAdminDefaultToAllCategoryUsersOnCreation(
        bool $canManageAllUsers
    ): void {
        $currentUser = $this->user(
            $canManageAllUsers
                ? ['ROLE_SUPER_ADMIN']
                : ['ROLE_ADMIN']
        );

        $firstCategoryUser = $this->user();
        $secondCategoryUser = $this->user();
        $unrelatedUser = $this->user();

        /*
     * On utilise une émission fixture uniquement pour récupérer
     * une catégorie et un thème persistés.
     */
        $fixture = $this->emission();

        $categorie = $fixture->getCategorie();
        $theme = $fixture->getTheme();

        self::assertNotNull($categorie);
        self::assertNotNull($theme);

        /*
     * Ici il faut alimenter directement la collection de la catégorie,
     * car c'est cette collection que la règle métier utilise ensuite.
     */
        $categorie->addUser($firstCategoryUser);
        $categorie->addUser($secondCategoryUser);

        /*
     * currentUser et unrelatedUser ne sont volontairement
     * pas associés à la catégorie.
     */
        $emission = (new Emission())
            ->setCategorie($categorie)
            ->setTheme($theme);

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'current_user' => $currentUser,
                'can_manage_all_categories' => true,
                'can_manage_all_users' => $canManageAllUsers,
            ]
        );

        $form->submit(
            $this->payload($emission)
        );

        $this->assertValidForm($form);

        self::assertCount(
            2,
            $emission->getUsers()
        );

        self::assertTrue(
            $emission->getUsers()->contains($firstCategoryUser)
        );

        self::assertTrue(
            $emission->getUsers()->contains($secondCategoryUser)
        );

        self::assertFalse(
            $emission->getUsers()->contains($currentUser)
        );

        self::assertFalse(
            $emission->getUsers()->contains($unrelatedUser)
        );
    }

    public static function administrativeUserModes(): iterable
    {
        yield 'admin' => [false];
        yield 'super admin' => [true];
    }

    public function testAdminExplicitUserSelectionOverridesCategoryDefault(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);

        $firstCategoryUser = $this->user();
        $secondCategoryUser = $this->user();

        $fixture = $this->emission();

        $categorie = $fixture->getCategorie();
        $theme = $fixture->getTheme();

        self::assertNotNull($categorie);
        self::assertNotNull($theme);

        $categorie->addUser($firstCategoryUser);
        $categorie->addUser($secondCategoryUser);

        $emission = (new Emission())
            ->setCategorie($categorie)
            ->setTheme($theme);

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'current_user' => $admin,
                'can_manage_all_categories' => true,
                'can_manage_all_users' => false,
            ]
        );

        $form->submit(
            $this->payload($emission) + [
                'users' => [
                    (string) $firstCategoryUser->getId(),
                ],
            ]
        );

        $this->assertValidForm($form);

        self::assertCount(
            1,
            $emission->getUsers()
        );

        self::assertTrue(
            $emission->getUsers()->contains($firstCategoryUser)
        );

        self::assertFalse(
            $emission->getUsers()->contains($secondCategoryUser)
        );
    }

    public function testAdminCannotSelectUserOutsideCategory(): void
    {
        $admin = $this->user(['ROLE_ADMIN']);

        $categoryUser = $this->user();
        $outsideUser = $this->user();

        $fixture = $this->emission();

        $categorie = $fixture->getCategorie();
        $theme = $fixture->getTheme();

        self::assertNotNull($categorie);
        self::assertNotNull($theme);

        $categorie->addUser($categoryUser);

        $emission = (new Emission())
            ->setCategorie($categorie)
            ->setTheme($theme);

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'current_user' => $admin,
                'can_manage_all_categories' => true,
                'can_manage_all_users' => false,
            ]
        );

        $form->submit(
            $this->payload($emission) + [
                'users' => [
                    (string) $outsideUser->getId(),
                ],
            ]
        );

        self::assertFalse(
            $form->isValid()
        );

        self::assertGreaterThan(
            0,
            count($form->get('users')->getErrors(true)),
            'Un ADMIN ne doit pas pouvoir associer un utilisateur hors catégorie.'
        );

        self::assertFalse(
            $emission->getUsers()->contains($outsideUser)
        );
    }

    public function testSuperAdminCanExplicitlySelectUserOutsideCategory(): void
    {
        $superAdmin = $this->user(['ROLE_SUPER_ADMIN']);

        $categoryUser = $this->user();
        $outsideUser = $this->user();

        $fixture = $this->emission();

        $categorie = $fixture->getCategorie();
        $theme = $fixture->getTheme();

        self::assertNotNull($categorie);
        self::assertNotNull($theme);

        $categorie->addUser($categoryUser);

        $emission = (new Emission())
            ->setCategorie($categorie)
            ->setTheme($theme);

        $form = $this->form(
            EmissionType::class,
            $emission,
            [
                'current_user' => $superAdmin,
                'can_manage_all_categories' => true,
                'can_manage_all_users' => true,
            ]
        );

        $form->submit(
            $this->payload($emission) + [
                'users' => [
                    (string) $outsideUser->getId(),
                ],
            ]
        );

        $this->assertValidForm($form);

        self::assertCount(
            1,
            $emission->getUsers()
        );

        self::assertTrue(
            $emission->getUsers()->contains($outsideUser)
        );

        self::assertFalse(
            $emission->getUsers()->contains($categoryUser)
        );
    }
}

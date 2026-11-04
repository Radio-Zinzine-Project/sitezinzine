<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Categories;
use App\Form\CategorieType;
use App\Tests\Support\FormIntegrationTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class CategorieTypeTest extends FormIntegrationTestCase
{
    /** @dataProvider slugPermissions */
    public function testSlugPermissionsOnCreateAndEdit(bool $edit, bool $super, bool $disabled): void
    {
        $user = $this->user($super ? ['ROLE_SUPER_ADMIN'] : ['ROLE_ADMIN']);
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $category = $edit ? $this->category() : new Categories();
        $category->setSlug('OLD');
        $editor = $this->editor();
        $form = $this->form(CategorieType::class, $category);
        self::assertSame($disabled, $form->get('slug')->isDisabled());
        $form->submit([
            'titre' => 'Culture',
            'slug' => 'NEW',
            'editeur' => (string) $editor->getId(),
            'duree' => '60',
            'descriptif' => 'Description',
            'users' => [(string) $user->getId()],
            'active' => '1'
        ]);
        $this->assertValidForm($form);
        self::assertSame($disabled ? 'OLD' : 'NEW', $category->getSlug());
        self::assertSame($editor, $category->getEditeur());
        self::assertTrue($category->getUsers()->contains($user));
    }
    public static function slugPermissions(): iterable
    {
        yield 'création admin' => [false, false, false];
        yield 'édition admin verrouillée' => [true, false, true];
        yield 'édition super admin' => [true, true, false];
    }
    public function testOmittedOwnerFieldsClearCollectionsAndFailOwnerValidation(): void
    {
        $user = $this->user();
        $old = $this->guest(true);
        $category = $this->category()->addUser($user)->addInviteOldAnimateur($old);
        $form = $this->form(CategorieType::class, $category);
        $form->submit(['titre' => 'Culture', 'descriptif' => 'Description', 'editeur' => (string) $category->getEditeur()->getId(), 'duree' => '60']);
        self::assertTrue($category->getUsers()->isEmpty());
        self::assertTrue($category->getInviteOldAnimateurs()->isEmpty());
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->getErrors(true)));
    }
    public function testFormerPresentersAreSelectableButGuestsAreExcluded(): void
    {
        $old = $this->guest(true);
        $guest = $this->guest(false);
        $category = $this->category();
        $form = $this->form(CategorieType::class, $category);
        $values = $this->choiceValues($form->get('inviteOldAnimateurs'));
        self::assertContains((string) $old->getId(), $values);
        self::assertNotContains((string) $guest->getId(), $values);
        $form->submit([
            'titre' => '',
            'descriptif' => 'Description',
            'editeur' => (string) $category->getEditeur()->getId(),
            'duree' => '60',
            'inviteOldAnimateurs' => [(string) $old->getId()]
        ]);
        $this->assertValidForm($form);
        self::assertSame('Nouvelle catégorie', $category->getTitre());
        self::assertTrue($category->getInviteOldAnimateurs()->contains($old));
    }
    public function testMalformedSlugFailsValidation(): void
    {
        $user = $this->user();
        $form = $this->form(CategorieType::class, new Categories());
        $form->submit(['titre' => 'Culture', 'descriptif' => 'Description', 'slug' => '12', 'editeur' => (string) $this->editor()->getId(), 'duree' => '60', 'users' => [(string) $user->getId()]]);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('slug')->getErrors()));
    }

    public function testEmptyDescriptionFailsValidationWithoutMappingException(): void
    {
        $user = $this->user();

        $category = $this->category();

        $form = $this->form(
            CategorieType::class,
            $category
        );

        self::assertTrue(
            $form->get('descriptif')->isRequired()
        );

        $form->submit([
            'titre' => 'Culture',
            'descriptif' => '',
            'editeur' => (string) $category->getEditeur()->getId(),
            'duree' => '60',
            'users' => [
                (string) $user->getId(),
            ],
        ]);

        self::assertFalse(
            $form->isValid()
        );

        self::assertGreaterThan(
            0,
            count(
                $form->get('descriptif')->getErrors()
            )
        );

        self::assertSame(
            '',
            $category->getDescriptif()
        );
    }
}

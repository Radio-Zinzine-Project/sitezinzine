<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\ChangePasswordType;
use App\Tests\Support\FormIntegrationTestCase;

class ChangePasswordTypeTest extends FormIntegrationTestCase
{
    public function testValidPasswordChangeForm(): void
    {
        $form = $this->form(ChangePasswordType::class);

        $form->submit([
            'currentPassword' => 'ancien-mot-de-passe',
            'newPassword' => [
                'first' => 'nouveau-mot-de-passe',
                'second' => 'nouveau-mot-de-passe',
            ],
        ]);

        $this->assertValidForm($form);

        self::assertSame(
            'ancien-mot-de-passe',
            $form->get('currentPassword')->getData()
        );

        self::assertSame(
            'nouveau-mot-de-passe',
            $form->get('newPassword')->getData()
        );
    }

    public function testCurrentPasswordIsRequired(): void
    {
        $form = $this->form(ChangePasswordType::class);

        $form->submit([
            'currentPassword' => '',
            'newPassword' => [
                'first' => 'nouveau-mot-de-passe',
                'second' => 'nouveau-mot-de-passe',
            ],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());

        self::assertSame(
            'Saisissez votre mot de passe actuel',
            $form
                ->get('currentPassword')
                ->getErrors()[0]
                ->getMessage()
        );
    }

    public function testNewPasswordIsRequired(): void
    {
        $form = $this->form(ChangePasswordType::class);

        $form->submit([
            'currentPassword' => 'ancien-mot-de-passe',
            'newPassword' => [
                'first' => '',
                'second' => '',
            ],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());

        $errors = (string) $form->getErrors(true, false);

        self::assertStringContainsString(
            'Choisissez un nouveau mot de passe',
            $errors
        );
    }

    public function testNewPasswordMustHaveAtLeastSixCharacters(): void
    {
        $form = $this->form(ChangePasswordType::class);

        $form->submit([
            'currentPassword' => 'ancien-mot-de-passe',
            'newPassword' => [
                'first' => '12345',
                'second' => '12345',
            ],
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());

        $errors = (string) $form->getErrors(true, false);

        self::assertStringContainsString(
            'Votre mot de passe doit avoir au moins 6 caractères',
            $errors
        );
    }

    public function testNewPasswordConfirmationMustMatch(): void
    {
        $form = $this->form(ChangePasswordType::class);

        $form->submit([
            'currentPassword' => 'ancien-mot-de-passe',
            'newPassword' => [
                'first' => 'nouveau-mot-de-passe',
                'second' => 'mot-de-passe-different',
            ],
        ]);

        self::assertTrue($form->isSubmitted());

        /*
     * RepeatedType a correctement transformé les deux champs :
     * une différence entre eux est une erreur de validation,
     * pas une erreur de transformation.
     */
        self::assertTrue($form->isSynchronized());
        self::assertFalse($form->isValid());

        $errors = (string) $form->getErrors(true, false);

        self::assertStringContainsString(
            'Les deux mots de passe doivent être identiques.',
            $errors
        );
    }
}

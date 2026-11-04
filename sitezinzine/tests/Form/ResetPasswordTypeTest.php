<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Form\ResetPasswordType;
use App\Tests\Support\FormIntegrationTestCase;

class ResetPasswordTypeTest extends FormIntegrationTestCase
{
    /** @dataProvider passwords */
    public function testPasswordConstraints(int $length, string $confirmation, bool $valid): void
    {
        $password = str_repeat('x', $length);
        $form = $this->form(ResetPasswordType::class);
        $form->submit(['password' => $password, 'passwordConfirm' => $confirmation]);
        self::assertSame($valid, $form->isValid());
        self::assertArrayNotHasKey('passwordConfirm', $form->getData());
        self::assertSame('' === $confirmation ? null : $confirmation, $form->get('passwordConfirm')->getData());
        if ($valid) {
            self::assertSame($password, $form->getData()['password']);
        }
    }
    public static function passwords(): iterable
    {
        yield 'minimum' => [8, 'xxxxxxxx', true];
        yield 'maximum' => [4096, 'confirmation', true];
        yield 'trop court' => [7, 'xxxxxxx', false];
        yield 'trop long' => [4097, 'confirmation', false];
        yield 'vide' => [0, 'confirmation', false];
        yield 'confirmation vide' => [8, '', false];
    }
}

<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Form\ForgotPasswordRequestType;
use App\Tests\Support\FormIntegrationTestCase;

class ForgotPasswordRequestTypeTest extends FormIntegrationTestCase
{
    /** @dataProvider emails */
    public function testEmailValidation(string $email, bool $valid): void
    {
        $form = $this->form(ForgotPasswordRequestType::class);
        $form->submit(['email' => $email]);
        self::assertSame($valid, $form->isValid());
        if ($valid) {
            self::assertSame(trim($email), $form->getData()['email']);
        } else {
            self::assertGreaterThan(0, count($form->get('email')->getErrors()));
        }
    }
    public static function emails(): iterable
    {
        yield 'valide' => [' alice@example.com ', true];
        yield 'vide' => ['', false];
        yield 'invalide' => ['not-an-email', false];
    }
}

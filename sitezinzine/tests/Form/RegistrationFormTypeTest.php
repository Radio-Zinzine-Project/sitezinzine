<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Tests\Support\FormIntegrationTestCase;

class RegistrationFormTypeTest extends FormIntegrationTestCase
{
    public function testExistingUsernameAndEmailAreRejected(): void
    {
        $existing = $this->user();
        $form = $this->form(RegistrationFormType::class, new User());
        $form->submit(['username' => $existing->getUsername(), 'email' => $existing->getEmail(),
            'plainPassword' => 'password123', 'agreeTerms' => '1']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('username')->getErrors()));
        self::assertGreaterThan(0, count($form->get('email')->getErrors()));
    }
    /** @dataProvider passwords */
    public function testRegistrationValidatesPasswordAndTerms(int $length, bool $agree, bool $valid): void
    {
        $user = (new User())->setPassword('existing-hash');
        $form = $this->form(RegistrationFormType::class, $user);
        $id = uniqid('register_');
        $data = ['username' => $id, 'email' => $id . '@example.com', 'plainPassword' => str_repeat('x', $length)];
        if ($agree) { $data['agreeTerms'] = '1'; }
        $form->submit($data);
        self::assertSame($valid, $form->isValid(), (string) $form->getErrors(true, false));
        self::assertSame($id, $user->getUsername());
        self::assertSame('existing-hash', $user->getPassword());
        self::assertFalse($form->get('plainPassword')->getConfig()->getMapped());
        self::assertFalse($form->get('agreeTerms')->getConfig()->getMapped());
    }
    public static function passwords(): iterable
    {
        yield 'valide' => [6, true, true];
        yield 'vide' => [0, true, false];
        yield 'court' => [5, true, false];
        yield 'long' => [4097, true, false];
        yield 'conditions refusées' => [8, false, false];
    }
    public function testCsrfConfigurationAndInvalidToken(): void
    {
        $form = $this->form(RegistrationFormType::class, new User(), ['csrf_protection' => true]);
        self::assertSame('registration', $form->getConfig()->getOption('csrf_token_id'));
        self::assertSame('_token', $form->getConfig()->getOption('csrf_field_name'));
        $form->submit(['username' => uniqid('csrf_'), 'email' => uniqid() . '@example.com', 'plainPassword' => 'password123', 'agreeTerms' => '1', '_token' => 'invalid']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->getErrors()));
    }
}

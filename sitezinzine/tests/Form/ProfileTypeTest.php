<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Form\ProfileType;
use App\Tests\Support\FormIntegrationTestCase;

class ProfileTypeTest extends FormIntegrationTestCase
{
    /** @dataProvider invalidPseudos */
    public function testPseudoConstraints(string $pseudo): void
    {
        $form = $this->form(ProfileType::class, $this->user());
        $form->submit(['pseudo' => $pseudo, 'newEmail' => '']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('pseudo')->getErrors()));
    }
    public static function invalidPseudos(): iterable
    {
        yield 'trop court' => ['x'];
        yield 'trop long' => [str_repeat('x', 61)];
        yield 'caractères interdits' => ['<script>'];
    }
    /** @dataProvider pendingEmails */
    public function testInitialEmailAndUnmappedNewEmail(?string $pending): void
    {
        $user = $this->user()->setPendingEmail($pending);
        $original = $user->getEmail();
        $form = $this->form(ProfileType::class, $user);
        self::assertSame($pending ?? $original, $form->get('newEmail')->getData());
        $form->submit(['pseudo' => ' Nouveau pseudo ', 'newEmail' => 'new@example.com']);
        $this->assertValidForm($form);
        self::assertSame('Nouveau pseudo', $user->getPseudo());
        self::assertSame($original, $user->getEmail());
        self::assertSame($pending, $user->getPendingEmail());
        self::assertSame('new@example.com', $form->get('newEmail')->getData());
    }
    public static function pendingEmails(): iterable
    {
        yield 'email actuel' => [null];
        yield 'email en attente' => ['pending@example.com'];
    }
    public function testOptionalFieldsCanBeCleared(): void
    {
        $user = $this->user()->setPseudo('Old');
        $form = $this->form(ProfileType::class, $user);
        $form->submit(['pseudo' => '', 'newEmail' => '']);
        $this->assertValidForm($form);
        self::assertNull($user->getPseudo());
        self::assertNull($form->get('newEmail')->getData());
    }
}

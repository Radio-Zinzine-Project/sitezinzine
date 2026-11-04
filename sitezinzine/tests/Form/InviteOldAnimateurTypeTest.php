<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\InviteOldAnimateur;
use App\Form\InviteOldAnimateurType;
use App\Tests\Support\FormIntegrationTestCase;

class InviteOldAnimateurTypeTest extends FormIntegrationTestCase
{
    public function testSubmissionMapsContactAndFormerPresenterFlag(): void
    {
        $person = new InviteOldAnimateur();
        $form = $this->form(InviteOldAnimateurType::class, $person);
        $form->submit(['firstName' => ' Alice ', 'lastName' => 'Martin', 'mail' => 'alice@example.com', 'phoneNumber' => '0102030405', 'ancienanimateur' => '1']);
        $this->assertValidForm($form);
        self::assertSame('Alice', $person->getFirstName());
        self::assertSame('Martin', $person->getLastName());
        self::assertSame('alice@example.com', $person->getMail());
        self::assertSame('0102030405', $person->getPhoneNumber());
        self::assertTrue($person->isAncienanimateur());
    }
    public function testEmptyFirstNameIsRejected(): void
    {
        $form = $this->form(InviteOldAnimateurType::class, new InviteOldAnimateur());
        $form->submit(['firstName' => '   ']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('firstName')->getErrors()));
    }
    public function testOptionalFieldsAndCheckboxCanBeCleared(): void
    {
        $person = (new InviteOldAnimateur())->setAncienanimateur(true)->setMail('old@example.com');
        $form = $this->form(InviteOldAnimateurType::class, $person);
        $form->submit(['firstName' => 'Alice']);
        $this->assertValidForm($form);
        self::assertFalse($person->isAncienanimateur());
        self::assertNull($person->getMail());
    }
}

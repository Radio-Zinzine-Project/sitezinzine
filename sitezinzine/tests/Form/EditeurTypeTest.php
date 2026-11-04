<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\Editeur;
use App\Form\EditeurType;
use App\Tests\Support\FormIntegrationTestCase;

class EditeurTypeTest extends FormIntegrationTestCase
{
    public function testSubmissionMapsEditorContact(): void
    {
        $editor = new Editeur();
        $form = $this->form(EditeurType::class, $editor);
        $form->submit(['name' => ' Radio ', 'mail' => 'radio@example.com', 'phone' => '0102030405']);
        $this->assertValidForm($form);
        self::assertSame('Radio', $editor->getName());
        self::assertSame('radio@example.com', $editor->getMail());
        self::assertSame('0102030405', $editor->getPhone());
    }
    public function testEmptyNameUsesDefaultAndPhoneIsOptional(): void
    {
        $editor = new Editeur();
        $form = $this->form(EditeurType::class, $editor);
        $form->submit(['name' => '', 'mail' => 'radio@example.com']);
        $this->assertValidForm($form);
        self::assertSame('Nouvel éditeur', $editor->getName());
        self::assertNull($editor->getPhone());
    }
}

<?php
declare(strict_types=1);
namespace App\Tests\Form;

use App\Entity\Annonce;
use App\Form\AnnonceType;
use App\Tests\Support\FormIntegrationTestCase;

class AnnonceTypeTest extends FormIntegrationTestCase
{
    /** @dataProvider existingTypes */
    public function testInitialTypeAndCustomType(?string $type, ?string $selected, string $custom): void
    {
        $annonce = new Annonce();
        if (null !== $type) { $annonce->setType($type); }
        $form = $this->form(AnnonceType::class, $annonce);
        self::assertSame($selected, $form->get('type')->getData());
        self::assertSame($custom, $form->get('autreType')->getData());
        self::assertFalse($form->get('autreType')->getConfig()->getMapped());
        self::assertFalse($form->has('valid'));
    }
    public static function existingTypes(): iterable
    {
        yield 'nouvelle' => [null, null, ''];
        yield 'connue' => [' Concert ', 'Concert', ''];
        yield 'personnalisée' => [' Marché local ', '__autre__', 'Marché local'];
    }
    public function testSubmitMapsDatesAndAdminValidation(): void
    {
        $annonce = new Annonce();
        $form = $this->form(AnnonceType::class, $annonce, ['show_valid' => true]);
        $form->submit($this->payload() + ['valid' => '1']);
        $this->assertValidForm($form);
        self::assertSame('Concert', $annonce->getType());
        self::assertSame('04', $annonce->getDepartement());
        self::assertSame('2026-10-20', $annonce->getDateDebut()->format('Y-m-d'));
        self::assertSame('2026-10-21', $annonce->getDateFin()->format('Y-m-d'));
        self::assertTrue($annonce->isValid());
        self::assertSame('', $annonce->getPresentation());
    }
    /** @dataProvider invalidData */
    public function testInvalidChoiceDateOrLength(string $field, string $value): void
    {
        $form = $this->form(AnnonceType::class, new Annonce());
        $form->submit(array_replace($this->payload(), [$field => $value]));
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get($field)->getErrors(true)));
    }
    public static function invalidData(): iterable
    {
        yield 'département' => ['departement', '99'];
        yield 'type' => ['type', 'forged'];
        yield 'date' => ['dateDebut', 'not-a-date'];
        yield 'titre trop long' => ['titre', str_repeat('x', 101)];
    }
    private function payload(): array
    {
        return ['titre' => 'Concert local', 'organisateur' => 'Radio', 'ville' => 'Limans', 'adresse' => 'Place',
            'departement' => '04', 'type' => 'Concert', 'dateDebut' => '2026-10-20', 'dateFin' => '2026-10-21'];
    }
}

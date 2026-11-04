<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Evenement;
use App\Form\EvenementType;
use App\Tests\Support\FormIntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class EvenementTypeTest extends FormIntegrationTestCase
{
    /** @dataProvider types */
    public function testInitialTypeNormalization(?string $type, ?string $selected, string $custom): void
    {
        $event = new Evenement();
        if (null !== $type) {
            $event->setType($type);
        }
        $form = $this->form(EvenementType::class, $event);
        self::assertSame($selected, $form->get('type')->getData());
        self::assertSame($custom, $form->get('autreType')->getData());
        self::assertFalse($form->has('valid'));
        $form->createView(); // Exerce aussi les libellés et valeurs des choix.
    }

    public static function types(): iterable
    {
        yield 'nouveau' => [
            null,
            null,
            '',
        ];

        yield 'emission avec casse et espaces' => [
            ' EMISSION ',
            'Emission',
            '',
        ];

        yield 'studio mobile connu' => [
            'Studio Mobile',
            'Studio Mobile',
            '',
        ];

        yield 'table ronde connue' => [
            'Table Ronde',
            'Table Ronde',
            '',
        ];

        yield 'rassemblement manifestation connu' => [
            'Rassemblement - Manifestation',
            'Rassemblement - Manifestation',
            '',
        ];

        yield 'type personnalisé' => [
            'Rencontre locale',
            'autre',
            'Rencontre locale',
        ];
    }

    public function testValidSubmissionWithOptionalFieldsAndValidationFlag(): void
    {
        $event = new Evenement();
        $form = $this->form(EvenementType::class, $event, ['show_valid' => true]);
        $form->submit(['titre' => 'Rencontre', 'type' => 'emission', 'dateDebut' => '2026-10-20', 'dateFin' => '2026-10-21', 'valid' => '1']);
        $this->assertValidForm($form);
        self::assertSame('Emission', $event->getType());
        self::assertNull($event->getDepartement());
        self::assertSame('', $event->getPresentation());
        self::assertTrue($event->isValid());
        self::assertSame('2026-10-20', $event->getDateDebut()->format('Y-m-d'));
    }

    public function testEmptyTitleAndMalformedDateAreRejected(): void
    {
        $form = $this->form(EvenementType::class, new Evenement());
        $form->submit(['titre' => '', 'type' => 'emission', 'dateDebut' => 'invalid', 'dateFin' => '2026-10-21']);
        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, count($form->get('titre')->getErrors()));
        self::assertGreaterThan(0, count($form->get('dateDebut')->getErrors()));
    }

    public function testEndDateBeforeStartDateIsRejected(): void
    {
        $event = new Evenement();

        $form = $this->form(
            EvenementType::class,
            $event
        );

        $form->submit([
            'titre' => 'Événement avec dates incohérentes',
            'type' => 'emission',
            'dateDebut' => '2026-10-20',
            'dateFin' => '2026-10-19',
        ]);

        self::assertFalse(
            $form->isValid()
        );

        self::assertGreaterThan(
            0,
            count($form->get('dateFin')->getErrors())
        );
    }

    public function testCustomTypeIsStoredFromAutreType(): void
    {
        $event = new Evenement();

        $form = $this->form(EvenementType::class, $event);

        $form->submit([
            'titre' => 'Rencontre locale',
            'type' => 'autre',
            'autreType' => 'Débat public',
            'dateDebut' => '2026-10-20',
            'dateFin' => '2026-10-21',
        ]);

        $this->assertValidForm($form);

        self::assertSame(
            'Débat public',
            $event->getType()
        );
    }

    public function testCustomTypeLongerThanFiftyCharactersIsRejected(): void
    {
        $event = new Evenement();

        $form = $this->form(
            EvenementType::class,
            $event
        );

        $form->submit([
            'titre' => 'Événement avec type trop long',
            'type' => 'autre',
            'autreType' => str_repeat('a', 51),
            'dateDebut' => '2026-10-20',
            'dateFin' => '2026-10-21',
        ]);

        self::assertFalse(
            $form->isValid()
        );

        self::assertGreaterThan(
            0,
            count($form->get('autreType')->getErrors(true))
        );
    }

    public function testNonImageThumbnailIsRejected(): void
    {
        $event = new Evenement();

        $form = $this->form(
            EvenementType::class,
            $event
        );

        $tmpFile = tempnam(
            sys_get_temp_dir(),
            'evenement-test-'
        );

        file_put_contents(
            $tmpFile,
            'ce fichier n est pas une image'
        );

        $uploadedFile = new UploadedFile(
            $tmpFile,
            'not-an-image.txt',
            'text/plain',
            null,
            true
        );

        $form->submit([
            'titre' => 'Événement avec faux fichier',
            'type' => 'emission',
            'dateDebut' => '2026-10-20',
            'dateFin' => '2026-10-21',
            'thumbnailFile' => $uploadedFile,
        ]);

        self::assertFalse(
            $form->isValid()
        );

        self::assertGreaterThan(
            0,
            count($form->get('thumbnailFile')->getErrors(true))
        );
    }
}

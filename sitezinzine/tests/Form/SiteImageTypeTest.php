<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\SiteImage;
use App\Form\SiteImageType;
use App\Tests\Support\FormIntegrationTestCase;

class SiteImageTypeTest extends FormIntegrationTestCase
{
    public function testCreateFormContainsExpectedFields(): void
    {
        $siteImage = new SiteImage();

        $form = $this->form(
            SiteImageType::class,
            $siteImage
        );

        self::assertTrue($form->has('key'));
        self::assertTrue($form->has('label'));
        self::assertTrue($form->has('description'));
        self::assertTrue($form->has('thumbnailFile'));

        /*
         * À la création, la clé technique doit pouvoir être renseignée.
         */
        self::assertFalse(
            $form->get('key')->getConfig()->getDisabled()
        );

        /*
         * Description et image sont facultatives.
         */
        self::assertFalse(
            $form->get('description')->getConfig()->getRequired()
        );

        self::assertFalse(
            $form->get('thumbnailFile')->getConfig()->getRequired()
        );
    }

    public function testCreateFormMapsSubmittedDataToEntity(): void
    {
        $siteImage = new SiteImage();

        $form = $this->form(
            SiteImageType::class,
            $siteImage
        );

        $form->submit([
            'key' => 'homepage_banner',
            'label' => 'Bannière accueil',
            'description' => 'Image affichée sur la page d’accueil.',
        ]);

        $this->assertValidForm($form);

        self::assertSame(
            'homepage_banner',
            $siteImage->getKey()
        );

        self::assertSame(
            'Bannière accueil',
            $siteImage->getLabel()
        );

        self::assertSame(
            'Image affichée sur la page d’accueil.',
            $siteImage->getDescription()
        );

        self::assertNull(
            $siteImage->getThumbnailFile()
        );
    }

    public function testCreateFormAcceptsEmptyOptionalDescriptionAndImage(): void
    {
        $siteImage = new SiteImage();

        $form = $this->form(
            SiteImageType::class,
            $siteImage
        );

        $form->submit([
            'key' => 'homepage_banner',
            'label' => 'Bannière accueil',
            'description' => '',
        ]);

        $this->assertValidForm($form);

        self::assertSame(
            'homepage_banner',
            $siteImage->getKey()
        );

        self::assertSame(
            'Bannière accueil',
            $siteImage->getLabel()
        );

        self::assertNull(
            $siteImage->getDescription()
        );

        self::assertNull(
            $siteImage->getThumbnailFile()
        );
    }

    public function testEditFormDisablesTechnicalKey(): void
    {
        /*
         * SiteImageType considère qu'il s'agit d'une édition
         * dès que l'entité possède un ID.
         *
         * On persiste donc réellement l'entité dans la base de test.
         * La transaction sera annulée par FormIntegrationTestCase.
         */
        $siteImage = $this->save(
            (new SiteImage())
                ->setKey(
                    'test_edit_' . bin2hex(random_bytes(6))
                )
                ->setLabel('Playlist de jour')
        );

        self::assertNotNull(
            $siteImage->getId()
        );

        $form = $this->form(
            SiteImageType::class,
            $siteImage
        );

        /*
         * La règle métier importante :
         * une clé existante n'est plus modifiable.
         */
        self::assertTrue(
            $form->get('key')->getConfig()->getDisabled()
        );

        /*
         * Le texte d'aide appartient à la vue du formulaire,
         * et non à FormConfigInterface.
         */
        $keyView = $form->get('key')->createView();

        self::assertArrayHasKey(
            'help',
            $keyView->vars
        );

        self::assertSame(
            'La clé technique ne peut pas être modifiée après la création.',
            $keyView->vars['help']
        );
    }

    public function testEditSubmissionCannotModifyTechnicalKey(): void
    {
        $originalKey = 'test_immutable_' . bin2hex(
            random_bytes(6)
        );

        $siteImage = $this->save(
            (new SiteImage())
                ->setKey($originalKey)
                ->setLabel('Ancien libellé')
                ->setDescription('Ancienne description')
        );

        self::assertNotNull(
            $siteImage->getId()
        );

        $form = $this->form(
            SiteImageType::class,
            $siteImage
        );

        self::assertTrue(
            $form->get('key')->getConfig()->getDisabled()
        );

        /*
         * On simule volontairement une requête contenant une tentative
         * de modification de la clé.
         *
         * Le champ étant disabled en édition, Symfony ne doit pas
         * appliquer cette valeur à l'entité.
         */
        $form->submit([
            'key' => 'malicious_key_change',
            'label' => 'Nouveau libellé',
            'description' => 'Nouvelle description',
        ]);

        $this->assertValidForm($form);

        /*
         * La clé technique doit être strictement conservée.
         */
        self::assertSame(
            $originalKey,
            $siteImage->getKey()
        );

        /*
         * Les autres champs restent normalement modifiables.
         */
        self::assertSame(
            'Nouveau libellé',
            $siteImage->getLabel()
        );

        self::assertSame(
            'Nouvelle description',
            $siteImage->getDescription()
        );
    }

    /**
     * @dataProvider invalidRequiredData
     */
    public function testCreateFormRejectsMissingRequiredData(
        string $key,
        string $label
    ): void {
        $siteImage = new SiteImage();

        $form = $this->form(
            SiteImageType::class,
            $siteImage
        );

        $form->submit([
            'key' => $key,
            'label' => $label,
            'description' => '',
        ]);

        self::assertTrue(
            $form->isSubmitted()
        );

        self::assertTrue(
            $form->isSynchronized()
        );

        self::assertFalse(
            $form->isValid()
        );
    }

    public static function invalidRequiredData(): iterable
    {
        yield 'clé absente' => [
            '',
            'Playlist de jour',
        ];

        yield 'libellé absent' => [
            'playlist_day',
            '',
        ];
    }
}
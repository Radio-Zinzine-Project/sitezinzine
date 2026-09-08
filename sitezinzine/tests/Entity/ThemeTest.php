<?php

namespace App\Tests\Entity;

use App\Entity\Emission;
use App\Entity\Theme;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;

class ThemeTest extends TestCase
{
    public function testInitialValues(): void
    {
        $theme = new Theme();

        $this->assertNull($theme->getId());
        $this->assertNull($theme->getName());
        $this->assertNull($theme->getThumbnail());
        $this->assertNull($theme->getThumbnailFile());
        $this->assertNull($theme->getUpdatedAt());
        $this->assertCount(0, $theme->getEmissions());
    }

    public function testGettersAndSetters(): void
    {
        $theme = new Theme();
        $date = new \DateTime('2026-09-06 12:00:00');
        $file = new File(__FILE__);

        $result = $theme
            ->setName('Musique')
            ->setThumbnail('thumb.jpg')
            ->setThumbnailFile($file)
            ->setUpdatedAt($date);

        $this->assertSame($theme, $result);
        $this->assertSame('Musique', $theme->getName());
        $this->assertSame('thumb.jpg', $theme->getThumbnail());
        $this->assertSame($file, $theme->getThumbnailFile());
        $this->assertSame($date, $theme->getUpdatedAt());
    }

    public function testNullableFields(): void
    {
        $theme = new Theme();
        $file = new File(__FILE__);

        $theme
            ->setName('Musique')
            ->setThumbnail('thumb.jpg')
            ->setThumbnailFile($file);

        $theme
            ->setName(null)
            ->setThumbnail(null)
            ->setThumbnailFile(null);

        $this->assertNull($theme->getName());
        $this->assertNull($theme->getThumbnail());
        $this->assertNull($theme->getThumbnailFile());
    }

    public function testAddEmissionSetsOwningSide(): void
    {
        $theme = new Theme();
        $emission = new Emission();

        $result = $theme->addEmission($emission);

        $this->assertSame($theme, $result);
        $this->assertCount(1, $theme->getEmissions());
        $this->assertTrue(
            $theme->getEmissions()->contains($emission)
        );
        $this->assertSame($theme, $emission->getTheme());
    }

    public function testAddingSameEmissionTwiceDoesNotDuplicateIt(): void
    {
        $theme = new Theme();
        $emission = new Emission();

        $theme->addEmission($emission);
        $theme->addEmission($emission);

        $this->assertCount(1, $theme->getEmissions());
        $this->assertSame($theme, $emission->getTheme());
    }

    public function testRemoveEmissionClearsOwningSide(): void
    {
        $theme = new Theme();
        $emission = new Emission();

        $theme->addEmission($emission);

        $this->assertSame($theme, $emission->getTheme());
        $this->assertCount(1, $theme->getEmissions());

        $result = $theme->removeEmission($emission);

        $this->assertSame($theme, $result);
        $this->assertCount(0, $theme->getEmissions());
        $this->assertNull($emission->getTheme());
    }

    public function testRemoveEmissionDoesNotClearAnotherTheme(): void
    {
        $theme = new Theme();
        $otherTheme = new Theme();
        $emission = new Emission();

        $theme->addEmission($emission);

        /*
         * On simule le cas où le côté propriétaire de la relation
         * a déjà été modifié avant le retrait de l'ancienne collection.
         */
        $emission->setTheme($otherTheme);

        $this->assertSame($otherTheme, $emission->getTheme());
        $this->assertCount(1, $theme->getEmissions());

        $theme->removeEmission($emission);

        $this->assertCount(0, $theme->getEmissions());

        /*
         * removeEmission() ne doit pas écraser le nouveau thème,
         * puisque getTheme() n'est plus égal à $theme.
         */
        $this->assertSame($otherTheme, $emission->getTheme());
    }

    public function testRemovingUnknownEmissionDoesNothing(): void
    {
        $theme = new Theme();
        $emission = new Emission();

        $result = $theme->removeEmission($emission);

        $this->assertSame($theme, $result);
        $this->assertCount(0, $theme->getEmissions());
        $this->assertNull($emission->getTheme());
    }
}
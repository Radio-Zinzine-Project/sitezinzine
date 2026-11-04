<?php

namespace App\Tests\Entity;

use App\Entity\CategorieTagImage;
use App\Entity\Categories;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Validation;

class CategorieTagImageTest extends TestCase
{
    public function testInitialValues(): void
    {
        $tagImage = new CategorieTagImage();

        $this->assertNull($tagImage->getId());
        $this->assertNull($tagImage->getCategorie());
        $this->assertNull($tagImage->getAnnee());
        $this->assertNull($tagImage->getImage());
        $this->assertNull($tagImage->getImageFile());
        $this->assertNull($tagImage->getUpdatedAt());

        $this->assertSame(
            'Catégorie - ',
            (string) $tagImage
        );
    }

    public function testGettersAndSetters(): void
    {
        $tagImage = new CategorieTagImage();
        $categorie = new Categories();
        $date = new \DateTimeImmutable('2026-09-06 12:00:00');

        $result = $tagImage
            ->setCategorie($categorie)
            ->setAnnee(2026)
            ->setImage('image.webp')
            ->setUpdatedAt($date);

        $this->assertSame($tagImage, $result);
        $this->assertSame($categorie, $tagImage->getCategorie());
        $this->assertSame(2026, $tagImage->getAnnee());
        $this->assertSame('image.webp', $tagImage->getImage());
        $this->assertSame($date, $tagImage->getUpdatedAt());
    }

    public function testNullableFields(): void
    {
        $tagImage = new CategorieTagImage();
        $categorie = new Categories();

        $tagImage
            ->setCategorie($categorie)
            ->setImage('image.jpg')
            ->setUpdatedAt(new \DateTimeImmutable());

        $tagImage
            ->setCategorie(null)
            ->setImage(null)
            ->setUpdatedAt(null);

        $this->assertNull($tagImage->getCategorie());
        $this->assertNull($tagImage->getImage());
        $this->assertNull($tagImage->getUpdatedAt());
    }

    public function testSetImageFileUpdatesUpdatedAt(): void
    {
        $tagImage = new CategorieTagImage();
        $file = new File(__FILE__);

        $before = new \DateTimeImmutable();

        $result = $tagImage->setImageFile($file);

        $after = new \DateTimeImmutable();

        $this->assertSame($tagImage, $result);
        $this->assertSame($file, $tagImage->getImageFile());

        $this->assertNotNull($tagImage->getUpdatedAt());

        $this->assertGreaterThanOrEqual(
            $before,
            $tagImage->getUpdatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $tagImage->getUpdatedAt()
        );
    }

    public function testSetNullImageFileDoesNotChangeUpdatedAt(): void
    {
        $tagImage = new CategorieTagImage();
        $date = new \DateTimeImmutable('2026-09-06 12:00:00');

        $tagImage->setUpdatedAt($date);

        $result = $tagImage->setImageFile(null);

        $this->assertSame($tagImage, $result);
        $this->assertNull($tagImage->getImageFile());
        $this->assertSame($date, $tagImage->getUpdatedAt());
    }

    public function testToStringUsesCategoryTitleAndYear(): void
    {
        $categorie = new Categories();
        $categorie->setTitre('Musique');

        $tagImage = new CategorieTagImage();

        $tagImage
            ->setCategorie($categorie)
            ->setAnnee(2026);

        $this->assertSame(
            'Musique - 2026',
            (string) $tagImage
        );
    }

    public function testToStringWithoutCategoryUsesFallback(): void
    {
        $tagImage = new CategorieTagImage();

        $tagImage->setAnnee(2025);

        $this->assertSame(
            'Catégorie - 2025',
            (string) $tagImage
        );
    }

    public function testYearBelowMinimumIsInvalid(): void
    {
        $tagImage = new CategorieTagImage();

        $tagImage->setAnnee(1899);

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validateProperty($tagImage, 'annee');

        $this->assertGreaterThan(
            0,
            $violations->count()
        );
    }

    public function testYearAboveMaximumIsInvalid(): void
    {
        $tagImage = new CategorieTagImage();

        $tagImage->setAnnee(2101);

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validateProperty($tagImage, 'annee');

        $this->assertGreaterThan(
            0,
            $violations->count()
        );
    }

    public function testValidYearHasNoViolation(): void
    {
        $tagImage = new CategorieTagImage();

        $tagImage->setAnnee(2026);

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validateProperty($tagImage, 'annee');

        $this->assertCount(0, $violations);
    }
}
<?php

namespace App\Tests\Entity;

use App\Entity\Page;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Validation;

class PageTest extends TestCase
{
    public function testInitialValues(): void
    {
        $before = new \DateTime();

        $page = new Page();

        $after = new \DateTime();

        $this->assertNull($page->getId());
        $this->assertNull($page->getSlug());
        $this->assertNull($page->getTitle());
        $this->assertNull($page->getContent());
        $this->assertNull($page->getMainImageName());
        $this->assertNull($page->getMainImageFile());

        $this->assertFalse($page->getDeleteMainImage());

        $this->assertNotNull($page->getUpdatedAt());

        $this->assertGreaterThanOrEqual(
            $before,
            $page->getUpdatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $page->getUpdatedAt()
        );

        $this->assertSame('', (string) $page);
    }

    public function testBasicGettersAndSetters(): void
    {
        $page = new Page();

        $updatedAt = new \DateTime('2026-09-06 12:00:00');

        $result = $page
            ->setSlug('radio-zinzine')
            ->setTitle('Radio Zinzine')
            ->setContent('<p>Présentation</p>')
            ->setMainImageName('zinzine.webp')
            ->setUpdatedAt($updatedAt)
            ->setDeleteMainImage(true);

        $this->assertSame($page, $result);
        $this->assertSame('radio-zinzine', $page->getSlug());
        $this->assertSame('Radio Zinzine', $page->getTitle());
        $this->assertSame(
            '<p>Présentation</p>',
            $page->getContent()
        );
        $this->assertSame(
            'zinzine.webp',
            $page->getMainImageName()
        );
        $this->assertSame(
            $updatedAt,
            $page->getUpdatedAt()
        );
        $this->assertTrue($page->getDeleteMainImage());
    }

    public function testNullableImageName(): void
    {
        $page = new Page();

        $page->setMainImageName('image.webp');

        $page->setMainImageName(null);

        $this->assertNull($page->getMainImageName());
    }

    public function testDeleteMainImageCanBeTrueFalseOrNull(): void
    {
        $page = new Page();

        $page->setDeleteMainImage(true);
        $this->assertTrue($page->getDeleteMainImage());

        $page->setDeleteMainImage(false);
        $this->assertFalse($page->getDeleteMainImage());

        $page->setDeleteMainImage(null);
        $this->assertNull($page->getDeleteMainImage());
    }

    public function testToStringUsesTitleFirst(): void
    {
        $page = new Page();

        $page
            ->setSlug('a-propos')
            ->setTitle('À propos');

        $this->assertSame(
            'À propos',
            (string) $page
        );
    }

    public function testToStringFallsBackToSlug(): void
    {
        $page = new Page();

        $page->setSlug('a-propos');

        $this->assertSame(
            'a-propos',
            (string) $page
        );
    }

    public function testSetMainImageFileUpdatesUpdatedAt(): void
    {
        $page = new Page();

        $oldDate = new \DateTime('2000-01-01 00:00:00');

        $page->setUpdatedAt($oldDate);

        $before = new \DateTime();

        $file = new File(__FILE__);

        $result = $page->setMainImageFile($file);

        $after = new \DateTime();

        $this->assertSame($page, $result);
        $this->assertSame($file, $page->getMainImageFile());

        $this->assertGreaterThanOrEqual(
            $before,
            $page->getUpdatedAt()
        );

        $this->assertLessThanOrEqual(
            $after,
            $page->getUpdatedAt()
        );
    }

    public function testSettingNullImageFileDoesNotChangeUpdatedAt(): void
    {
        $page = new Page();

        $date = new \DateTime('2025-01-01 10:00:00');

        $page->setUpdatedAt($date);

        $page->setMainImageFile(null);

        $this->assertNull($page->getMainImageFile());

        $this->assertSame(
            $date,
            $page->getUpdatedAt()
        );
    }

    public function testSlugIsRequired(): void
    {
        $page = new Page();

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validateProperty(
            $page,
            'slug'
        );

        $this->assertGreaterThan(
            0,
            $violations->count()
        );
    }

    public function testTitleIsRequired(): void
    {
        $page = new Page();

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validateProperty(
            $page,
            'title'
        );

        $this->assertGreaterThan(
            0,
            $violations->count()
        );
    }

    public function testContentIsRequired(): void
    {
        $page = new Page();

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validateProperty(
            $page,
            'content'
        );

        $this->assertGreaterThan(
            0,
            $violations->count()
        );
    }

    public function testValidRequiredFieldsHaveNoViolations(): void
    {
        $page = new Page();

        $page
            ->setSlug('a-propos')
            ->setTitle('À propos')
            ->setContent('<p>Contenu</p>');

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $this->assertCount(
            0,
            $validator->validateProperty($page, 'slug')
        );

        $this->assertCount(
            0,
            $validator->validateProperty($page, 'title')
        );

        $this->assertCount(
            0,
            $validator->validateProperty($page, 'content')
        );
    }
}
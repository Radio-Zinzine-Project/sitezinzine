<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SiteImage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SiteImageTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->validator = self::getContainer()->get(
            ValidatorInterface::class
        );
    }

    public function testGettersAndSettersStoreSiteImageData(): void
    {
        $siteImage = new SiteImage();

        $updatedAt = new \DateTimeImmutable(
            '2026-09-26 14:30:00'
        );

        self::assertSame(
            $siteImage,
            $siteImage->setKey('playlist_day')
        );

        self::assertSame(
            $siteImage,
            $siteImage->setLabel('Playlist de jour')
        );

        self::assertSame(
            $siteImage,
            $siteImage->setDescription(
                'Image utilisée pour la playlist de jour.'
            )
        );

        self::assertSame(
            $siteImage,
            $siteImage->setThumbnail('playlist-day.webp')
        );

        self::assertSame(
            $siteImage,
            $siteImage->setUpdatedAt($updatedAt)
        );

        self::assertSame('playlist_day', $siteImage->getKey());
        self::assertSame(
            'Playlist de jour',
            $siteImage->getLabel()
        );
        self::assertSame(
            'Image utilisée pour la playlist de jour.',
            $siteImage->getDescription()
        );
        self::assertSame(
            'playlist-day.webp',
            $siteImage->getThumbnail()
        );
        self::assertSame(
            $updatedAt,
            $siteImage->getUpdatedAt()
        );
    }

    public function testSettingThumbnailFileUpdatesModificationDate(): void
    {
        $siteImage = new SiteImage();

        self::assertNull($siteImage->getUpdatedAt());

        $file = new File(__FILE__);

        $before = new \DateTimeImmutable();

        $result = $siteImage->setThumbnailFile($file);

        $after = new \DateTimeImmutable();

        self::assertSame($siteImage, $result);
        self::assertSame($file, $siteImage->getThumbnailFile());

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $siteImage->getUpdatedAt()
        );

        self::assertGreaterThanOrEqual(
            $before,
            $siteImage->getUpdatedAt()
        );

        self::assertLessThanOrEqual(
            $after,
            $siteImage->getUpdatedAt()
        );
    }

    public function testSettingNullThumbnailFileDoesNotChangeModificationDate(): void
    {
        $siteImage = new SiteImage();

        $initialDate = new \DateTimeImmutable(
            '2026-09-01 10:00:00'
        );

        $siteImage->setUpdatedAt($initialDate);

        $result = $siteImage->setThumbnailFile(null);

        self::assertSame($siteImage, $result);
        self::assertNull($siteImage->getThumbnailFile());
        self::assertSame(
            $initialDate,
            $siteImage->getUpdatedAt()
        );
    }

    public function testDescriptionAndThumbnailMayBeNull(): void
    {
        $siteImage = (new SiteImage())
            ->setKey('playlist_day')
            ->setLabel('Playlist de jour')
            ->setDescription(null)
            ->setThumbnail(null);

        $violations = $this->validator->validate($siteImage);

        self::assertCount(0, $violations);
        self::assertNull($siteImage->getDescription());
        self::assertNull($siteImage->getThumbnail());
    }

    /**
     * @dataProvider invalidRequiredValues
     */
    public function testKeyAndLabelCannotBeBlank(
        string $key,
        string $label,
        string $expectedProperty
    ): void {
        $siteImage = (new SiteImage())
            ->setKey($key)
            ->setLabel($label);

        $violations = $this->validator->validate($siteImage);

        self::assertGreaterThan(0, $violations->count());

        $properties = [];

        foreach ($violations as $violation) {
            $properties[] = $violation->getPropertyPath();
        }

        self::assertContains(
            $expectedProperty,
            $properties
        );
    }

    public static function invalidRequiredValues(): iterable
    {
        yield 'clé vide' => [
            '',
            'Playlist de jour',
            'key',
        ];

        yield 'libellé vide' => [
            'playlist_day',
            '',
            'label',
        ];
    }

    public function testKeyCannotExceedMaximumLength(): void
    {
        $siteImage = (new SiteImage())
            ->setKey(str_repeat('a', 101))
            ->setLabel('Playlist de jour');

        $violations = $this->validator->validate($siteImage);

        self::assertGreaterThan(0, $violations->count());

        $properties = [];

        foreach ($violations as $violation) {
            $properties[] = $violation->getPropertyPath();
        }

        self::assertContains('key', $properties);
    }

    public function testLabelCannotExceedMaximumLength(): void
    {
        $siteImage = (new SiteImage())
            ->setKey('playlist_day')
            ->setLabel(str_repeat('a', 256));

        $violations = $this->validator->validate($siteImage);

        self::assertGreaterThan(0, $violations->count());

        $properties = [];

        foreach ($violations as $violation) {
            $properties[] = $violation->getPropertyPath();
        }

        self::assertContains('label', $properties);
    }

    /**
     * @dataProvider stringRepresentations
     */
    public function testStringRepresentation(
        ?string $key,
        ?string $label,
        string $expected
    ): void {
        $siteImage = new SiteImage();

        if ($key !== null) {
            $siteImage->setKey($key);
        }

        if ($label !== null) {
            $siteImage->setLabel($label);
        }

        self::assertSame(
            $expected,
            (string) $siteImage
        );
    }

    public static function stringRepresentations(): iterable
    {
        yield 'libellé prioritaire' => [
            'playlist_day',
            'Playlist de jour',
            'Playlist de jour',
        ];

        yield 'clé utilisée sans libellé' => [
            'playlist_day',
            null,
            'playlist_day',
        ];

        yield 'entité vide' => [
            null,
            null,
            'Image du site',
        ];
    }
}
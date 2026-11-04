<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SafeFilenameNamer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Vich\UploaderBundle\Exception\NameGenerationException;
use Vich\UploaderBundle\Mapping\PropertyMapping;

final class SafeFilenameNamerTest extends TestCase
{
    public function testConfigureRejectsMissingProperty(): void
    {
        $namer = new SafeFilenameNamer();

        $this->expectException(
            \InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Option "property" is missing or empty.'
        );

        $namer->configure([]);
    }

    public function testConfigureRejectsEmptyProperty(): void
    {
        $namer = new SafeFilenameNamer();

        $this->expectException(
            \InvalidArgumentException::class
        );

        $this->expectExceptionMessage(
            'Option "property" is missing or empty.'
        );

        $namer->configure([
            'property' => '',
        ]);
    }

    public function testNameUsesDefaultTitreProperty(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 42;
            }

            public function getTitre(): string
            {
                return 'Émission du Soir';
            }
        };

        $mapping = $this->createMapping(
            $object,
            'MonFichier.MP3'
        );

        $namer = new SafeFilenameNamer();

        $result = $namer->name(
            $object,
            $mapping
        );

        self::assertSame(
            'emission-du-soir-42.mp3',
            $result
        );
    }

    public function testConfiguredPropertyIsUsed(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 7;
            }

            public function getNom(): string
            {
                return 'Mon autre fichier';
            }
        };

        $mapping = $this->createMapping(
            $object,
            'IMAGE.JPG'
        );

        $namer = new SafeFilenameNamer();

        $namer->configure([
            'property' => 'nom',
        ]);

        self::assertSame(
            'mon-autre-fichier-7.jpg',
            $namer->name(
                $object,
                $mapping
            )
        );
    }

    public function testSlugifiesAccentsSpacesAndSpecialCharacters(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 12;
            }

            public function getTitre(): string
            {
                return '  L’été : Rock & Roll !!!  ';
            }
        };

        $mapping = $this->createMapping(
            $object,
            'audio.WAV'
        );

        $namer = new SafeFilenameNamer();

        self::assertSame(
            'l-ete-rock-roll-12.wav',
            $namer->name(
                $object,
                $mapping
            )
        );
    }

    public function testRepeatedSeparatorsAreCollapsed(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 3;
            }

            public function getTitre(): string
            {
                return 'Test --- fichier /// radio';
            }
        };

        $mapping = $this->createMapping(
            $object,
            'test.PNG'
        );

        $namer = new SafeFilenameNamer();

        self::assertSame(
            'test-fichier-radio-3.png',
            $namer->name(
                $object,
                $mapping
            )
        );
    }

    public function testMissingConfiguredPropertyThrowsNameGenerationException(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 1;
            }
        };

        $mapping = $this->createMapping(
            $object,
            'audio.mp3'
        );

        $namer = new SafeFilenameNamer();

        $namer->configure([
            'property' => 'proprieteInexistante',
        ]);

        $this->expectException(
            NameGenerationException::class
        );

        $this->expectExceptionMessage(
            'File name could not be generated: property "proprieteInexistante" does not exist.'
        );

        $namer->name(
            $object,
            $mapping
        );
    }

    public function testEmptyPropertyValueThrowsNameGenerationException(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 5;
            }

            public function getTitre(): string
            {
                return '';
            }
        };

        $mapping = $this->createMapping(
            $object,
            'audio.mp3'
        );

        $namer = new SafeFilenameNamer();

        $this->expectException(
            NameGenerationException::class
        );

        $this->expectExceptionMessage(
            'File name could not be generated: property "titre" is empty.'
        );

        $namer->name(
            $object,
            $mapping
        );
    }

    public function testNullPropertyValueThrowsNameGenerationException(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 5;
            }

            public function getTitre(): ?string
            {
                return null;
            }
        };

        $mapping = $this->createMapping(
            $object,
            'audio.mp3'
        );

        $namer = new SafeFilenameNamer();

        $this->expectException(
            NameGenerationException::class
        );

        $this->expectExceptionMessage(
            'File name could not be generated: property "titre" is empty.'
        );

        $namer->name(
            $object,
            $mapping
        );
    }

    public function testObjectWithoutGetIdUsesGeneratedUniqueId(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getTitre(): string
            {
                return 'Sans identifiant';
            }
        };

        $mapping = $this->createMapping(
            $object,
            'audio.MP3'
        );

        $namer = new SafeFilenameNamer();

        $result = $namer->name(
            $object,
            $mapping
        );

        self::assertMatchesRegularExpression(
            '/^sans-identifiant-[a-f0-9]+\.mp3$/',
            $result
        );
    }

    public function testZeroIdIsKept(): void
    {
        $object = new class {
            public ?UploadedFile $file = null;

            public function getId(): int
            {
                return 0;
            }

            public function getTitre(): string
            {
                return 'Test';
            }
        };

        $mapping = $this->createMapping(
            $object,
            'audio.mp3'
        );

        $namer = new SafeFilenameNamer();

        self::assertSame(
            'test-0.mp3',
            $namer->name(
                $object,
                $mapping
            )
        );
    }

    private function createMapping(
        object $object,
        string $originalFilename
    ): PropertyMapping {
        $file = $this->createMock(
            UploadedFile::class
        );

        $file
            ->method('getClientOriginalExtension')
            ->willReturn(
                pathinfo(
                    $originalFilename,
                    PATHINFO_EXTENSION
                )
            );

        $object->file = $file;

        return new PropertyMapping(
            'file',
            'fileName'
        );
    }
}
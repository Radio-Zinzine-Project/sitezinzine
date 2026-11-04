<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Theme;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ThemeDoctrineTest extends KernelTestCase
{
    use DoctrineTestTrait;

    protected function setUp(): void
    {

        $this->bootDoctrine();
    }

    protected function tearDown(): void
    {
        $this->closeEntityManager();

        parent::tearDown();
    }

    public function testThemeCanBePersistedAndReloaded(): void
    {
        $theme = new Theme();
        $theme
            ->setName('Test PHPUnit')
            ->setThumbnail('test.jpg')
            ->setUpdatedAt(new \DateTime('2026-09-05 20:00:00'));

        $this->entityManager->persist($theme);
        $this->entityManager->flush();

        self::assertNotNull($theme->getId());

        $themeId = $theme->getId();

        $this->clearEntityManager();

        $savedTheme = $this->entityManager
            ->getRepository(Theme::class)
            ->find($themeId);

        self::assertInstanceOf(Theme::class, $savedTheme);
        self::assertSame('Test PHPUnit', $savedTheme->getName());
        self::assertSame('test.jpg', $savedTheme->getThumbnail());
        self::assertSame(
            '2026-09-05 20:00:00',
            $savedTheme->getUpdatedAt()?->format('Y-m-d H:i:s')
        );

        $this->entityManager->remove($savedTheme);
        $this->entityManager->flush();

        $this->clearEntityManager();

        self::assertNull(
            $this->entityManager
                ->getRepository(Theme::class)
                ->find($themeId)
        );
    }
}
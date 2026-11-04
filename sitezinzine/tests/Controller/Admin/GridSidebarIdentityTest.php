<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class GridSidebarIdentityTest extends KernelTestCase
{
    /** @dataProvider postitSources */
    public function testPostitTransmitsTheSourceIdentityWithoutConfusingTables(bool $manual, bool $published): void
    {
        self::bootKernel();
        $days = array_fill(0, 7, []);
        $manualDays = $days;
        // Même ID numérique possible dans chaque table : le type est indispensable.
        $source = [
            'id' => 42, 'draftId' => $published ? null : 42,
            'diffusionId' => $published ? 42 : null,
            'sourceType' => $published ? 'diffusion' : 'draft',
            'startIndex' => 56, 'duration' => 60,
            'startsAt' => '2026-10-06 14:00:00', 'endsAt' => '2026-10-06 15:00:00',
            'categoryTitle' => 'Catégorie', 'categorySlug' => 'CAT',
            'title' => 'Émission', 'emissionTitle' => 'Émission', 'emissionId' => 20,
            'assigned' => true, 'broadcastRank' => 1, 'nombreDiffusion' => 1,
            'assignmentGroupKey' => 'sidebar_identity',
            'draftType' => $published ? 'validated_diffusion' : 'manual_special',
        ];
        if ($manual) {
            $manualDays[0][] = $source;
        } else {
            $days[0][] = $source;
        }
        $html = static::getContainer()->get(Environment::class)->render('admin/grille/_grid.html.twig', [
            'startOfWeek' => new \DateTime('2026-10-06'),
            'daySegments' => $days, 'manualDraftsByDay' => $manualDays,
        ]);
        $postit = (new Crawler($html))->filter('.postit');
        self::assertCount(1, $postit);
        self::assertSame('click->grid#selectSlot', $postit->attr('data-action'));
        if ($published) {
            if ($manual) {
                self::assertSame('', $postit->attr('data-draft-id', ''), 'Un ID de Diffusion ne devient jamais un draftId.');
            }
            self::assertSame('42', $postit->attr('data-diffusion-id', ''), 'Une Diffusion doit exposer son diffusionId.');
            self::assertSame('', $postit->attr('data-draft-id', ''), 'Un ID de Diffusion ne devient jamais un draftId.');
        } else {
            self::assertSame('42', $postit->attr('data-draft-id', ''));
            self::assertSame('', $postit->attr('data-diffusion-id', ''));
        }
    }

    public static function postitSources(): iterable
    {
        yield 'regular draft' => [false, false];
        yield 'regular diffusion' => [false, true];
        yield 'manual draft' => [true, false];
        yield 'manual diffusion' => [true, true];
    }
}

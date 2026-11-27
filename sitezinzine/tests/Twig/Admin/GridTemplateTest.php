<?php

declare(strict_types=1);

namespace App\Tests\Twig\Admin;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class GridTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->twig = static::getContainer()->get(
            Environment::class
        );
    }

    /**
     * @dataProvider manualDiffusionRankCases
     */
    public function testManualDiffusionBadgeUsesNombreDiffusion(
        string $draftType,
        int $nombreDiffusion,
        string $expectedBadge
    ): void {
        $html = $this->renderGridWithManualDiffusion(
            $draftType,
            $nombreDiffusion
        );

        self::assertStringContainsString(
            $expectedBadge,
            $this->normalizeWhitespace($html)
        );
    }

    public static function manualDiffusionRankCases(): iterable
    {
        yield 'draft première diffusion' => [
            'manual_special',
            1,
            '1re diff',
        ];

        yield 'draft première rediffusion' => [
            'manual_rebroadcast',
            2,
            '↺ Rediff 1',
        ];

        /*
         * Régression du 30/09/2026 :
         *
         * une Diffusion validée possède draftType=validated_diffusion.
         * Le rang doit donc être déterminé par nombreDiffusion et non
         * par le draftType.
         */
        yield 'diffusion validée première diffusion' => [
            'validated_diffusion',
            1,
            '1re diff',
        ];

        yield 'diffusion validée première rediffusion' => [
            'validated_diffusion',
            2,
            '↺ Rediff 1',
        ];

        yield 'diffusion validée deuxième rediffusion' => [
            'validated_diffusion',
            3,
            '↺ Rediff 2',
        ];
    }

    public function testValidatedManualRebroadcastIsStyledAsRebroadcast(): void
    {
        $html = $this->renderGridWithManualDiffusion(
            'validated_diffusion',
            2
        );

        self::assertStringContainsString(
            'postit--manual-rebroadcast',
            $html
        );
    }

    public function testValidatedFirstManualDiffusionIsNotStyledAsRebroadcast(): void
    {
        $html = $this->renderGridWithManualDiffusion(
            'validated_diffusion',
            1
        );

        self::assertStringNotContainsString(
            'postit--manual-rebroadcast',
            $html
        );
    }

    public function testCompactGridKeepsRealIndicesAndHasOneBreak(): void
    {
        $grid = $this->gridXPath();
        self::assertSame(7, $grid->query('//*[@data-day-index]')->length);
        self::assertStringContainsString('Mardi', $grid->query('//*[contains(@class, "head__day")]')->item(0)->textContent);
        self::assertStringContainsString('Lundi', $grid->query('//*[contains(@class, "head__day")]')->item(6)->textContent);
        $expected = [...range(0, 3), ...range(26, 95)];
        foreach ($grid->query('//*[@data-day-index]') as $day) {
            $indices = [];
            foreach ($grid->query('./div[contains(@class, "day-slot")]/@data-start-index', $day) as $index) {
                $indices[] = (int) $index->nodeValue;
            }
            self::assertSame($expected, $indices);
        }

        $bands = $grid->query('//*[contains(concat(" ", @class, " "), " schedule-break ")]');
        self::assertSame(1, $bands->length);
        foreach (['01:00', '06:30', 'Plage sans programmation'] as $text) {
            self::assertStringContainsString($text, $bands->item(0)->textContent);
        }
        self::assertSame(0, $grid->query('.//*[@tabindex or @data-action or @data-start-index]', $bands->item(0))->length);

        $root = $grid->query('//*[@data-slot-minutes]')->item(0);
        self::assertNotNull($root);
        foreach ([
            'slot-minutes' => '15', 'slot-height' => '14', 'day-slots' => '96',
            'break-start' => '4', 'break-end' => '26', 'break-height' => '28',
        ] as $name => $value) {
            self::assertSame($value, $root->getAttribute('data-' . $name));
        }
        foreach (['--slot-h:14px', '--break-top:56px', '--break-h:28px', '--compression:280px', '--time-h:1064px'] as $property) {
            self::assertStringContainsString($property, $root->getAttribute('style'));
        }
    }

    public function testTheSameCompactAxisIsRenderedInConsultation(): void
    {
        $grid = $this->gridXPath('diffusion');
        self::assertSame(7 * 74, $grid->query('//*[contains(@class, "day-slot")]')->length);
        self::assertSame(18, $grid->query('//*[contains(@class, "hour-label")]')->length);
        self::assertSame(1, $grid->query('//*[contains(concat(" ", @class, " "), " schedule-break ")]')->length);
        self::assertStringContainsString('--compression:280px', $grid->query('//*[@data-slot-minutes]')->item(0)->getAttribute('style'));
    }

    public function testCompactGridProjectsPostitsWithoutChangingTheirHeightsOrIdentity(): void
    {
        $grid = $this->gridXPath();
        foreach ([
            ['@data-slot-id="10"', 3, 0, 1, '00:45', 'postit--first'],
            ['@data-slot-id="11"', 26, 1, 2, '06:30', 'postit--rescheduled'],
            ['@data-draft-id="12"', 27, 1, 2, '06:45', 'postit--override'],
            ['@data-diffusion-id="13"', 32, 1, 2, '08:00', 'postit--manual-rebroadcast'],
        ] as [$selector, $index, $offset, $slots, $time, $variant]) {
            $postit = $grid->query("//*[$selector]")->item(0);
            self::assertNotNull($postit);
            self::assertSame((string) $index, $postit->getAttribute('data-start-index'));
            self::assertStringContainsString("2026-09-29 $time:00", $postit->getAttribute('data-starts-at'));
            self::assertStringContainsString("--time-index:$index", $postit->getAttribute('style'));
            self::assertStringContainsString("--after-break:$offset", $postit->getAttribute('style'));
            self::assertStringContainsString("height:calc($slots * var(--slot-h) - 2px)", $postit->getAttribute('style'));
            self::assertStringContainsString($variant, $postit->getAttribute('class'));
        }
        $arbitrated = $grid->query('//*[@data-slot-id="11"]')->item(0);
        self::assertSame('true', $arbitrated->getAttribute('data-is-projected-override'));
        self::assertSame('2026-09-29 06:46:00', $arbitrated->getAttribute('data-ends-at'));
        self::assertSame('16', $arbitrated->getAttribute('data-duration'));
        self::assertSame('manual_test', $grid->query('//*[@data-diffusion-id="13"]')->item(0)->getAttribute('data-assignment-group-key'));
    }

    private function gridXPath(string $gridMode = 'draft'): \DOMXPath
    {
        $published = [
            'id' => 13, 'diffusionId' => 13, 'startIndex' => 32, 'duration' => 30,
            'startsAt' => '2026-09-29 08:00:00', 'title' => 'Rediffusion',
            'categoryTitle' => 'Catégorie test', 'assignmentGroupKey' => 'manual_test',
            'draftType' => 'validated_diffusion', 'nombreDiffusion' => 2,
        ];
        $context = [
            'gridMode' => $gridMode,
            'startOfWeek' => new \DateTimeImmutable('2026-09-29'),
            'daySegments' => [[
                ['slotId' => 10, 'startIndex' => 3, 'duration' => 15, 'startsAt' => '2026-09-29 00:45:00'],
                [
                    'slotId' => 11, 'startIndex' => 26, 'duration' => 16,
                    'startsAt' => '2026-09-29 06:30:00', 'endsAt' => '2026-09-29 06:46:00',
                    'isProjectedOverride' => true,
                ],
            ]],
            'manualDraftsByDay' => [[
                [
                    'id' => 12, 'startIndex' => 27, 'duration' => 30, 'startsAt' => '2026-09-29 06:45:00',
                    'title' => 'Émission de test', 'categoryTitle' => 'Catégorie test',
                ],
                $published,
            ]],
        ];
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML(
            '<meta charset="UTF-8">' . $this->twig->render('admin/grille/_grid.html.twig', $context),
            LIBXML_NOERROR,
        ));

        return new \DOMXPath($document);
    }

    private function renderGridWithManualDiffusion(
        string $draftType,
        int $nombreDiffusion
    ): string {
        $manualDiffusion = [
            'id' => 100,
            'startIndex' => 36,
            'duration' => 60,
            'startsAt' => '2026-09-29 09:00:00',
            'endsAt' => '2026-09-29 10:00:00',

            'title' => 'Émission de test',
            'categoryTitle' => 'Catégorie test',
            'categorySlug' => 'TST',

            'draftType' => $draftType,

            'emissionId' => 11919,
            'assigned' => true,
            'isManualDraft' => true,
            'emissionIsAutoGenerated' => false,

            'broadcastRank' => $nombreDiffusion,
            'nombreDiffusion' => $nombreDiffusion,

            'assignmentGroupKey' => 'manual_test',
        ];

        /*
         * _grid.html.twig travaille sur 7 journées.
         *
         * On place uniquement notre diffusion de test dans le premier
         * jour et on laisse le reste de la grille vide.
         */
        $manualDraftsByDay = [
            [$manualDiffusion],
            [],
            [],
            [],
            [],
            [],
            [],
        ];

        $daySegments = [
            [],
            [],
            [],
            [],
            [],
            [],
            [],
        ];

        return $this->twig->render(
            'admin/grille/_grid.html.twig',
            [
                'gridMode' => 'diffusion',

                'manualDraftsByDay' => $manualDraftsByDay,
                'daySegments' => $daySegments,

                /*
                 * Contexte minimal nécessaire à la structure de la grille.
                 */
                'startOfWeek' => new \DateTimeImmutable(
                    '2026-09-29 00:00:00'
                ),

                'days' => [
                    'Mardi',
                    'Mercredi',
                    'Jeudi',
                    'Vendredi',
                    'Samedi',
                    'Dimanche',
                    'Lundi',
                ],

                'hours' => range(0, 23),

                'slotMinutes' => 15,
                'slotsPerHour' => 4,
                'slotsPerDay' => 96,

                'isReadOnly' => true,
            ]
        );
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $value
            ) ?? $value
        );
    }
}

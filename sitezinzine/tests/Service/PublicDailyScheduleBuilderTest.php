<?php

namespace App\Tests\Service;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Service\PublicDailyScheduleBuilder;
use App\Service\PublicScheduleBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class PublicDailyScheduleBuilderTest extends TestCase
{
    private \DateTimeZone $timezone;
    private PublicDailyScheduleBuilder $builder;

    protected function setUp(): void
    {
        $this->timezone = new \DateTimeZone('Europe/Paris');

        /*
         * buildFromWeeklySchedule() ne fait aucun appel à
         * PublicScheduleBuilder.
         *
         * On crée donc une instance sans constructeur de la dépendance
         * finale afin de tester uniquement la transformation quotidienne.
         */
        $reflection = new ReflectionClass(PublicScheduleBuilder::class);

        /** @var PublicScheduleBuilder $publicScheduleBuilder */
        $publicScheduleBuilder = $reflection->newInstanceWithoutConstructor();

        $this->builder = new PublicDailyScheduleBuilder(
            $publicScheduleBuilder
        );
    }

    public function testNightGapCreatesNightAndDayPlaylistsWithCorrectImageKeys(): void
    {
        $weeklySchedule = $this->createWeeklySchedule([]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 03:00:00'
        );

        self::assertArrayHasKey('items', $result);
        self::assertArrayHasKey('activeIndex', $result);

        $items = $result['items'];

        self::assertCount(2, $items);

        /*
     * Playlist de nuit.
     *
     * Elle couvre la plage réservée à la playlist nocturne
     * et doit exposer la clé utilisée par l'accueil pour
     * récupérer l'image SiteImage correspondante.
     */
        $nightPlaylist = $items[0];

        self::assertSame('playlist', $nightPlaylist['type']);
        self::assertSame('Playlist de nuit', $nightPlaylist['title']);
        self::assertSame('Playlist', $nightPlaylist['label']);

        self::assertArrayHasKey('playlistKey', $nightPlaylist);
        self::assertSame(
            'playlist_night',
            $nightPlaylist['playlistKey']
        );

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $nightPlaylist['startsAt']
        );

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $nightPlaylist['endsAt']
        );

        self::assertSame(
            '2026-09-08 01:00:00',
            $nightPlaylist['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-08 06:30:00',
            $nightPlaylist['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertTrue($nightPlaylist['isCurrent']);

        /*
     * Playlist de jour.
     *
     * En l'absence de programmation après 06h30,
     * elle prend le relais jusqu'à minuit et doit
     * exposer sa propre clé d'image.
     */
        $dayPlaylist = $items[1];

        self::assertSame('playlist', $dayPlaylist['type']);
        self::assertSame('Musiques et chansons', $dayPlaylist['title']);
        self::assertSame('Playlist', $dayPlaylist['label']);

        self::assertArrayHasKey('playlistKey', $dayPlaylist);
        self::assertSame(
            'playlist_day',
            $dayPlaylist['playlistKey']
        );

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $dayPlaylist['startsAt']
        );

        self::assertInstanceOf(
            \DateTimeImmutable::class,
            $dayPlaylist['endsAt']
        );

        self::assertSame(
            '2026-09-08 06:30:00',
            $dayPlaylist['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-09 00:00:00',
            $dayPlaylist['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertFalse($dayPlaylist['isCurrent']);

        /*
     * À 03h00, seule la playlist nocturne est courante.
     */
        self::assertSame(0, $result['activeIndex']);

        $currentItems = array_values(
            array_filter(
                $items,
                static fn(array $item): bool => ($item['isCurrent'] ?? false) === true
            )
        );

        self::assertCount(1, $currentItems);
        self::assertSame(
            'playlist_night',
            $currentItems[0]['playlistKey']
        );
    }

    public function testNoVirtualPlaylistIsCreatedBetweenMidnightAndOne(): void
    {
        $weeklySchedule = $this->createWeeklySchedule([]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 00:30:00'
        );

        $items = $result['items'];

        self::assertCount(2, $items);

        /*
         * La première carte virtuelle ne doit commencer qu'à 01h00.
         *
         * 00h00 → 01h00 doit être fourni par la vraie programmation
         * correspondant à la rediffusion des infos.
         */
        self::assertSame(
            '2026-09-08 01:00:00',
            $items[0]['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertFalse($items[0]['isCurrent']);
        self::assertFalse($items[1]['isCurrent']);

        /*
         * En l'absence anormale de la rediffusion des infos,
         * aucune carte virtuelle ne doit prétendre être à l'antenne.
         */
        self::assertSame(0, $result['activeIndex']);
    }

    public function testRealMidnightInformationDiffusionIsKept(): void
    {
        $category = new Categories();
        $category->setTitre('Informations');

        $emission = new Emission();
        $emission
            ->setTitre('Rediffusion des informations')
            ->setCategorie($category);

        $diffusion = new Diffusion();
        $diffusion
            ->setEmission($emission)
            ->setHoraireDiffusion(
                new \DateTime('2026-09-08 00:00:00')
            );

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 00:00:00',
                '2026-09-08 01:00:00',
                $emission,
                $category,
                $diffusion
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 00:30:00'
        );

        $items = $result['items'];

        self::assertCount(3, $items);

        self::assertSame('diffusion', $items[0]['type']);
        self::assertSame($emission, $items[0]['emission']);
        self::assertSame($category, $items[0]['category']);
        self::assertTrue($items[0]['isCurrent']);

        self::assertSame(
            '2026-09-08 00:00:00',
            $items[0]['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-08 01:00:00',
            $items[0]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame('Playlist de nuit', $items[1]['title']);
        self::assertSame(
            '2026-09-08 01:00:00',
            $items[1]['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame(0, $result['activeIndex']);
    }

    public function testNightPlaylistCanFillOnlyPartOfNightGap(): void
    {
        $category = new Categories();
        $category->setTitre('Nuit');

        $emission = new Emission();
        $emission
            ->setTitre('Émission nocturne')
            ->setCategorie($category);

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 03:00:00',
                '2026-09-08 04:00:00',
                $emission,
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 02:00:00'
        );

        $items = $result['items'];

        self::assertCount(4, $items);

        self::assertSame('Playlist de nuit', $items[0]['title']);
        self::assertSame(
            '2026-09-08 01:00:00',
            $items[0]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 03:00:00',
            $items[0]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame('diffusion', $items[1]['type']);

        self::assertSame('Playlist de nuit', $items[2]['title']);
        self::assertSame(
            '2026-09-08 04:00:00',
            $items[2]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 06:30:00',
            $items[2]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame('Musiques et chansons', $items[3]['title']);
        self::assertSame(
            '2026-09-08 06:30:00',
            $items[3]['startsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testBuildCreatesClassicPlaylistBetweenTwoDaytimeItems(): void
    {
        $category = new Categories();
        $category->setTitre('Catégorie');

        $firstEmission = new Emission();
        $firstEmission
            ->setTitre('Première émission')
            ->setCategorie($category);

        $secondEmission = new Emission();
        $secondEmission
            ->setTitre('Deuxième émission')
            ->setCategorie($category);

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 08:00:00',
                '2026-09-08 09:00:00',
                $firstEmission,
                $category
            ),
            $this->createDiffusionItem(
                '2026-09-08 10:30:00',
                '2026-09-08 11:30:00',
                $secondEmission,
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 09:30:00'
        );

        $items = $result['items'];

        /*
         * 01h-06h30 : Playlist de nuit
         * 06h30-08h : Playlist classique
         * 08h-09h : émission
         * 09h-10h30 : Playlist classique
         * 10h30-11h30 : émission
         * 11h30-24h : Playlist classique
         */
        self::assertCount(6, $items);

        self::assertSame('Playlist de nuit', $items[0]['title']);
        self::assertSame('Musiques et chansons', $items[1]['title']);

        self::assertSame('diffusion', $items[2]['type']);

        self::assertSame('playlist', $items[3]['type']);
        self::assertSame('Musiques et chansons', $items[3]['title']);
        self::assertSame(
            '2026-09-08 09:00:00',
            $items[3]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 10:30:00',
            $items[3]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertTrue($items[3]['isCurrent']);
        self::assertSame(3, $result['activeIndex']);

        self::assertSame('diffusion', $items[4]['type']);
        self::assertSame('playlist', $items[5]['type']);
    }

    public function testEmptyRegularSlotIsKeptAndNotReplacedByPlaylist(): void
    {
        $category = new Categories();
        $category->setTitre('Magazine');

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createEmptyRegularItem(
                '2026-09-08 10:00:00',
                '2026-09-08 11:00:00',
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 10:30:00'
        );

        $items = $result['items'];

        self::assertCount(4, $items);

        self::assertSame('Playlist de nuit', $items[0]['title']);
        self::assertSame('Musiques et chansons', $items[1]['title']);

        self::assertSame('empty_regular', $items[2]['type']);
        self::assertSame($category, $items[2]['category']);
        self::assertNull($items[2]['emission']);
        self::assertTrue($items[2]['isRegular']);
        self::assertTrue($items[2]['isCurrent']);

        self::assertSame('playlist', $items[3]['type']);

        self::assertSame(2, $result['activeIndex']);
    }

    public function testNightPlaylistCanBeCurrentItem(): void
    {
        $weeklySchedule = $this->createWeeklySchedule([]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 03:15:00'
        );

        self::assertSame(
            'Playlist de nuit',
            $result['items'][0]['title']
        );

        self::assertTrue($result['items'][0]['isCurrent']);
        self::assertFalse($result['items'][1]['isCurrent']);

        self::assertSame(0, $result['activeIndex']);
    }

    public function testClassicPlaylistCanBeCurrentItem(): void
    {
        $weeklySchedule = $this->createWeeklySchedule([]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 12:00:00'
        );

        self::assertSame(
            'Musiques et chansons',
            $result['items'][1]['title']
        );

        self::assertTrue($result['items'][1]['isCurrent']);
        self::assertFalse($result['items'][0]['isCurrent']);

        self::assertSame(1, $result['activeIndex']);
    }

    public function testDiffusionCanBeCurrentItem(): void
    {
        $category = new Categories();
        $category->setTitre('Catégorie');

        $emission = new Emission();
        $emission
            ->setTitre('Émission')
            ->setCategorie($category);

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 14:00:00',
                '2026-09-08 15:00:00',
                $emission,
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 14:30:00'
        );

        $currentItems = array_values(
            array_filter(
                $result['items'],
                static fn(array $item): bool => $item['isCurrent']
            )
        );

        self::assertCount(1, $currentItems);
        self::assertSame('diffusion', $currentItems[0]['type']);
        self::assertSame($emission, $currentItems[0]['emission']);
    }

    public function testItemsAreSortedBeforePlaylistGapsAreCreated(): void
    {
        $category = new Categories();
        $category->setTitre('Catégorie');

        $morningEmission = new Emission();
        $morningEmission
            ->setTitre('Matin')
            ->setCategorie($category);

        $eveningEmission = new Emission();
        $eveningEmission
            ->setTitre('Soir')
            ->setCategorie($category);

        /*
         * On fournit volontairement les éléments dans le mauvais ordre.
         */
        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 18:00:00',
                '2026-09-08 19:00:00',
                $eveningEmission,
                $category
            ),
            $this->createDiffusionItem(
                '2026-09-08 08:00:00',
                '2026-09-08 09:00:00',
                $morningEmission,
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 12:00:00'
        );

        $items = $result['items'];

        self::assertCount(6, $items);

        self::assertSame('Playlist de nuit', $items[0]['title']);
        self::assertSame('Musiques et chansons', $items[1]['title']);

        self::assertSame('diffusion', $items[2]['type']);
        self::assertSame($morningEmission, $items[2]['emission']);

        self::assertSame('playlist', $items[3]['type']);
        self::assertSame(
            '2026-09-08 09:00:00',
            $items[3]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 18:00:00',
            $items[3]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame('diffusion', $items[4]['type']);
        self::assertSame($eveningEmission, $items[4]['emission']);

        self::assertSame('playlist', $items[5]['type']);
    }

    public function testOverlappingItemsDoNotCreatePlaylistInsideOverlap(): void
    {
        $category = new Categories();
        $category->setTitre('Catégorie');

        $firstEmission = new Emission();
        $firstEmission
            ->setTitre('Première')
            ->setCategorie($category);

        $secondEmission = new Emission();
        $secondEmission
            ->setTitre('Deuxième')
            ->setCategorie($category);

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 10:00:00',
                '2026-09-08 12:00:00',
                $firstEmission,
                $category
            ),
            $this->createDiffusionItem(
                '2026-09-08 11:00:00',
                '2026-09-08 13:00:00',
                $secondEmission,
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 09:30:00'
        );

        $playlistItems = array_values(
            array_filter(
                $result['items'],
                static fn(array $item): bool =>
                $item['type'] === 'playlist'
            )
        );

        /*
         * 01h-06h30
         * 06h30-10h
         * 13h-24h
         *
         * Rien ne doit être ajouté entre 10h et 13h malgré
         * le chevauchement des deux émissions.
         */
        self::assertCount(3, $playlistItems);

        self::assertSame(
            '2026-09-08 01:00:00',
            $playlistItems[0]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 06:30:00',
            $playlistItems[0]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-08 06:30:00',
            $playlistItems[1]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 10:00:00',
            $playlistItems[1]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-09-08 13:00:00',
            $playlistItems[2]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-09 00:00:00',
            $playlistItems[2]['endsAt']->format('Y-m-d H:i:s')
        );
    }

    public function testAdjacentProgrammedItemsDoNotCreatePlaylistBetweenThem(): void
    {
        $category = new Categories();
        $category->setTitre('Catégorie');

        $firstEmission = new Emission();
        $firstEmission
            ->setTitre('Première')
            ->setCategorie($category);

        $secondEmission = new Emission();
        $secondEmission
            ->setTitre('Deuxième')
            ->setCategorie($category);

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 10:00:00',
                '2026-09-08 11:00:00',
                $firstEmission,
                $category
            ),
            $this->createDiffusionItem(
                '2026-09-08 11:00:00',
                '2026-09-08 12:00:00',
                $secondEmission,
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 11:30:00'
        );

        $items = $result['items'];

        self::assertCount(5, $items);

        self::assertSame('Playlist de nuit', $items[0]['title']);
        self::assertSame('Musiques et chansons', $items[1]['title']);
        self::assertSame('diffusion', $items[2]['type']);
        self::assertSame('diffusion', $items[3]['type']);
        self::assertSame('playlist', $items[4]['type']);

        self::assertSame(
            '2026-09-08 11:00:00',
            $items[3]['startsAt']->format('Y-m-d H:i:s')
        );

        self::assertTrue($items[3]['isCurrent']);
        self::assertSame(3, $result['activeIndex']);
    }

    public function testItemEndingAfterMidnightIsClippedToEndOfDay(): void
    {
        $category = new Categories();
        $category->setTitre('Nuit');

        $emission = new Emission();
        $emission
            ->setTitre('Émission de nuit')
            ->setCategorie($category);

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 23:00:00',
                '2026-09-09 01:00:00',
                $emission,
                $category
            ),
        ]);

        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 23:30:00'
        );

        $items = $result['items'];

        self::assertCount(3, $items);

        self::assertSame('Playlist de nuit', $items[0]['title']);

        self::assertSame('playlist', $items[1]['type']);
        self::assertSame('Musiques et chansons', $items[1]['title']);
        self::assertSame(
            '2026-09-08 06:30:00',
            $items[1]['startsAt']->format('Y-m-d H:i:s')
        );
        self::assertSame(
            '2026-09-08 23:00:00',
            $items[1]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertSame('diffusion', $items[2]['type']);
        self::assertSame(
            '2026-09-09 00:00:00',
            $items[2]['endsAt']->format('Y-m-d H:i:s')
        );

        self::assertTrue($items[2]['isCurrent']);
        self::assertSame(2, $result['activeIndex']);
    }

    public function testCurrentItemChangesExactlyAtBoundary(): void
    {
        $category = new Categories();
        $category->setTitre('Catégorie');

        $emission = new Emission();
        $emission
            ->setTitre('Émission')
            ->setCategorie($category);

        $weeklySchedule = $this->createWeeklySchedule([
            $this->createDiffusionItem(
                '2026-09-08 10:00:00',
                '2026-09-08 11:00:00',
                $emission,
                $category
            ),
        ]);

        /*
         * Exactement à 11h00, l'émission terminée ne doit plus
         * être courante. La Playlist classique suivante prend le relais.
         */
        $result = $this->buildForTuesday(
            $weeklySchedule,
            '2026-09-08 11:00:00'
        );

        $items = $result['items'];

        self::assertCount(4, $items);

        self::assertSame('diffusion', $items[2]['type']);
        self::assertFalse($items[2]['isCurrent']);

        self::assertSame('playlist', $items[3]['type']);
        self::assertSame(
            'Musiques et chansons',
            $items[3]['title']
        );
        self::assertTrue($items[3]['isCurrent']);

        self::assertSame(3, $result['activeIndex']);
    }

    private function buildForTuesday(
        array $weeklySchedule,
        string $now
    ): array {
        return $this->builder->buildFromWeeklySchedule(
            $weeklySchedule,
            new \DateTimeImmutable(
                '2026-09-08',
                $this->timezone
            ),
            new \DateTimeImmutable(
                $now,
                $this->timezone
            )
        );
    }

    private function createWeeklySchedule(
        array $items
    ): array {
        return [
            'startOfWeek' => new \DateTimeImmutable(
                '2026-09-08 00:00:00',
                $this->timezone
            ),
            'endOfWeek' => new \DateTimeImmutable(
                '2026-09-14 00:00:00',
                $this->timezone
            ),
            'days' => [
                [
                    'date' => new \DateTimeImmutable(
                        '2026-09-08 00:00:00',
                        $this->timezone
                    ),
                    'key' => '2026-09-08',
                    'items' => $items,
                ],
            ],
        ];
    }

    private function createDiffusionItem(
        string $startsAt,
        string $endsAt,
        Emission $emission,
        Categories $category,
        ?Diffusion $diffusion = null
    ): array {
        return [
            'type' => 'diffusion',
            'startsAt' => new \DateTimeImmutable(
                $startsAt,
                $this->timezone
            ),
            'endsAt' => new \DateTimeImmutable(
                $endsAt,
                $this->timezone
            ),
            'emission' => $emission,
            'category' => $category,
            'diffusion' => $diffusion,
            'isRegular' => true,
        ];
    }

    private function createEmptyRegularItem(
        string $startsAt,
        string $endsAt,
        Categories $category
    ): array {
        return [
            'type' => 'empty_regular',
            'startsAt' => new \DateTimeImmutable(
                $startsAt,
                $this->timezone
            ),
            'endsAt' => new \DateTimeImmutable(
                $endsAt,
                $this->timezone
            ),
            'emission' => null,
            'category' => $category,
            'diffusion' => null,
            'isRegular' => true,
        ];
    }
}

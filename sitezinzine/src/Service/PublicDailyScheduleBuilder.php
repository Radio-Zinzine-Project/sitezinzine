<?php

namespace App\Service;

final class PublicDailyScheduleBuilder
{
    private const TIMEZONE = 'Europe/Paris';

    public function __construct(
        private readonly PublicScheduleBuilder $publicScheduleBuilder,
    ) {}

    /**
     * Construit la programmation publique complète d'une journée
     * à partir du programme hebdomadaire public.
     *
     * @return array{
     *     items: array<int, array>,
     *     activeIndex: int
     * }
     */
    public function build(
        \DateTimeInterface $date,
        \DateTimeInterface $now
    ): array {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        $dayStart = new \DateTimeImmutable(
            $date->format('Y-m-d 00:00:00'),
            $timezone
        );

        /*
     * PublicScheduleBuilder travaille sur une semaine radio
     * allant du mardi au lundi.
     */
        $dayOfWeek = (int) $dayStart->format('N');
        $daysSinceTuesday = ($dayOfWeek + 5) % 7;

        $radioWeekStart = $dayStart->modify(
            sprintf('-%d days', $daysSinceTuesday)
        );

        $weeklySchedule = $this->publicScheduleBuilder->build(
            $radioWeekStart
        );

        return $this->buildFromWeeklySchedule(
            $weeklySchedule,
            $date,
            $now
        );
    }

    /**
     * Construit la programmation quotidienne à partir d'un programme
     * hebdomadaire déjà calculé.
     *
     * Cette méthode permet notamment de tester toute la logique quotidienne
     * indépendamment de PublicScheduleBuilder.
     *
     * Les périodes sans aucune programmation sont complétées par
     * des cartes virtuelles "Musiques et chansons / Playlist".
     *
     * @return array{
     *     items: array<int, array{
     *         type: string,
     *         startsAt: \DateTimeImmutable,
     *         endsAt: \DateTimeImmutable,
     *         diffusion: \DateTimeImmutable,
     *         endDiffusion: \DateTimeImmutable,
     *         emission: mixed,
     *         category: mixed,
     *         isRegular: bool,
     *         isCurrent: bool
     *     }>,
     *     activeIndex: int
     * }
     */
    public function buildFromWeeklySchedule(
        array $weeklySchedule,
        \DateTimeInterface $date,
        \DateTimeInterface $now
    ): array {
        $timezone = new \DateTimeZone(self::TIMEZONE);

        $dayStart = new \DateTimeImmutable(
            $date->format('Y-m-d 00:00:00'),
            $timezone
        );

        $dayEnd = $dayStart->modify('+1 day');

        $nowLocal = \DateTimeImmutable::createFromInterface($now)
            ->setTimezone($timezone);

        $dayItems = $this->extractDayItems(
            $weeklySchedule,
            $dayStart
        );

        /*
     * On normalise les éléments fournis par PublicScheduleBuilder
     * pour conserver les noms utilisés actuellement par le carrousel :
     *
     * diffusion
     * endDiffusion
     */
        $items = [];

        foreach ($dayItems as $item) {
            $startsAt = $this->toLocalImmutable(
                $item['startsAt'],
                $timezone
            );

            $endsAt = $this->toLocalImmutable(
                $item['endsAt'],
                $timezone
            );

            /*
         * Le carrousel quotidien ne doit jamais sortir
         * de la journée demandée.
         */
            if ($startsAt < $dayStart) {
                $startsAt = $dayStart;
            }

            if ($endsAt > $dayEnd) {
                $endsAt = $dayEnd;
            }

            /*
         * On ignore un éventuel élément devenu vide ou invalide
         * après limitation aux bornes de la journée.
         */
            if ($endsAt <= $startsAt) {
                continue;
            }

            $items[] = [
                'type' => $item['type'],

                'startsAt' => $startsAt,
                'endsAt' => $endsAt,

                /*
             * Compatibilité avec les noms actuellement utilisés
             * par le Twig du carrousel.
             */
                'diffusion' => $startsAt,
                'endDiffusion' => $endsAt,

                'emission' => $item['emission'] ?? null,
                'category' => $item['category'] ?? null,
                'isRegular' => $item['isRegular'] ?? false,

                'isCurrent' => false,
            ];
        }

        /*
     * On garantit l'ordre chronologique avant de rechercher
     * les trous de programmation.
     */
        usort(
            $items,
            static fn(array $a, array $b): int =>
            $a['startsAt'] <=> $b['startsAt']
        );

        /*
     * On complète uniquement les véritables trous.
     *
     * Un empty_regular est déjà un élément du programme :
     * il ne sera donc jamais remplacé par une playlist.
     */
        $items = $this->fillPlaylistGaps(
            $items,
            $dayStart,
            $dayEnd
        );

        /*
     * Détermination de la carte actuellement à l'antenne.
     *
     * On travaille avec des intervalles [début, fin[ :
     * exactement à l'heure de fin d'une carte,
     * c'est la carte suivante qui devient courante.
     */
        foreach ($items as &$item) {
            $item['isCurrent'] =
                $nowLocal >= $item['startsAt']
                && $nowLocal < $item['endsAt'];
        }

        unset($item);

        $activeIndex = $this->resolveActiveIndex(
            $items,
            $nowLocal
        );

        return [
            'items' => $items,
            'activeIndex' => $activeIndex,
        ];
    }

    /**
     * Récupère uniquement la journée demandée dans le résultat
     * de PublicScheduleBuilder.
     */
    private function extractDayItems(
        array $weeklySchedule,
        \DateTimeImmutable $dayStart
    ): array {
        $dayKey = $dayStart->format('Y-m-d');

        foreach ($weeklySchedule['days'] ?? [] as $day) {
            if (($day['key'] ?? null) !== $dayKey) {
                continue;
            }

            return $day['items'] ?? [];
        }

        return [];
    }

    /**
     * Insère une carte Playlist dans chaque période sans programmation.
     *
     * Les cartes régulières vides sont déjà présentes dans $items :
     * elles ne sont donc jamais remplacées par une playlist.
     */
    private function fillPlaylistGaps(
        array $items,
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $dayEnd
    ): array {
        $result = [];
        $cursor = $dayStart;

        foreach ($items as $item) {
            $startsAt = $item['startsAt'];
            $endsAt = $item['endsAt'];

            /*
         * Il existe un trou entre la fin du dernier élément
         * et le début de celui-ci.
         */
            if ($startsAt > $cursor) {
                $result = array_merge(
                    $result,
                    $this->createPlaylistItemsForGap(
                        $cursor,
                        $startsAt,
                        $dayStart
                    )
                );
            }

            $result[] = $item;

            /*
         * En cas de chevauchement historique, on ne fait jamais
         * reculer le curseur.
         */
            if ($endsAt > $cursor) {
                $cursor = $endsAt;
            }
        }

        /*
     * Complète également la fin de journée.
     */
        if ($cursor < $dayEnd) {
            $result = array_merge(
                $result,
                $this->createPlaylistItemsForGap(
                    $cursor,
                    $dayEnd,
                    $dayStart
                )
            );
        }

        return $result;
    }

    private function createNightPlaylistItem(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt
    ): array {
        return [
            'type' => 'playlist',

            'startsAt' => $startsAt,
            'endsAt' => $endsAt,

            'diffusion' => $startsAt,
            'endDiffusion' => $endsAt,

            'emission' => null,
            'category' => null,
            'isRegular' => false,
            'isCurrent' => false,

            'playlistKey' => 'playlist_night',
            'title' => 'Playlist de nuit',
            'label' => 'Playlist',
        ];
    }

    private function createPlaylistItemsForGap(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        \DateTimeImmutable $dayStart
    ): array {
        $items = [];

        $nightStart = $dayStart->setTime(1, 0);
        $nightEnd = $dayStart->setTime(6, 30);

        /*
     * 00h00 → 01h00 :
     *
     * On ne crée volontairement aucune carte virtuelle.
     *
     * Cette période correspond à la rediffusion des infos
     * et doit être fournie par la programmation réelle.
     *
     * Si elle manque dans les données, on laisse le trou visible
     * au lieu de le remplacer à tort par une Playlist.
     */

        /*
     * 01h00 → 06h30 :
     * musique + rediffusions d'anciennes émissions.
     *
     * Toute partie du trou comprise dans cette plage devient
     * une "Playlist de nuit".
     */
        $nightSegmentStart = $startsAt > $nightStart
            ? $startsAt
            : $nightStart;

        $nightSegmentEnd = $endsAt < $nightEnd
            ? $endsAt
            : $nightEnd;

        if ($nightSegmentEnd > $nightSegmentStart) {
            $items[] = $this->createNightPlaylistItem(
                $nightSegmentStart,
                $nightSegmentEnd
            );
        }

        /*
     * Après 06h30 :
     * les véritables trous de programmation redeviennent
     * une Playlist classique "Musiques et chansons".
     */
        $classicPlaylistStart = $startsAt > $nightEnd
            ? $startsAt
            : $nightEnd;

        if ($endsAt > $classicPlaylistStart) {
            $items[] = $this->createPlaylistItem(
                $classicPlaylistStart,
                $endsAt
            );
        }

        return $items;
    }

    /**
     * Une playlist est purement virtuelle :
     * aucune Emission, Category ou Diffusion n'est créée en base.
     */
    private function createPlaylistItem(
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt
    ): array {
        return [
            'type' => 'playlist',

            'startsAt' => $startsAt,
            'endsAt' => $endsAt,

            // Compatibilité avec le Twig actuel.
            'diffusion' => $startsAt,
            'endDiffusion' => $endsAt,

            'emission' => null,
            'category' => null,
            'isRegular' => false,
            'isCurrent' => false,

            /*
         * Ces valeurs sont du contenu d'affichage,
         * pas des données métier persistées.
         */
            'playlistKey' => 'playlist_day',
            'title' => 'Musiques et chansons',
            'label' => 'Playlist',
        ];
    }

    /**
     * Choisit la carte sur laquelle Glide doit démarrer.
     *
     * Priorité :
     * 1. carte actuellement en cours ;
     * 2. dernière carte déjà commencée ;
     * 3. première carte.
     */
    private function resolveActiveIndex(
        array $items,
        \DateTimeImmutable $now
    ): int {
        $activeIndex = 0;

        foreach ($items as $index => $item) {
            if ($item['isCurrent']) {
                return $index;
            }

            if ($item['startsAt'] <= $now) {
                $activeIndex = $index;
            }
        }

        return $activeIndex;
    }

    private function toLocalImmutable(
        \DateTimeInterface $date,
        \DateTimeZone $timezone
    ): \DateTimeImmutable {
        /*
         * Les horaires de grille sont des heures locales Europe/Paris.
         * On conserve donc la même convention que l'ancien
         * findProgramForDate().
         */
        return new \DateTimeImmutable(
            $date->format('Y-m-d H:i:s'),
            $timezone
        );
    }
}

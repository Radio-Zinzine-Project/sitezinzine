<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Selection;

use App\Repository\DiffusionRepository;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use App\Service\RadioWeek;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Read-only projection of published occurrences and their declared audio references. */
final readonly class WeeklyAudioSelection
{
    private AudioLocalUrlResolver $localUrls;

    public function __construct(
        private DiffusionRepository $diffusions,
        #[Autowire('%app.mp3_public_base_url%')]
        string $canonicalUrl,
        #[Autowire('%app.mp3_deferred_cleanup.local_url_prefixes%')]
        string $configuredPrefixes,
    ) {
        $this->localUrls = AudioLocalUrlResolver::fromConfiguredLibrary($canonicalUrl, $configuredPrefixes)
            ?? new AudioLocalUrlResolver([$canonicalUrl]);
    }

    /**
     * The bounds are Paris calendar dates. Audio references do not establish file availability.
     *
     * @return array{
     *     weekStart: \DateTimeImmutable, weekEnd: \DateTimeImmutable,
     *     occurrences: list<array{
     *         diffusionId: ?int, emissionId: ?int, emissionTitle: string, categoryCode: ?string, startsAt: \DateTimeImmutable,
     *         mp3Path: ?string, url: ?string, localUrlPath: ?string, live: bool, deleted: bool,
     *         audioKind: 'local_reference'|'external_url'|'uncertain_url'|'none'
     *     }>
     * }
     */
    public function select(\DateTimeImmutable $date): array
    {
        $weekStart = RadioWeek::start($date->setTimezone(new \DateTimeZone('Europe/Paris')));
        $weekEnd = $weekStart->modify('+7 days');
        $occurrences = [];
        foreach ($this->diffusions->findPublishedByWeek($weekStart, $weekEnd) as $diffusion) {
            $emission = $diffusion->getEmission();
            $startsAt = $diffusion->getHoraireDiffusion();
            if ($emission === null || $startsAt === null) {
                throw new \UnexpectedValueException('A published diffusion must have an emission and an effective start time.');
            }

            $occurrences[] = $this->occurrence(
                $diffusion->getId(), $emission->getId(), $emission->getTitre(), $emission->getCategorie()?->getSlug(),
                \DateTimeImmutable::createFromInterface($startsAt), $emission->getThumbnailMp3(), $emission->getUrl(),
                $emission->isLive(), $emission->isDeleted()
            );
        }

        return ['weekStart' => $weekStart, 'weekEnd' => $weekEnd, 'occurrences' => $occurrences];
    }

    /** Builds the same projection from the SQL rows used to calculate the captured revision. */
    public function fromSnapshot(\DateTimeImmutable $weekStart, array $rows): array
    {
        $occurrences = [];
        foreach ($rows as $row) {
            if ($row['emission_id'] === null || $row['horaire_diffusion'] === null) {
                throw new \UnexpectedValueException('A published diffusion must have an emission and an effective start time.');
            }
            $occurrences[] = $this->occurrence(
                (int) $row['diffusion_id'], (int) $row['emission_id'], $row['emission_title'], $row['category_code'],
                new \DateTimeImmutable($row['horaire_diffusion'], $weekStart->getTimezone()),
                $row['thumbnail_mp3'], $row['url'], (bool) $row['is_live'], (bool) $row['deleted']
            );
        }

        return ['weekStart' => $weekStart, 'weekEnd' => $weekStart->modify('+7 days'), 'occurrences' => $occurrences];
    }

    private function occurrence(
        ?int $diffusionId, ?int $emissionId, string $title, ?string $category, \DateTimeImmutable $startsAt,
        ?string $path, ?string $url, bool $live, bool $deleted,
    ): array {
        $resolvedUrl = $this->localUrls->resolve($url);

        return [
            'diffusionId' => $diffusionId,
            'emissionId' => $emissionId,
            'emissionTitle' => $title,
            'categoryCode' => $category,
            'startsAt' => $startsAt,
            'mp3Path' => $path,
            'url' => $url,
            'localUrlPath' => $resolvedUrl['relative_path'],
            'live' => $live,
            'deleted' => $deleted,
            'audioKind' => match (true) {
                ($path !== null && $path !== '') || $resolvedUrl['status'] === 'local' => 'local_reference',
                $resolvedUrl['status'] === 'external' => 'external_url',
                $resolvedUrl['status'] === 'uncertain' => 'uncertain_url',
                default => 'none',
            },
        ];
    }
}

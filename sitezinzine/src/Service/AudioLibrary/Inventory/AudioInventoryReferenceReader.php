<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Scalar reference pages only: no physical observations or inventory conclusions. */
final readonly class AudioInventoryReferenceReader
{
    public function __construct(
        private Connection $connection,
        private AudioLocalUrlResolver $urlResolver,
    ) {}

    /**
     * Reads at most 500 emissions, including logically deleted ones. A full
     * page requires another read to establish exhaustion. The cursor advances
     * over emissions, not associations; an empty page preserves afterId.
     *
     * exhausted only describes this SQL page. It does not establish reference
     * completeness, resolve uncertain URLs or guarantee a cross-page snapshot.
     * The caller owns any transaction; this reader never starts or ends one.
     * Physical field bytes are retained, not validated against a filesystem.
     * Uncertain URLs are identified for later lookup without copying secrets.
     *
     * @return array{
     *     associations:list<array{storage:string, relative_path:string, emission_id:int, origin:string, thumbnail_missing:bool}>,
     *     uncertainties:list<array{emission_id:int, field:string, reason:string}>,
     *     next_after_id:int,
     *     exhausted:bool
     * }
     */
    public function readPage(int $afterId = 0, int $limit = 500): array
    {
        if ($afterId < 0 || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Expected a nonnegative cursor and a page size between 1 and 500.');
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, thumbnail_mp3, url FROM emission WHERE id > :afterId ORDER BY id ASC LIMIT :limit',
            ['afterId' => $afterId, 'limit' => $limit],
            ['afterId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER],
        );

        $associations = [];
        $uncertainties = [];
        $nextAfterId = $afterId;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $nextAfterId = $id;
            $physical = $row['thumbnail_mp3'];
            $missing = $physical === null || $physical === '';
            if (!$missing) {
                $associations[] = $this->association($id, $physical, 'thumbnailMp3', false);
            }

            $url = $this->urlResolver->resolve($row['url']);
            if ($url['status'] === 'local') {
                $associations[] = $this->association($id, $url['relative_path'], 'local_audio_url', $missing);
            } elseif ($url['status'] === 'uncertain') {
                $uncertainties[] = ['emission_id' => $id, 'field' => 'url', 'reason' => 'uncertain_local_url'];
            }
        }

        return [
            'associations' => $associations,
            'uncertainties' => $uncertainties,
            'next_after_id' => $nextAfterId,
            'exhausted' => count($rows) < $limit,
        ];
    }

    private function association(int $id, string $path, string $origin, bool $missing): array
    {
        return [
            'storage' => 'emissionsMp3',
            'relative_path' => $path,
            'emission_id' => $id,
            'origin' => $origin,
            'thumbnail_missing' => $missing,
        ];
    }
}

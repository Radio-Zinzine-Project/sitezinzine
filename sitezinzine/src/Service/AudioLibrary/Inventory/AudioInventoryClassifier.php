<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Classifies collected observations; never authorizes a file operation. */
final class AudioInventoryClassifier
{
    /**
     * Inputs are normalized by collectors, not by this policy. A local_audio_url
     * association must already be positively recognized as a local library URL.
     * referencesComplete covers both physical-field and recognized-URL searches,
     * including emissions retained by logical deletion. false includes unknown.
     * present=null means that physical presence could not be established.
     * Historical reasons are informational only, never current reference evidence.
     *
     * @param list<array{storage:string, relative_path:string, emission_id:positive-int, origin:'thumbnailMp3'|'local_audio_url', thumbnail_missing:bool}> $associations
     * @param list<string> $historicalCleanupReasons
     * @return array{storage:string, relative_path:string, classification:string, emission_ids:list<int>, missing_thumbnail_emission_ids:list<int>}
     */
    public function classify(
        string $storage,
        string $relativePath,
        ?bool $present,
        bool $referencesComplete,
        array $associations,
        array $historicalCleanupReasons = [],
    ): array {
        $emissions = [];
        $missingThumbnails = [];
        $physicalAssociations = [];
        foreach ($associations as $association) {
            if ($association['storage'] !== $storage || $association['relative_path'] !== $relativePath) {
                continue;
            }
            $id = $association['emission_id'];
            $emissions[$id] = $id;
            if ($association['origin'] === 'thumbnailMp3') {
                $physicalAssociations[$id] = true;
            } elseif ($association['origin'] === 'local_audio_url' && $association['thumbnail_missing']) {
                $missingThumbnails[$id] = $id;
            }
        }
        $missingThumbnails = array_diff_key($missingThumbnails, $physicalAssociations);

        $classification = match (true) {
            count($emissions) > 1 => 'multiple_associations',
            $present === true && $emissions !== [] => 'associated',
            $present === false && $emissions !== [] => 'broken_reference',
            $present === true && $referencesComplete => 'orphan_candidate',
            default => 'indeterminate',
        };

        return [
            'storage' => $storage,
            'relative_path' => $relativePath,
            'classification' => $classification,
            'emission_ids' => array_values($emissions),
            'missing_thumbnail_emission_ids' => array_values($missingThumbnails),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\ValueObject\AudioLibrary;

final readonly class Mp3CleanupAnomaly
{
    /**
     * @param array{replacement_relative_path:string, commit_confirmed:bool, reference_count:?int} $context
     * @param array{class:class-string<\Throwable>, code:int|string, sql_state:?string} $diagnostic
     */
    public function __construct(
        public string $reason,
        public int $emissionId,
        public string $obsoleteRelativePath,
        public array $context,
        public array $diagnostic,
        public ?Mp3CleanupHistoricalIdentity $historicalIdentity = null,
    ) {}
}

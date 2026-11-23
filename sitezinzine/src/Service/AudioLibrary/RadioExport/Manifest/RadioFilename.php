<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\RadioExport\Manifest;

use App\Service\Mp3FilenameTitle;

final class RadioFilename
{
    private const MARKERS = <<<'REGEX'
        ~(?<![\p{L}\p{N}])
        (?:
            n\x{00B0}?\h*(?<number>[0-9]+)
            | (?:[e\x{00E9}]pisode|partie|part)\h+(?<part>[0-9]+)(?:/[0-9]+(?!/))?
            | (?<!/)(?<fraction>[0-9]+)/[0-9]+(?!/)
        )
        (?![\p{L}\p{N}])~iux
        REGEX;

    // Common basename ceiling for Linux filesystems. Qualify the radio storage separately.
    private const MAX_FILENAME_BYTES = 255;

    /** @return array{filename: ?string, issues: list<string>} */
    public function format(?string $category, \DateTimeInterface $startsAt, string $title): array
    {
        if ($category === null || preg_match('/\A[A-Z]{3}\z/', $category) !== 1) {
            return ['filename' => null, 'issues' => ['invalid_category_code']];
        }

        if (preg_match_all(self::MARKERS, $title, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            return ['filename' => null, 'issues' => ['invalid_title']];
        }
        $numbers = array_values(array_filter(array_column($matches, 'number'), static fn (?string $value): bool => $value !== null));
        $parts = array_values(array_unique(array_filter(
            [...array_column($matches, 'part'), ...array_column($matches, 'fraction')],
            static fn (?string $value): bool => $value !== null,
        )));
        if (count($numbers) > 1 || count($parts) > 1) {
            return ['filename' => null, 'issues' => ['ambiguous_title_markers']];
        }

        $effective = \DateTimeImmutable::createFromInterface($startsAt)->setTimezone(new \DateTimeZone('Europe/Paris'));
        $prefix = $category . 'd' . $effective->format("y-m-d\\tH'i's");
        static $transliterator;
        $transliterator ??= \Transliterator::create('Latin-ASCII');
        if ($transliterator === null) {
            throw new \RuntimeException('The ICU Latin-ASCII transliterator is unavailable.');
        }
        $transliterated = $transliterator->transliterate(preg_replace(self::MARKERS, ' ', $title) ?? $title);
        if ($transliterated === false) {
            return ['filename' => null, 'issues' => ['invalid_title']];
        }
        $radioTitle = Mp3FilenameTitle::fromTransliterated($transliterated);
        $filename = implode('-', [$prefix, ...$numbers, ...$parts, $radioTitle]) . '.mp3';
        if (strlen($filename) > self::MAX_FILENAME_BYTES) {
            return ['filename' => null, 'issues' => ['filename_too_long']];
        }

        return ['filename' => $filename, 'issues' => []];
    }
}

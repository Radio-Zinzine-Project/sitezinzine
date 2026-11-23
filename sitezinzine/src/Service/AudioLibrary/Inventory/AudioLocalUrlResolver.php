<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Pure recognition of explicitly configured public library prefixes. */
final class AudioLocalUrlResolver
{
    /** @var list<array{scheme:string, host:string, prefix:string}> */
    private array $bases = [];

    /**
     * Bases must be absolute HTTP(S) URLs with a nonempty library prefix.
     * A single trailing slash is optional. Ports, credentials, query strings,
     * fragments and ambiguous path syntax are not supported configuration.
     *
     * @param list<string> $allowedBaseUrls
     */
    public function __construct(array $allowedBaseUrls)
    {
        if ($allowedBaseUrls === []) {
            throw new \InvalidArgumentException('At least one explicit local audio base URL is required.');
        }
        foreach ($allowedBaseUrls as $base) {
            if (!is_string($base) || $this->hasUnsafeCharacters($base)) {
                throw new \InvalidArgumentException('Invalid local audio base URL.');
            }
            $parts = parse_url($base);
            $path = $parts['path'] ?? '';
            // Remove only the optional directory delimiter, never path components.
            $relativePrefix = str_starts_with($path, '/') ? substr($path, 1) : '';
            if (str_ends_with($relativePrefix, '/')) {
                $relativePrefix = substr($relativePrefix, 0, -1);
            }
            if ($parts === false || empty($parts['host'])
                || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || $this->hasExtraComponents($parts)
                || !$this->isSafeRelativePath($relativePrefix)) {
                throw new \InvalidArgumentException('Expected an unambiguous absolute HTTP(S) library base URL.');
            }
            $this->bases[] = [
                'scheme' => strtolower($parts['scheme']),
                'host' => strtolower($parts['host']),
                'prefix' => '/' . $relativePrefix . '/',
            ];
        }
    }

    public static function fromConfiguredLibrary(string $canonicalUrl, string $configuredPrefixes): ?self
    {
        $prefixes = json_decode($configuredPrefixes, true);
        if (!is_array($prefixes) || !array_is_list($prefixes) || $prefixes === []) {
            return null;
        }
        try {
            return new self([$canonicalUrl, ...$prefixes]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Allows canonical-only corbeille configuration while rejecting malformed alias lists. */
    public static function forTrash(string $canonicalUrl, string $configuredPrefixes): self
    {
        if (json_decode($configuredPrefixes) === []) {
            return new self([$canonicalUrl]);
        }

        return self::fromConfiguredLibrary($canonicalUrl, $configuredPrefixes)
            ?? throw new \InvalidArgumentException('Invalid MP3 library URL aliases.');
    }

    /**
     * No decoding, trimming, filesystem lookup or path case folding is performed.
     * Only scheme and host use their case-insensitive URL semantics.
     * A local result identifies a path, not physical presence or audio integrity.
     *
     * @return array{status:'local'|'external'|'uncertain'|'absent', relative_path:?string}
     */
    public function resolve(?string $url): array
    {
        if ($url === null || $url === '') {
            return $this->result('absent');
        }
        // parse_url can replace control characters: reject them before parsing.
        if ($this->hasUnsafeCharacters($url)) {
            return $this->result('uncertain');
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $this->result('uncertain');
        }
        $host = strtolower($parts['host']);
        $bases = array_filter($this->bases, static fn (array $base): bool => $base['host'] === $host);
        if ($bases === []) {
            return $this->result('external');
        }
        $path = $parts['path'] ?? '';
        if ($this->hasExtraComponents($parts)
            || ($path !== '' && (!$this->isSafeRelativePath(substr($path, 1)) || !str_starts_with($path, '/')))) {
            return $this->result('uncertain');
        }

        $relativePath = null;
        $potentiallyLocal = false;
        foreach ($bases as $base) {
            if ($path === rtrim($base['prefix'], '/')
                || strncasecmp($path, $base['prefix'], strlen($base['prefix'])) === 0) {
                $potentiallyLocal = true;
            }
            if (strtolower($parts['scheme']) !== $base['scheme'] || !str_starts_with($path, $base['prefix'])) {
                continue;
            }
            $candidate = substr($path, strlen($base['prefix']));
            if (!$this->isSafeRelativePath($candidate)) {
                return $this->result('uncertain');
            }
            if ($relativePath !== null && $relativePath !== $candidate) {
                return $this->result('uncertain');
            }
            $relativePath = $candidate;
        }
        if ($relativePath !== null) {
            return $this->result('local', $relativePath);
        }

        return $this->result($potentiallyLocal ? 'uncertain' : 'external');
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || $this->hasUnsafeCharacters($path) || str_contains($path, '%') || str_contains($path, ':')) {
            return false;
        }
        foreach (explode('/', $path) as $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                return false;
            }
        }

        return true;
    }

    private function hasUnsafeCharacters(string $value): bool
    {
        return str_contains($value, '\\') || preg_match('/[\x00-\x20\x7f]/', $value) === 1;
    }

    private function hasExtraComponents(array $parts): bool
    {
        foreach (['user', 'pass', 'port', 'query', 'fragment'] as $component) {
            if (array_key_exists($component, $parts)) {
                return true;
            }
        }

        return false;
    }

    private function result(string $status, ?string $relativePath = null): array
    {
        return ['status' => $status, 'relative_path' => $relativePath];
    }
}

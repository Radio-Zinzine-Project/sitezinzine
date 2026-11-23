<?php

declare(strict_types=1);

namespace App\ValueObject\AudioLibrary;

/** Immutable evidence tied to the event that reported the cleanup failure. */
final readonly class Mp3CleanupHistoricalIdentity
{
    private function __construct(
        public string $state,
        public ?string $storage = null,
        public ?string $relativePath = null,
        public ?string $originalUrl = null,
        public ?array $identity = null,
    ) {}

    public static function capture(string $storage, string $relativePath, ?string $originalUrl, ?array $identity): self
    {
        if (preg_match('//u', $storage) !== 1 || preg_match('//u', $relativePath) !== 1
            || ($originalUrl !== null && preg_match('//u', $originalUrl) !== 1)) {
            return new self('unusable');
        }
        if ($identity === null) {
            return new self('missing', $storage, $relativePath, $originalUrl);
        }

        $physical = [];
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
            if (!is_int($identity[$key] ?? null)) {
                return new self('unusable', $storage, $relativePath, $originalUrl);
            }
            $physical[$key] = $identity[$key];
        }
        $sha = $identity['sha256'] ?? null;
        if ($physical['dev'] < 0 || $physical['ino'] <= 0 || $physical['size'] <= 0
            || !is_string($sha) || preg_match('/\A[a-f0-9]{64}\z/', $sha) !== 1) {
            return new self('unusable', $storage, $relativePath, $originalUrl);
        }

        return new self('complete', $storage, $relativePath, $originalUrl, $physical + ['sha256' => $sha]);
    }

    /** Legacy events have no evidence key. Malformed evidence is never repaired on read. */
    public static function restore(mixed $record): self
    {
        if ($record === null) {
            return new self('missing');
        }
        if (!is_array($record) || !is_string($record['storage'] ?? null) || !is_string($record['relative_path'] ?? null)
            || !array_key_exists('original_url', $record)
            || ($record['original_url'] !== null && !is_string($record['original_url']))) {
            return new self('unusable');
        }
        $state = $record['state'] ?? null;
        if ($state === 'complete' && is_array($record['identity'] ?? null)) {
            return self::capture($record['storage'], $record['relative_path'], $record['original_url'], $record['identity']);
        }

        return new self($state === 'missing' && array_key_exists('identity', $record) && $record['identity'] === null
            ? 'missing' : 'unusable', $record['storage'], $record['relative_path'], $record['original_url']);
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'storage' => $this->storage,
            'relative_path' => $this->relativePath,
            'original_url' => $this->originalUrl,
            'identity' => $this->identity,
        ];
    }
}

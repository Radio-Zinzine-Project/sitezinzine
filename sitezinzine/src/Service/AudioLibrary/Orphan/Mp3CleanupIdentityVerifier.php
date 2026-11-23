<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Orphan;

use App\Service\Mp3StoragePath;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;

/** Read-only observation. A future mutator must revalidate under the cooperative storage lock. */
final readonly class Mp3CleanupIdentityVerifier
{
    public function __construct(private string $libraryDir) {}

    /** @param iterable<Mp3CleanupHistoricalIdentity> $proofs All events for the incident, from a consistent read. */
    public function check(string $relative, iterable $proofs): Mp3CleanupIdentityDecision
    {
        return $this->observe($relative, $proofs, false);
    }

    /** Absence needs no historical fingerprint, but contradictory evidence blocks closure. */
    public function checkAbsenceForClosure(string $relative, iterable $proofs): Mp3CleanupIdentityDecision
    {
        return $this->observe($relative, $proofs, true);
    }

    private function observe(string $relative, iterable $proofs, bool $forClosure): Mp3CleanupIdentityDecision
    {
        try {
            $path = Mp3StoragePath::resolve($this->libraryDir, $relative);
        } catch (\RuntimeException) {
            return Mp3CleanupIdentityDecision::UnsafePath;
        }
        $current = self::stat($path);
        if ($current === false) {
            $entries = @scandir(dirname($path));

            if ($entries === false || in_array(basename($path), $entries, true) || self::stat($path) !== false) {
                return Mp3CleanupIdentityDecision::Indeterminate;
            }
            if (!$forClosure) {
                return Mp3CleanupIdentityDecision::Absent;
            }
        } elseif (($current['mode'] & 0170000) !== 0100000) {
            return Mp3CleanupIdentityDecision::UnsafePath;
        }
        if ($current !== false && $current['ino'] <= 0) {
            return Mp3CleanupIdentityDecision::Indeterminate;
        }

        $identity = null;
        $missing = $unusable = false;
        foreach ($proofs as $proof) {
            if ($proof->state === 'missing') {
                $missing = true;
                continue;
            }
            if ($proof->state !== 'complete') {
                $unusable = true;
                continue;
            }
            if ($proof->storage !== 'emissionsMp3' || $proof->relativePath !== $relative
                || ($identity !== null && $identity !== $proof->identity)) {
                return Mp3CleanupIdentityDecision::ConflictingProofs;
            }
            $identity = $proof->identity;
        }
        if ($unusable) {
            return Mp3CleanupIdentityDecision::UnusableProof;
        }
        if ($current === false) {
            return Mp3CleanupIdentityDecision::Absent;
        }
        if ($forClosure) {
            return Mp3CleanupIdentityDecision::Present;
        }
        if ($missing || $identity === null) {
            return Mp3CleanupIdentityDecision::MissingProof;
        }
        if (!self::matchesStat($current, $identity)) {
            return Mp3CleanupIdentityDecision::Different;
        }

        return $this->verifyContent($relative, $path, $identity);
    }

    private function verifyContent(string $relative, string $path, array $identity): Mp3CleanupIdentityDecision
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return Mp3CleanupIdentityDecision::Indeterminate;
        }
        try {
            // Check the descriptor and the resolved name before reading any bytes.
            if (!self::matchesStat(fstat($stream), $identity) || !$this->matchesPath($relative, $path, $identity)) {
                return Mp3CleanupIdentityDecision::Indeterminate;
            }
            $hash = hash_init('sha256');
            if (@hash_update_stream($hash, $stream, $identity['size']) !== $identity['size']
                || !self::matchesStat(fstat($stream), $identity) || !$this->matchesPath($relative, $path, $identity)) {
                return Mp3CleanupIdentityDecision::Indeterminate;
            }

            return hash_equals($identity['sha256'], hash_final($hash))
                ? Mp3CleanupIdentityDecision::Confirmed : Mp3CleanupIdentityDecision::Different;
        } finally {
            fclose($stream);
        }
    }

    private function matchesPath(string $relative, string $path, array $identity): bool
    {
        try {
            return Mp3StoragePath::resolve($this->libraryDir, $relative) === $path
                && self::matchesStat(self::stat($path), $identity);
        } catch (\RuntimeException) {
            return false;
        }
    }

    private static function matchesStat(array|false $actual, array $expected): bool
    {
        if ($actual === false || ($actual['mode'] & 0170000) !== 0100000) {
            return false;
        }
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
            if ($actual[$key] !== $expected[$key]) {
                return false;
            }
        }

        return true;
    }

    private static function stat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }
}

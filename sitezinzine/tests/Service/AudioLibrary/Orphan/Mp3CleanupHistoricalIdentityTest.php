<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIdentityVerifier;
use App\Tests\Support\Mp3TestStorage;
use App\ValueObject\AudioLibrary\Mp3CleanupHistoricalIdentity;
use PHPUnit\Framework\TestCase;

final class Mp3CleanupHistoricalIdentityTest extends TestCase
{
    /** @dataProvider scenarios */
    public function testIdentity(string $scenario, string $expected): void
    {
        $library = Mp3TestStorage::library();
        $path = bin2hex(random_bytes(8)) . '.mp3';
        $file = $library . '/' . $path;
        self::assertSame(5, file_put_contents($file, 'audio'));
        $original = self::capture($file);
        $proof = Mp3CleanupHistoricalIdentity::capture('emissionsMp3', $path, null, $original);
        $proofs = [$proof];
        try {
            switch ($scenario) {
                case 'absent':
                    self::assertTrue(unlink($file));
                    break;
                case 'missing':
                    $proofs = [Mp3CleanupHistoricalIdentity::restore(null)];
                    break;
                case 'unusable':
                    $proofs = [Mp3CleanupHistoricalIdentity::restore(['state' => 'complete'])];
                    break;
                case 'changed':
                    self::assertSame(7, file_put_contents($file, 'changed'));
                    break;
                case 'different hash':
                    $original['sha256'] = hash('sha256', 'other');
                    $proofs = [Mp3CleanupHistoricalIdentity::capture('emissionsMp3', $path, null, $original)];
                    break;
                case 'new inode':
                    self::assertTrue(rename($file, $file . '.original'));
                    self::assertSame(5, file_put_contents($file, 'audio'));
                    self::assertNotSame($original['ino'], self::capture($file)['ino']);
                    break;
                case 'symlink':
                    self::assertTrue(rename($file, $file . '.original'));
                    self::assertTrue(symlink($file . '.original', $file));
                    break;
                case 'directory':
                    self::assertTrue(unlink($file));
                    self::assertTrue(mkdir($file));
                    break;
                case 'unknown':
                    $path = $path . '/missing.mp3';
                    break;
                case 'conflict':
                    ++$original['ino'];
                    $proofs[] = Mp3CleanupHistoricalIdentity::capture('emissionsMp3', $path, null, $original);
                    break;
                case 'duplicate':
                    $proofs[] = Mp3CleanupHistoricalIdentity::restore($proof->toArray());
                    break;
            }

            self::assertSame($expected, (new Mp3CleanupIdentityVerifier($library))->check($path, $proofs)->value);
        } finally {
            // The process-owned storage deliberately refuses to clean up symlinks.
            if ($scenario === 'symlink' && is_link($file)) {
                unlink($file);
            }
        }
    }

    public static function scenarios(): iterable
    {
        yield ['match', 'confirmed'];
        yield ['absent', 'absent'];
        yield ['missing', 'missing_proof'];
        yield ['unusable', 'unusable_proof'];
        yield ['changed', 'different'];
        yield ['different hash', 'different'];
        yield ['new inode', 'different'];
        yield ['symlink', 'unsafe_path'];
        yield ['directory', 'unsafe_path'];
        yield ['unknown', 'indeterminate'];
        yield ['conflict', 'conflicting_proofs'];
        yield ['duplicate', 'confirmed'];
    }

    private static function capture(string $file): array
    {
        clearstatcache(true, $file);
        $stat = lstat($file);
        self::assertIsArray($stat);

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']))
            + ['sha256' => hash_file('sha256', $file)];
    }
}

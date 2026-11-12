<?php

declare(strict_types=1);

namespace App\Tests\Support;

trait CapturesMp3FallbackLog
{
    /** @return array{mixed, string} Operation result and captured incident identifier. */
    private function captureMp3FallbackLog(callable $operation, int $emissionId, string $operationName): array
    {
        $previous = ini_get('error_log');
        self::assertIsString($previous);
        $path = tempnam(sys_get_temp_dir(), 'mp3-fallback-');
        self::assertNotFalse($path);

        try {
            self::assertNotFalse(ini_set('error_log', $path), 'Cannot redirect the PHP fallback log.');
            self::assertSame($path, ini_get('error_log'));
            $result = $operation();
            $log = file_get_contents($path);
            self::assertNotFalse($log);
            $pattern = '~MP3 incident=(MP3-[a-f0-9]{32}) emission=' . $emissionId
                . ' operation=' . preg_quote($operationName, '~')
                . ' stage=metadata cause=RuntimeException logger_failure=RuntimeException~';
            self::assertSame(1, preg_match($pattern, $log, $matches), 'Expected a correlated MP3 fallback diagnostic.');

            return [$result, $matches[1]];
        } finally {
            try {
                self::assertNotFalse(ini_set('error_log', $previous), 'Cannot restore the PHP log destination.');
                self::assertSame($previous, ini_get('error_log'));
            } finally {
                self::assertTrue(unlink($path), 'Cannot remove the temporary fallback log.');
            }
        }
    }
}

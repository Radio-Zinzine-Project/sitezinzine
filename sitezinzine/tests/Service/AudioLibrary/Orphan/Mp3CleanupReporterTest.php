<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupReporter;
use App\ValueObject\AudioLibrary\Mp3CleanupAnomaly;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** RED contract: no production reporter, persistence, kernel or physical file is required yet. */
final class Mp3CleanupReporterTest extends TestCase
{
    public function testReferenceCheckFailureReportsUnknownReferencesWithoutDeclaringAnOrphan(): void
    {
        $received = [];
        $reporter = $this->reporter($received);

        $reporter->reportReferenceCheckFailure(42, 'TST/2026/old.mp3', 'TST/2026/new.mp3', new \RuntimeException('Connection unavailable'));

        self::assertCount(1, $received);
        $anomaly = $received[0];
        self::assertInstanceOf(Mp3CleanupAnomaly::class, $anomaly);
        self::assertSame('reference_check_failed', $anomaly->reason);
        self::assertSame(42, $anomaly->emissionId);
        self::assertSame('TST/2026/old.mp3', $anomaly->obsoleteRelativePath);
        self::assertSame([
            'replacement_relative_path' => 'TST/2026/new.mp3',
            'commit_confirmed' => true,
            'reference_count' => null,
        ], $anomaly->context);
    }

    public function testObsoleteCleanupFailureReportsDeletionFailureAfterConfirmedZeroReferences(): void
    {
        $received = [];
        $reporter = $this->reporter($received);

        $reporter->reportObsoleteCleanupFailure(43, 'TST/2026/previous.mp3', 'TST/2026/replacement.mp3', new \RuntimeException('Removal denied'));

        self::assertCount(1, $received);
        $anomaly = $received[0];
        self::assertInstanceOf(Mp3CleanupAnomaly::class, $anomaly);
        self::assertSame('obsolete_cleanup_failed', $anomaly->reason);
        self::assertSame(43, $anomaly->emissionId);
        self::assertSame('TST/2026/previous.mp3', $anomaly->obsoleteRelativePath);
        self::assertSame([
            'replacement_relative_path' => 'TST/2026/replacement.mp3',
            'commit_confirmed' => true,
            'reference_count' => 0,
        ], $anomaly->context);
    }

    public function testTechnicalDiagnosticContainsOnlySafeScalarsWithoutRawExceptionDetails(): void
    {
        $received = [];
        $reporter = $this->reporter($received);
        $previous = new \RuntimeException('PRIVATE_PREVIOUS_TOKEN');
        $error = new \RuntimeException('token=PRIVATE_TOKEN user@example.test /private/server/path', 17, $previous);

        $reporter->reportObsoleteCleanupFailure(42, 'TST/2026/old.mp3', 'TST/2026/new.mp3', $error);

        self::assertCount(1, $received);
        self::assertSame([
            'class' => \RuntimeException::class,
            'code' => 17,
            'sql_state' => null,
        ], $received[0]->diagnostic);
        $serialized = json_encode($received[0], JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_TOKEN', 'PRIVATE_PREVIOUS_TOKEN', 'user@example.test', '/private/server/path'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $serialized);
        }
    }

    public function testSqlDiagnosticKeepsSqlStateWithoutQueryParameters(): void
    {
        $received = [];
        $reporter = $this->reporter($received);
        // Constructing this exception does not open a PDO connection.
        $error = new \PDOException('SELECT secret FROM emission WHERE token = PRIVATE_SQL_PARAMETER', 2006);
        $error->errorInfo = ['08006', 2006, 'PRIVATE_DRIVER_MESSAGE'];

        $reporter->reportReferenceCheckFailure(42, 'TST/2026/old.mp3', 'TST/2026/new.mp3', $error);

        self::assertCount(1, $received);
        self::assertSame([
            'class' => \PDOException::class,
            'code' => 2006,
            'sql_state' => '08006',
        ], $received[0]->diagnostic);
        $serialized = json_encode($received[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('PRIVATE_SQL_PARAMETER', $serialized);
        self::assertStringNotContainsString('PRIVATE_DRIVER_MESSAGE', $serialized);
    }

    public function testSuccessfulRecipientDoesNotUseFallbackLogger(): void
    {
        $received = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $reporter = new Mp3CleanupReporter(static function (Mp3CleanupAnomaly $anomaly) use (&$received): void {
            $received[] = $anomaly;
        }, $logger);

        $reporter->reportReferenceCheckFailure(42, 'TST/2026/old.mp3', 'TST/2026/new.mp3', new \RuntimeException());

        self::assertCount(1, $received);
        self::assertSame('reference_check_failed', $received[0]->reason);
        self::assertNull($received[0]->context['reference_count']);
    }

    public static function cleanupFailures(): iterable
    {
        yield 'unknown references' => ['reference_check_failed', null];
        yield 'zero references established' => ['obsolete_cleanup_failed', 0];
    }

    /** @dataProvider cleanupFailures */
    public function testRecipientFailureIsContainedAndFallbackContainsOnlySafeMetadata(string $reason, ?int $referenceCount): void
    {
        $logs = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(static function ($message, array $context) use (&$logs): void {
            $logs[] = [$message, $context];
        });
        $recipientFailure = new \RuntimeException('token=PRIVATE_RECIPIENT_MESSAGE', 17,
            new \LogicException('PRIVATE_PREVIOUS_EXCEPTION'));
        $reporter = new Mp3CleanupReporter(static function (Mp3CleanupAnomaly $anomaly) use ($recipientFailure): void {
            throw $recipientFailure;
        }, $logger);
        $origin = new \PDOException('SELECT PRIVATE_SQL_PARAMETER FROM emission', 2006);
        $origin->errorInfo = ['08006', 2006, 'PRIVATE_DRIVER_DETAIL'];

        try {
            $this->reportFailure($reporter, $reason, $origin, 'TST/2026/token=PRIVATE_OLD_PATH.mp3', 'TST/2026/PRIVATE_NEW_PATH.mp3');
        } catch (\Throwable $error) {
            self::fail('A recipient failure must not escape the post-commit reporter: ' . $error::class);
        }

        // Assert outside the logger callback: a future defensive catch must not swallow assertions.
        self::assertCount(1, $logs);
        self::assertIsString($logs[0][0]);
        self::assertNotSame('', $logs[0][0]);
        self::assertSame([
            'reason' => $reason,
            'emission_id' => 42,
            'commit_confirmed' => true,
            'reference_count' => $referenceCount,
            'diagnostic' => ['class' => \PDOException::class, 'code' => 2006, 'sql_state' => '08006'],
            'recipient_failure' => ['class' => \RuntimeException::class, 'code' => 17, 'sql_state' => null],
        ], $logs[0][1]);
        $serialized = json_encode($logs, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE_RECIPIENT_MESSAGE', 'PRIVATE_PREVIOUS_EXCEPTION', 'PRIVATE_SQL_PARAMETER',
            'PRIVATE_DRIVER_DETAIL', 'PRIVATE_OLD_PATH', 'PRIVATE_NEW_PATH', 'trace', __FILE__] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $serialized);
        }
    }

    /** @dataProvider cleanupFailures */
    public function testFallbackLoggerFailureIsAlsoContainedWithoutRetrying(string $reason, ?int $referenceCount): void
    {
        $recipientCalls = 0;
        $loggerCalls = 0;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(static function () use (&$loggerCalls): void {
            ++$loggerCalls;
            throw new \LogicException('PRIVATE_LOGGER_FAILURE');
        });
        $reporter = new Mp3CleanupReporter(static function (Mp3CleanupAnomaly $anomaly) use (&$recipientCalls): void {
            ++$recipientCalls;
            throw new \RuntimeException('PRIVATE_RECIPIENT_FAILURE');
        }, $logger);

        try {
            $this->reportFailure($reporter, $reason, new \RuntimeException('PRIVATE_ORIGIN'));
        } catch (\Throwable $error) {
            self::fail('Neither recipient nor fallback logger failure may escape: ' . $error::class);
        }

        self::assertSame(1, $recipientCalls);
        self::assertSame(1, $loggerCalls);
    }

    private function reportFailure(
        Mp3CleanupReporter $reporter,
        string $reason,
        \Throwable $origin,
        string $oldPath = 'TST/2026/old.mp3',
        string $newPath = 'TST/2026/new.mp3',
    ): void {
        if ($reason === 'reference_check_failed') {
            $reporter->reportReferenceCheckFailure(42, $oldPath, $newPath, $origin);
        } else {
            $reporter->reportObsoleteCleanupFailure(42, $oldPath, $newPath, $origin);
        }
    }

    private function reporter(array &$received): Mp3CleanupReporter
    {
        return new Mp3CleanupReporter(static function (Mp3CleanupAnomaly $anomaly) use (&$received): void {
            $received[] = $anomaly;
        });
    }
}

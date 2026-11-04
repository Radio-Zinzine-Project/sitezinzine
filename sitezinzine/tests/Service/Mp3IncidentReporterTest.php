<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Mp3FailureStage;
use App\Exception\Mp3OperationFailure;
use App\Service\Mp3IncidentReporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class Mp3IncidentReporterTest extends TestCase
{
    /** @dataProvider stages */
    public function testPublicCategoryComesFromTheStageNotExceptionText(Mp3FailureStage $stage, string $word): void
    {
        $context = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(static function ($message, array $data) use (&$context): void {
            $context = $data;
        });
        $origin = new \RuntimeException('Contradictory SQL/private/diagnostic/details');
        $incident = (new Mp3IncidentReporter($logger))->report(new Mp3OperationFailure($stage, $origin, true, true), 42, 'replacement');
        self::assertStringContainsString($word, $incident->message);
        self::assertStringNotContainsString($origin->getMessage(), $incident->message);
        self::assertStringContainsString('n’ont pas été enregistrées', $incident->message);
        self::assertStringContainsString('ancien MP3 a été conservé', $incident->message);
        self::assertSame($incident->id, $context['incident_id']);
        self::assertSame($stage->value, $context['stage']);
        self::assertStringContainsString($incident->id, $incident->message);
        self::assertFalse($incident->retryBlocked);
    }

    public static function stages(): iterable
    {
        yield 'validation' => [Mp3FailureStage::VALIDATION, 'invalide'];
        yield 'metadata' => [Mp3FailureStage::METADATA, 'métadonnées'];
        yield 'move' => [Mp3FailureStage::MOVE, 'déplacer'];
        yield 'doctrine' => [Mp3FailureStage::DOCTRINE, 'enregistrement'];
    }

    public function testUncertainCommitDoesNotClaimRollbackOrOfferImmediateRetry(): void
    {
        $reporter = new Mp3IncidentReporter($this->createMock(LoggerInterface::class));
        $incident = $reporter->report(new Mp3OperationFailure(Mp3FailureStage::COMMIT,
            new \RuntimeException('/private/commit-details'), false, true), 42, 'replacement');
        self::assertTrue($incident->retryBlocked);
        self::assertFalse($incident->showOriginal);
        self::assertStringContainsString('pas pu être confirmé', $incident->message);
        self::assertStringNotContainsString('n’ont pas été enregistrées', $incident->message);
        self::assertStringNotContainsString('/private', $incident->message);
    }

    public function testMissingPreservationProofIsNotTurnedIntoAClaim(): void
    {
        $reporter = new Mp3IncidentReporter($this->createMock(LoggerInterface::class));
        $incident = $reporter->report(new Mp3OperationFailure(Mp3FailureStage::VALIDATION,
            new \RuntimeException('unreadable'), true, false), 42, 'replacement');
        self::assertFalse($incident->showOriginal);
        self::assertStringNotContainsString('ancien MP3 a été conservé', $incident->message);
    }

    public function testConflictDoesNotPresentTheStaleOriginalAsTheCurrentDatabaseFile(): void
    {
        $reporter = new Mp3IncidentReporter($this->createMock(LoggerInterface::class));
        $incident = $reporter->report(new Mp3OperationFailure(Mp3FailureStage::CONFLICT,
            new \RuntimeException('stale reference'), true, true), 42, 'replacement');
        self::assertTrue($incident->retryBlocked);
        self::assertFalse($incident->showOriginal);
        self::assertStringContainsString('Rechargez la fiche', $incident->message);
    }

    public function testLoggerFailureDoesNotReplaceTheBusinessError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willThrowException(new \RuntimeException('LOGGER_SINK_SECRET'));
        $incident = (new Mp3IncidentReporter($logger))->report(new Mp3OperationFailure(Mp3FailureStage::METADATA,
            new \RuntimeException('metadata details'), true, true), 42, 'replacement');
        self::assertStringContainsString('métadonnées', $incident->message);
        self::assertStringNotContainsString('LOGGER_SINK_SECRET', $incident->message);
        self::assertFalse($incident->retryBlocked);
    }

    public function testDiagnosticOmitsArgumentsCredentialsAndPersonalEmail(): void
    {
        $context = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function ($message, array $data) use (&$context): void {
            $context = $data;
        });
        $previousSetting = ini_set('zend.exception_ignore_args', '0');
        try {
            $origin = $this->diagnosticOrigin('TRACE_ARGUMENT_PRIVATE');
            (new Mp3IncidentReporter($logger))->report(new Mp3OperationFailure(Mp3FailureStage::METADATA, $origin, true), 42, 'upload');
        } finally {
            if ($previousSetting !== false) {
                ini_set('zend.exception_ignore_args', $previousSetting);
            }
        }
        $json = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['TRACE_ARGUMENT_PRIVATE', 'CREDENTIAL_PRIVATE', 'TOKEN_PRIVATE', 'MAIL_PRIVATE@example.test', 'PASS_PRIVATE'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame(\RuntimeException::class, $context['exception']['class']);
        self::assertStringContainsString('/private/diagnostic', $context['exception']['message']);
        foreach ($context['exception']['trace'] as $frame) {
            self::assertArrayNotHasKey('args', $frame);
            self::assertArrayNotHasKey('object', $frame);
        }
    }

    private function diagnosticOrigin(string $privateArgument): \RuntimeException
    {
        return new \RuntimeException('/private/diagnostic token=TOKEN_PRIVATE Bearer CREDENTIAL_PRIVATE MAIL_PRIVATE@example.test https://user:PASS_PRIVATE@host.test/path');
    }

    public function testSqlDiagnosticsKeepTheSqlStateWithoutQueryParameters(): void
    {
        $context = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function ($message, array $data) use (&$context): void {
            $context = $data;
        });
        $origin = new \PDOException("SQL containing PRIVATE_QUERY_PARAMETER and private@example.test", 1062);
        $origin->errorInfo = ['23000', 1062, 'PRIVATE_QUERY_PARAMETER'];
        (new Mp3IncidentReporter($logger))->report(new Mp3OperationFailure(Mp3FailureStage::DOCTRINE, $origin, true), 42, 'replacement');
        self::assertSame('23000', $context['exception']['sql_state']);
        self::assertSame(\PDOException::class, $context['exception']['class']);
        self::assertStringNotContainsString('PRIVATE_QUERY_PARAMETER', json_encode($context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private@example.test', json_encode($context, JSON_THROW_ON_ERROR));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAttemptJournal;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupResult;
use App\Tests\Support\Mp3CleanupDbalIntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class Mp3DeferredCleanupAttemptJournalIntegrationTest extends Mp3CleanupDbalIntegrationTestCase
{
    public function testConfirmedReservationsAreVisibleAndBlockDuplicateAndConcurrentAttempts(): void
    {
        $writer = $this->newConnection();
        if (!$writer->createSchemaManager()->tablesExist(['mp3_deferred_cleanup_attempt'])) {
            self::markTestSkipped('The deferred cleanup attempt migration requires separate approval.');
        }

        $observer = $this->newConnection();
        $journal = new Mp3DeferredCleanupAttemptJournal();
        $first = bin2hex(random_bytes(16));
        $second = bin2hex(random_bytes(16));
        $other = bin2hex(random_bytes(16));
        $issue = random_int(1_000_000_000, 2_147_483_647);
        self::assertSame(0, (int) $writer->fetchOne(
            'SELECT COUNT(*) FROM mp3_deferred_cleanup_attempt WHERE issue_id = ?', [$issue],
        ));
        $now = new \DateTimeImmutable('2026-11-20T12:02:00Z');
        try {
            $journal->reserve($writer, $first, $issue, 42, 19, $now);
            self::assertSame('pending', $journal->find($observer, $first)['state']);
            foreach ([$first, $second] as $denied) {
                try {
                    $journal->reserve($observer, $denied, $issue, 42, 20, $now);
                    self::fail('A duplicate request or an active request must block a reservation.');
                } catch (UniqueConstraintViolationException) {
                    self::assertSame(0, $observer->getTransactionNestingLevel());
                }
            }

            $journal->finish($writer, $first, Mp3DeferredCleanupResult::ReferenceActive, $now);
            $journal->reserve($observer, $second, $issue, 43, null, $now);
            $journal->finish($observer, $second, Mp3DeferredCleanupResult::Indeterminate, $now);
            self::assertSame('reference_active', $journal->find($observer, $first)['result_code']);
            self::assertSame('indeterminate', $journal->find($writer, $second)['result_code']);
            try {
                $journal->reserve($writer, $other, $issue, 44, null, $now);
                self::fail('An indeterminate attempt must still bar a new attempt.');
            } catch (UniqueConstraintViolationException) {
                self::assertNull($journal->find($observer, $other));
            }
        } finally {
            foreach ([$first, $second, $other] as $id) {
                $writer->delete('mp3_deferred_cleanup_attempt', ['request_id' => $id, 'issue_id' => $issue]);
            }
        }
    }
}

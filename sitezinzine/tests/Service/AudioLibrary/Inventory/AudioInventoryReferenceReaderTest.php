<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryClassifier;
use App\Service\AudioLibrary\Inventory\AudioInventoryReferenceReader;
use App\Service\AudioLibrary\Inventory\AudioLocalUrlResolver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\TestCase;

/** SQL contracts only; no database, filesystem observation or snapshot guarantee. */
final class AudioInventoryReferenceReaderTest extends TestCase
{
    private const PATH = 'SAN/2015/Archive.mp3';
    private const OLD_BASE = 'http://radiozinzine.org/emissions/';
    private const NEW_BASE = 'http://radiozinzine.org/uploads/emissionsMp3/';

    public function testEmptyPageRetainsCursorAndOnlyReportsSqlExhaustion(): void
    {
        $connection = $this->connection();
        $this->expectPage($connection, [], 42, 500);
        self::assertSame([
            'associations' => [], 'uncertainties' => [], 'next_after_id' => 42, 'exhausted' => true,
        ], $this->reader($connection)->readPage(42));
    }

    public function testDefaultsUseOneBoundedScalarParameterizedSelectIncludingLogicallyDeletedEmissions(): void
    {
        $connection = $this->connection();
        // deleted_at is deliberately absent from both projection and WHERE.
        $this->expectPage($connection, [$this->row(17, self::PATH)], 0, 500);
        self::assertSame([
            'associations' => [self::association(17, self::PATH)],
            'uncertainties' => [], 'next_after_id' => 17, 'exhausted' => true,
        ], $this->reader($connection)->readPage());
    }

    public function testFullPageIsNotExhaustedAndCursorUsesLastEmissionWithoutReferences(): void
    {
        $connection = $this->connection();
        $this->expectPage($connection, [$this->row(17, self::PATH), $this->row(31)], 9, 2);
        $page = $this->reader($connection)->readPage(9, 2);
        self::assertSame(31, $page['next_after_id']);
        self::assertFalse($page['exhausted']);
        self::assertSame([self::association(17, self::PATH)], $page['associations']);
        self::assertSame([], $page['uncertainties']);
    }

    public function testNextPageUsesReturnedCursorAndRequiresNoCountOrPerFileQuery(): void
    {
        $connection = $this->connection();
        $calls = 0;
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params, array $types) use (&$calls): array {
                $this->assertSql($sql);
                self::assertSame(['afterId' => $calls === 0 ? 0 : 17, 'limit' => 1], $params);
                self::assertSame(['afterId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER], $types);
                return $calls++ === 0 ? [$this->row(17)] : [];
            },
        );
        $reader = $this->reader($connection);
        $first = $reader->readPage(0, 1);
        self::assertFalse($first['exhausted']);
        $last = $reader->readPage($first['next_after_id'], 1);
        self::assertSame(['associations' => [], 'uncertainties' => [], 'next_after_id' => 17, 'exhausted' => true], $last);
    }

    /** @dataProvider projections */
    public function testProjectionUsesRealResolverAndPreservesPhysicalReferences(
        ?string $physical,
        ?string $url,
        array $associations,
        array $uncertainties,
    ): void {
        $connection = $this->connection();
        // PDO may return SQL integer IDs as strings.
        $rows = [['id' => '17', 'thumbnail_mp3' => $physical, 'url' => $url]];
        $before = $rows;
        $this->expectPage($connection, $rows, 0, 500);
        self::assertSame([
            'associations' => $associations, 'uncertainties' => $uncertainties,
            'next_after_id' => 17, 'exhausted' => true,
        ], $this->reader($connection)->readPage());
        self::assertSame($before, $rows);
    }

    public static function projections(): array
    {
        $physical = self::association(17, self::PATH);
        $local = self::association(17, self::PATH, 'local_audio_url', true);
        $urlWithPhysical = self::association(17, self::PATH, 'local_audio_url');
        $uncertain = [['emission_id' => 17, 'field' => 'url', 'reason' => 'uncertain_local_url']];
        return [
            'no reference' => [null, null, [], []],
            'empty fields' => ['', '', [], []],
            'physical only' => [self::PATH, null, [$physical], []],
            'historical local URL' => [null, self::OLD_BASE . self::PATH, [$local], []],
            'future local URL with empty physical field' => ['', self::NEW_BASE . self::PATH, [$local], []],
            'external URL' => [null, 'https://example.org/' . self::PATH, [], []],
            'uncertain URL with sensitive query' => [null, self::OLD_BASE . self::PATH . '?token=do-not-expose', [], $uncertain],
            'uncertainty does not erase physical reference' => [self::PATH, 'https://radiozinzine.org/emissions/' . self::PATH, [$physical], $uncertain],
            'both fields' => [self::PATH, self::OLD_BASE . self::PATH, [$physical, $urlWithPhysical], []],
            'different physical path is not missing' => ['OTHER/other.mp3', self::OLD_BASE . self::PATH, [self::association(17, 'OTHER/other.mp3'), $urlWithPhysical], []],
            'physical case and accents unchanged' => ['san/Été.MP3', null, [self::association(17, 'san/Été.MP3')], []],
            'URL case unchanged' => [null, self::OLD_BASE . 'san/Été.MP3', [self::association(17, 'san/Été.MP3', 'local_audio_url', true)], []],
            'whitespace physical field is not silently trimmed' => [' ', self::OLD_BASE . self::PATH, [self::association(17, ' '), $urlWithPhysical], []],
            'zero string is not an absent field' => ['0', null, [self::association(17, '0')], []],
        ];
    }

    public function testMultipleEmissionsAndTwoOriginsRemainAvailableToClassifier(): void
    {
        $connection = $this->connection();
        $this->expectPage($connection, [
            $this->row(17, self::PATH, self::OLD_BASE . self::PATH),
            $this->row(18, self::PATH),
        ], 0, 500);
        $page = $this->reader($connection)->readPage();
        self::assertSame([
            self::association(17, self::PATH), self::association(17, self::PATH, 'local_audio_url'),
            self::association(18, self::PATH),
        ], $page['associations']);
        $classification = (new AudioInventoryClassifier())->classify('emissionsMp3', self::PATH, true, true, $page['associations']);
        self::assertSame('multiple_associations', $classification['classification']);
        self::assertSame([17, 18], $classification['emission_ids']);
    }

    public function testTwoOriginsForOneEmissionDoNotBecomeMultipleAssociations(): void
    {
        $connection = $this->connection();
        $this->expectPage($connection, [$this->row(17, self::PATH, self::OLD_BASE . self::PATH)], 0, 500);
        $page = $this->reader($connection)->readPage();
        self::assertCount(2, $page['associations']);
        $classification = (new AudioInventoryClassifier())->classify('emissionsMp3', self::PATH, true, true, $page['associations']);
        self::assertSame('associated', $classification['classification']);
        self::assertSame([17], $classification['emission_ids']);
    }

    /** @dataProvider invalidParameters */
    public function testInvalidPaginationIsRejectedBeforeSql(int $afterId, int $limit): void
    {
        $connection = $this->connection();
        $connection->expects(self::never())->method('fetchAllAssociative');
        $reader = $this->reader($connection);
        $this->expectException(\InvalidArgumentException::class);
        $reader->readPage($afterId, $limit);
    }

    public static function invalidParameters(): array
    {
        return [[-1, 500], [0, 0], [0, -1], [0, 501], [0, PHP_INT_MAX]];
    }

    public function testDbalFailureIsPropagatedUnchangedWithoutAnyPartialResult(): void
    {
        $connection = $this->connection();
        $failure = new class('Synthetic read failure') extends \RuntimeException implements \Doctrine\DBAL\Exception {};
        $connection->expects(self::once())->method('fetchAllAssociative')->willThrowException($failure);
        $result = null;
        try {
            $result = $this->reader($connection)->readPage();
            self::fail('The SQL exception must propagate.');
        } catch (\Doctrine\DBAL\Exception $actual) {
            self::assertSame($failure, $actual);
            self::assertNull($result);
        }
    }

    private function reader(Connection $connection): AudioInventoryReferenceReader
    {
        return new AudioInventoryReferenceReader($connection, new AudioLocalUrlResolver([self::OLD_BASE, self::NEW_BASE]));
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        foreach (['executeStatement', 'insert', 'update', 'delete', 'beginTransaction', 'commit', 'rollBack', 'close', 'fetchOne', 'fetchAssociative'] as $method) {
            $connection->expects(self::never())->method($method);
        }
        return $connection;
    }

    private function expectPage(Connection $connection, array $rows, int $afterId, int $limit): void
    {
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql, array $params, array $types) use ($rows, $afterId, $limit): array {
                $this->assertSql($sql);
                self::assertSame(['afterId' => $afterId, 'limit' => $limit], $params);
                self::assertSame(['afterId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER], $types);
                return $rows;
            },
        );
    }

    private function assertSql(string $sql): void
    {
        self::assertSame(
            'SELECT id, thumbnail_mp3, url FROM emission WHERE id > :afterId ORDER BY id ASC LIMIT :limit',
            preg_replace('/\s+/', ' ', trim($sql)),
        );
    }

    private function row(int $id, ?string $physical = null, ?string $url = null): array
    {
        return ['id' => $id, 'thumbnail_mp3' => $physical, 'url' => $url];
    }

    private static function association(int $id, string $path, string $origin = 'thumbnailMp3', bool $missing = false): array
    {
        return [
            'storage' => 'emissionsMp3', 'relative_path' => $path, 'emission_id' => $id,
            'origin' => $origin, 'thumbnail_missing' => $missing,
        ];
    }
}

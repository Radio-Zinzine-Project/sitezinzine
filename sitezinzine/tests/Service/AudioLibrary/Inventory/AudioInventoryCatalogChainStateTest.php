<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryCatalogChainState;
use PHPUnit\Framework\TestCase;

final class AudioInventoryCatalogChainStateTest extends TestCase
{
    private function chain(): AudioInventoryCatalogChainState
    {
        return new AudioInventoryCatalogChainState('operation', 'catalog-A');
    }

    private function initial(): array
    {
        return [
            'operation_id' => 'operation', 'active_catalog_id' => 'catalog-A', 'revision' => 0,
            'consultation_active' => false, 'replacement_in_progress' => false,
            'retirement_pending' => false, 'last_retired_catalog_id' => null,
        ];
    }

    public function testInitialSnapshotHasOneActiveCatalogAndNoPendingWork(): void
    {
        self::assertSame($this->initial(), $this->chain()->snapshot());
    }

    /** @dataProvider invalidInitialIds */
    public function testInitialIdentifiersMustBeNonBlank(string $operation, string $catalog): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AudioInventoryCatalogChainState($operation, $catalog);
    }

    public static function invalidInitialIds(): array
    {
        return [['', 'catalog'], ['operation', ''], [" \t", 'catalog'], ['operation', "\n"]];
    }

    public function testOpaqueIdsArePreservedWithoutFilesystemInterpretation(): void
    {
        // No fixture, stream, directory or real MP3: these are opaque strings only.
        $chain = new AudioInventoryCatalogChainState(' operation ', '../synthetic/not-a-file');
        self::assertSame(' operation ', $chain->snapshot()['operation_id']);
        self::assertSame('../synthetic/not-a-file', $chain->snapshot()['active_catalog_id']);
        $token = $chain->beginReplacement('../synthetic/not-a-file');
        $chain->publishReplacement($token, '/another/opaque/catalog');
        self::assertSame('/another/opaque/catalog', $chain->snapshot()['active_catalog_id']);
    }

    public function testConsultationReceivesOnlyIdAndReturnsResult(): void
    {
        $chain = $this->chain();
        $result = $chain->withActiveCatalog(function (string $id) use ($chain): string {
            self::assertSame('catalog-A', $id);
            self::assertTrue($chain->snapshot()['consultation_active']);
            return 'observed';
        });
        self::assertSame('observed', $result);
        self::assertSame($this->initial(), $chain->snapshot());
    }

    public function testConsumerExceptionIsPreservedAndConsultationIsReleased(): void
    {
        $chain = $this->chain(); $failure = new \RuntimeException('consumer');
        try { $chain->withActiveCatalog(static function () use ($failure): void { throw $failure; }); self::fail('Failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertSame($this->initial(), $chain->snapshot());
        self::assertNotSame('', $chain->beginReplacement('catalog-A'));
    }

    public function testNestedConsultationIsExplicitlyRefused(): void
    {
        $chain = $this->chain();
        $chain->withActiveCatalog(function () use ($chain): void {
            try { $chain->withActiveCatalog(static function (): void { self::fail('Nested consumer called.'); }); self::fail('Nested consultation accepted.'); }
            catch (\DomainException) { self::assertTrue($chain->snapshot()['consultation_active']); }
        });
        self::assertFalse($chain->snapshot()['consultation_active']);
    }

    public function testReplacementIsRefusedDuringConsultation(): void
    {
        $chain = $this->chain();
        $chain->withActiveCatalog(function () use ($chain): void {
            try { $chain->beginReplacement('catalog-A'); self::fail('Replacement accepted during consultation.'); }
            catch (\DomainException) { self::assertFalse($chain->snapshot()['replacement_in_progress']); }
        });
        self::assertSame($this->initial(), $chain->snapshot());
    }

    public function testStaleExpectedCatalogDoesNotChangeState(): void
    {
        $chain = $this->chain();
        try { $chain->beginReplacement('catalog-old'); self::fail('Stale ID accepted.'); }
        catch (\DomainException) { self::assertSame($this->initial(), $chain->snapshot()); }
    }

    public function testOnlyOneReplacementCanBePending(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $before = $chain->snapshot();
        self::assertTrue($before['replacement_in_progress']); self::assertSame(0, $before['revision']);
        try { $chain->beginReplacement('catalog-A'); self::fail('Two replacements accepted.'); }
        catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
        $chain->abortReplacement($token);
    }

    public function testConsultationIsRefusedWhileReplacementIsInProgress(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A');
        try { $chain->withActiveCatalog(static function (): void { self::fail('Consumer called.'); }); self::fail('Consultation accepted.'); }
        catch (\DomainException) { self::assertTrue($chain->snapshot()['replacement_in_progress']); }
        $chain->abortReplacement($token);
    }

    public function testSuccessfulPublicationActivatesNewAndRetiresOldAtomically(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A');
        $chain->publishReplacement($token, 'catalog-B');
        $expected = $this->initial();
        $expected['active_catalog_id'] = 'catalog-B'; $expected['revision'] = 1;
        $expected['last_retired_catalog_id'] = 'catalog-A'; $expected['retirement_pending'] = true;
        self::assertSame($expected, $chain->snapshot());
    }

    /** @dataProvider tokenOperations */
    public function testUnknownTokenDoesNotChangePendingAttempt(string $method): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $before = $chain->snapshot();
        try {
            if ($method === 'publish') { $chain->publishReplacement('unknown', 'catalog-B'); }
            else { $chain->abortReplacement('unknown'); }
            self::fail('Unknown token accepted.');
        } catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
        $chain->abortReplacement($token);
    }
    public static function tokenOperations(): array { return [['publish'], ['abort']]; }

    public function testTokensAreBoundToChainEvenWithSameOperationId(): void
    {
        $a = $this->chain(); $b = $this->chain();
        $tokenA = $a->beginReplacement('catalog-A'); $tokenB = $b->beginReplacement('catalog-A');
        self::assertNotSame($tokenA, $tokenB);
        $this->expectException(\DomainException::class); $b->publishReplacement($tokenA, 'catalog-B');
    }

    public function testPublicationTokenCannotBeConsumedTwice(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $chain->publishReplacement($token, 'catalog-B');
        $before = $chain->snapshot();
        try { $chain->publishReplacement($token, 'catalog-C'); self::fail('Token reused.'); }
        catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
        $this->expectException(\DomainException::class); $chain->abortReplacement($token);
    }

    public function testCurrentCatalogCannotBeRepublishedAndAttemptRemainsUsable(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $before = $chain->snapshot();
        try { $chain->publishReplacement($token, 'catalog-A'); self::fail('Current catalog reactivated.'); }
        catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
        $chain->publishReplacement($token, 'catalog-B'); self::assertSame(1, $chain->snapshot()['revision']);
    }

    /** @dataProvider blankIds */
    public function testBlankNewCatalogDoesNotConsumeAttempt(string $id): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $before = $chain->snapshot();
        try { $chain->publishReplacement($token, $id); self::fail('Blank ID accepted.'); }
        catch (\InvalidArgumentException) { self::assertSame($before, $chain->snapshot()); }
        $chain->abortReplacement($token);
    }
    public static function blankIds(): array { return [[''], [" \n"]]; }

    public function testLastRetiredCatalogRemainsForbiddenAfterRetirementConfirmation(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $chain->publishReplacement($token, 'catalog-B');
        $chain->confirmRetirement('catalog-A'); $token = $chain->beginReplacement('catalog-B'); $before = $chain->snapshot();
        try { $chain->publishReplacement($token, 'catalog-A'); self::fail('Known retired catalog reactivated.'); }
        catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
        $chain->abortReplacement($token);
    }

    public function testAbandonPreservesActiveCatalogAndInvalidatesToken(): void
    {
        $chain = $this->chain(); $old = $chain->beginReplacement('catalog-A'); $chain->abortReplacement($old);
        self::assertSame($this->initial(), $chain->snapshot());
        $new = $chain->beginReplacement('catalog-A'); self::assertNotSame($old, $new); $before = $chain->snapshot();
        try { $chain->abortReplacement($old); self::fail('Stale abort accepted.'); }
        catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
        $this->expectException(\DomainException::class); $chain->publishReplacement($old, 'catalog-B');
    }

    public function testReplacementRequiresExplicitRetirementConfirmation(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $chain->publishReplacement($token, 'catalog-B');
        $before = $chain->snapshot();
        try { $chain->beginReplacement('catalog-B'); self::fail('Pending retirement ignored.'); }
        catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
        $chain->confirmRetirement('catalog-A');
        self::assertFalse($chain->snapshot()['retirement_pending']);
        self::assertSame('catalog-A', $chain->snapshot()['last_retired_catalog_id']);
        self::assertSame(1, $chain->snapshot()['revision']);
        self::assertNotSame('', $chain->beginReplacement('catalog-B'));
    }

    public function testWrongRetirementIdDoesNotReleasePendingRetirement(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $chain->publishReplacement($token, 'catalog-B'); $before = $chain->snapshot();
        try { $chain->confirmRetirement('catalog-X'); self::fail('Wrong retirement accepted.'); }
        catch (\DomainException) { self::assertSame($before, $chain->snapshot()); }
    }

    public function testRetirementWithoutPendingWorkIsRefused(): void
    {
        $this->expectException(\DomainException::class); $this->chain()->confirmRetirement('catalog-A');
    }

    public function testRepeatedRetirementConfirmationIsRefused(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $chain->publishReplacement($token, 'catalog-B'); $chain->confirmRetirement('catalog-A');
        $this->expectException(\DomainException::class); $chain->confirmRetirement('catalog-A');
    }

    public function testConsultationOfNewActiveCatalogBlocksRetirementConfirmation(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $chain->publishReplacement($token, 'catalog-B');
        $chain->withActiveCatalog(function (string $id) use ($chain): void {
            self::assertSame('catalog-B', $id);
            try { $chain->confirmRetirement('catalog-A'); self::fail('Retirement confirmed during consultation.'); }
            catch (\DomainException) { self::assertTrue($chain->snapshot()['retirement_pending']); }
        });
        $chain->confirmRetirement('catalog-A'); self::assertFalse($chain->snapshot()['retirement_pending']);
    }

    public function testSnapshotCannotMutateInternalStateOrExposeAttemptToken(): void
    {
        $chain = $this->chain(); $token = $chain->beginReplacement('catalog-A'); $snapshot = $chain->snapshot();
        self::assertNotContains($token, $snapshot);
        $snapshot['active_catalog_id'] = 'tampered'; $snapshot['revision'] = 99; $snapshot['replacement_in_progress'] = false;
        self::assertSame('catalog-A', $chain->snapshot()['active_catalog_id']); self::assertSame(0, $chain->snapshot()['revision']);
        self::assertTrue($chain->snapshot()['replacement_in_progress']);
    }

    public function testOlderForgottenCatalogIsOutsideLocalNonReuseGuarantee(): void
    {
        $chain = $this->chain();
        $token = $chain->beginReplacement('catalog-A'); $chain->publishReplacement($token, 'catalog-B'); $chain->confirmRetirement('catalog-A');
        $token = $chain->beginReplacement('catalog-B'); $chain->publishReplacement($token, 'catalog-C'); $chain->confirmRetirement('catalog-B');
        self::assertSame('catalog-B', $chain->snapshot()['last_retired_catalog_id']);
        // Deliberate boundary test: provenance/non-reuse of forgotten A belongs
        // to the future owner. This pure bounded state cannot recognize it.
        $token = $chain->beginReplacement('catalog-C'); $chain->publishReplacement($token, 'catalog-A');
        self::assertSame('catalog-A', $chain->snapshot()['active_catalog_id']); self::assertSame(3, $chain->snapshot()['revision']);
    }

    public function testStateRemainsConstantSizeAcrossManyCyclesWithoutCatalogHistory(): void
    {
        $chain = $this->chain(); $initialBytes = strlen(serialize($chain)); $baseline = memory_get_usage();
        $catalog = 'catalog-A';
        for ($i = 1; $i <= 2000; ++$i) {
            $new = sprintf('catalog-%08d', $i); $token = $chain->beginReplacement($catalog);
            $chain->publishReplacement($token, $new); $chain->confirmRetirement($catalog); $catalog = $new;
        }
        self::assertSame(2000, $chain->snapshot()['revision']); self::assertSame('operation', $chain->snapshot()['operation_id']);
        self::assertLessThan($initialBytes + 256, strlen(serialize($chain)));
        self::assertLessThan(512 * 1024, memory_get_usage() - $baseline);
        foreach ((new \ReflectionObject($chain))->getProperties() as $property) {
            self::assertFalse(is_array($property->getValue($chain)), 'No growing identifier history or global run registry.');
        }
    }
}

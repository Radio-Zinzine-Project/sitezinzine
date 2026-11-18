<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryCatalogChainOwner;
use App\Service\AudioLibrary\Inventory\AudioInventoryCatalogReferenceValidator;
use App\Service\AudioLibrary\Inventory\AudioInventoryMergePass;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryPathRunMerger;
use App\Service\AudioLibrary\Inventory\AudioInventoryRunCatalogCodec;
use App\Service\AudioLibrary\Inventory\AudioInventoryTemporaryWorkspace;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunCatalog;
use App\Service\AudioLibrary\Inventory\AudioInventoryWorkspaceRunMerger;
use PHPUnit\Framework\TestCase;

final class AudioInventoryCatalogChainOwnerTest extends TestCase
{
    private string $root;
    private AudioInventoryTemporaryWorkspace $workspace;
    private AudioInventoryPathRunCodec $paths;
    private array $others = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/zinzine-chain-owner-'.bin2hex(random_bytes(12)); mkdir($this->root, 0700);
        $this->workspace = new AudioInventoryTemporaryWorkspace($this->root, 5, 24);
        $this->paths = new AudioInventoryPathRunCodec(1024);
    }
    protected function tearDown(): void
    {
        try { $this->workspace->cleanup(); foreach ($this->others as $other) { $other->cleanup(); } }
        finally { rmdir($this->root); parent::tearDown(); }
    }
    private function runs(): array { return (new \ReflectionProperty($this->workspace, 'runs'))->getValue($this->workspace); }
    private function catalog(): AudioInventoryWorkspaceRunCatalog { return new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128)); }
    private function validator(int $budget = 20): AudioInventoryCatalogReferenceValidator { return new AudioInventoryCatalogReferenceValidator($this->workspace, $this->catalog(), $this->paths, $budget); }
    private function pass(): AudioInventoryMergePass
    {
        return new AudioInventoryMergePass($this->workspace, $this->catalog(), $this->catalog(), new AudioInventoryWorkspaceRunMerger($this->workspace, new AudioInventoryPathRunMerger($this->paths, 2), 2), $this->paths, 2);
    }
    private function input(array $paths): string
    {
        $id = $this->workspace->createRun(); $this->paths->write($this->workspace->openWrite($id), $paths);
        $this->workspace->close($id); $this->workspace->finalize($id); return $id;
    }
    private function fixture(): string { return $this->catalog()->create([$this->input(['a']), $this->input(['b'])]); }
    private function start(string $initial, ?AudioInventoryCatalogReferenceValidator $validator = null, ?AudioInventoryMergePass $pass = null, ?AudioInventoryWorkspaceRunCatalog $catalog = null): AudioInventoryCatalogChainOwner
    {
        return AudioInventoryCatalogChainOwner::start('operation', $initial, $this->workspace, $catalog ?? $this->catalog(), $validator ?? $this->validator(), $pass ?? $this->pass());
    }
    private function assertPreserved(array $before): void
    {
        self::assertSame([], array_diff_key($before, $this->runs()), 'No originally owned file may disappear.');
        foreach ($before as $id => $run) { self::assertArrayHasKey($id, $this->runs()); self::assertFileExists($run['path']); self::assertSame($run['state'], $this->runs()[$id]['state']); }
        foreach ($this->runs() as $run) { self::assertNull($run['stream']); }
    }
    private function corrupt(string $id): void
    {
        $path = $this->runs()[$id]['path']; $bytes = file_get_contents($path); $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1); file_put_contents($path, $bytes);
    }
    private function assertAborted(AudioInventoryCatalogChainOwner $owner, string $initial): void
    {
        $state = $owner->snapshot(); self::assertSame($initial, $state['active_catalog_id']); self::assertSame(0, $state['revision']);
        self::assertFalse($state['replacement_in_progress']); self::assertFalse($state['retirement_pending']); self::assertFalse($state['blocked']);
    }

    public function testStartReallyValidatesInitialCatalogWithoutChangingFiles(): void
    {
        $initial = $this->fixture(); $before = $this->runs(); $owner = $this->start($initial);
        self::assertSame('operation', $owner->snapshot()['operation_id']); $this->assertAborted($owner, $initial);
        self::assertSame([], $owner->getSecondaryFailures()); self::assertSame(array_keys($before), array_keys($this->runs())); $this->assertPreserved($before);
    }

    /** @dataProvider invalidInitials */
    public function testInitialValidationCannotBeReplacedByDeclaration(string $kind): void
    {
        if ($kind === 'foreign') {
            $other = new AudioInventoryTemporaryWorkspace($this->root, 1, 1); $this->others[] = $other;
            $initial = (new AudioInventoryWorkspaceRunCatalog($other, new AudioInventoryRunCatalogCodec(128)))->create([]);
        } elseif ($kind === 'unknown_reference') { $initial = $this->catalog()->create(['unknown']); }
        else { $initial = $this->fixture(); $this->corrupt($initial); }
        $before = $this->runs();
        try { $this->start($initial); self::fail('Invalid initial catalog accepted.'); }
        catch (\InvalidArgumentException|\UnexpectedValueException) { $this->assertPreserved($before); }
    }
    public static function invalidInitials(): array { return [['foreign'], ['unknown_reference'], ['corrupt']]; }

    public function testSuccessfulReplacementReallyProducesValidCatalogAndPendingRetirement(): void
    {
        $initial = $this->fixture(); $owner = $this->start($initial); $before = $this->runs();
        $result = $owner->replaceActiveCatalog();
        self::assertSame($initial, $result['previous_catalog_id']); self::assertNotSame($initial, $result['active_catalog_id']);
        self::assertSame($result['active_catalog_id'], $result['pass_report']['output_catalog_id']);
        self::assertSame(2, $result['pass_report']['input_runs']); self::assertSame(1, $result['pass_report']['output_runs']);
        self::assertSame(1, $result['revision']); self::assertTrue($result['retirement_pending']);
        self::assertSame($initial, $owner->snapshot()['last_retired_catalog_id']);
        self::assertSame($result['active_catalog_id'], $owner->snapshot()['active_catalog_id']);
        $ids = $owner->withActiveCatalog(static fn (iterable $ids) => iterator_to_array($ids, false));
        self::assertCount(1, $ids);
        try { self::assertSame(['a', 'b'], iterator_to_array($this->paths->read($this->workspace->openRead($ids[0])), false)); }
        finally { $this->workspace->close($ids[0]); }
        $this->assertPreserved($before);
    }

    public function testExactOrderWithRealValidatorAndPass(): void
    {
        $initial = $this->fixture(); $events = [];
        $validator = new class($this->workspace, $this->catalog(), $this->paths, 20, $events) extends AudioInventoryCatalogReferenceValidator {
            private array $events;
            public function __construct($workspace, $catalog, $codec, $budget, array &$events) { parent::__construct($workspace, $catalog, $codec, $budget); $this->events =& $events; }
            public function validate(string $id): array { $this->events[] = 'validate'; return parent::validate($id); }
        };
        $pass = new class($this->workspace, $this->catalog(), $this->catalog(), new AudioInventoryWorkspaceRunMerger($this->workspace, new AudioInventoryPathRunMerger($this->paths, 2), 2), $this->paths, 2, $events) extends AudioInventoryMergePass {
            private array $events;
            public function __construct($workspace, $reader, $writer, $merger, $codec, $k, array &$events) { parent::__construct($workspace, $reader, $writer, $merger, $codec, $k); $this->events =& $events; }
            public function run(string $id): array { $this->events[] = 'pass'; return parent::run($id); }
        };
        $owner = $this->start($initial, $validator, $pass); self::assertSame(['validate'], $events);
        $owner->replaceActiveCatalog(); self::assertSame(['validate', 'validate', 'pass', 'validate'], $events); self::assertSame(1, $owner->snapshot()['revision']);
    }

    public function testEntryValidationFailureAbortsAndAllowsRetryAfterRepair(): void
    {
        $initial = $this->fixture(); $owner = $this->start($initial); $path = $this->runs()[$initial]['path']; $original = file_get_contents($path); $this->corrupt($initial); $before = $this->runs();
        try { $owner->replaceActiveCatalog(); self::fail('Entry corruption ignored.'); }
        catch (\UnexpectedValueException) { $this->assertAborted($owner, $initial); self::assertCount(count($before), $this->runs()); }
        file_put_contents($path, $original); self::assertSame(1, $owner->replaceActiveCatalog()['revision']); $this->assertPreserved($before);
    }

    public function testRealComparisonBudgetExhaustionLeavesNoPublishedReplacement(): void
    {
        $initial = $this->catalog()->create([$this->input(['a']), $this->input(['b']), $this->input(['c'])]);
        $validator = new class($this->workspace, $this->catalog(), $this->paths, 20, $this->validator(1)) extends AudioInventoryCatalogReferenceValidator {
            private int $calls = 0;
            public function __construct($workspace, $catalog, $codec, $budget, private AudioInventoryCatalogReferenceValidator $restricted) { parent::__construct($workspace, $catalog, $codec, $budget); }
            public function validate(string $id): array { return ++$this->calls === 1 ? parent::validate($id) : $this->restricted->validate($id); }
        };
        $owner = $this->start($initial, $validator);
        try { $owner->replaceActiveCatalog(); self::fail('Comparison budget ignored.'); }
        catch (\LengthException $failure) { self::assertStringContainsString('Comparison budget', $failure->getMessage()); $this->assertAborted($owner, $initial); }
    }

    public function testMergeFailureDoesNotActivateCandidateOrDeleteInputs(): void
    {
        $initial = $this->catalog()->create([$this->input(['a']), $this->input(['a'])]); $owner = $this->start($initial); $before = $this->runs();
        try { $owner->replaceActiveCatalog(); self::fail('Duplicate path accepted.'); }
        catch (\UnexpectedValueException) { $this->assertAborted($owner, $initial); }
        $this->assertPreserved($before);
    }

    private function alteredPass(bool $corrupt): AudioInventoryMergePass
    {
        return new class($this->workspace, $this->catalog(), $this->catalog(), new AudioInventoryWorkspaceRunMerger($this->workspace, new AudioInventoryPathRunMerger($this->paths, 2), 2), $this->paths, 2, $corrupt) extends AudioInventoryMergePass {
            public function __construct(private AudioInventoryTemporaryWorkspace $owner, $reader, $writer, $merger, $codec, $k, private bool $corrupt) { parent::__construct($owner, $reader, $writer, $merger, $codec, $k); }
            public function run(string $id): array {
                $result = parent::run($id);
                if ($this->corrupt) {
                    $runs = (new \ReflectionProperty($this->owner, 'runs'))->getValue($this->owner); $path = $runs[$result['output_catalog_id']]['path'];
                    $bytes = file_get_contents($path); $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1); file_put_contents($path, $bytes);
                } else { ++$result['output_runs']; }
                return $result;
            }
        };
    }

    /** @dataProvider candidateFaults */
    public function testFinalizedButInvalidCandidateIsNeverActivated(bool $corrupt): void
    {
        $initial = $this->fixture(); $owner = $this->start($initial, null, $this->alteredPass($corrupt)); $before = $this->runs();
        try { $owner->replaceActiveCatalog(); self::fail('Bad candidate published.'); }
        catch (\UnexpectedValueException) { $this->assertAborted($owner, $initial); }
        $new = array_diff_key($this->runs(), $before); self::assertCount(2, $new);
        foreach ($new as $run) { self::assertSame('finalized', $run['state']); }
        $this->assertPreserved($before);
    }
    public static function candidateFaults(): array { return [[true], [false]]; }

    public function testConsultationStaysActiveUntilRealCatalogCloses(): void
    {
        $initial = $this->fixture(); $owner = null; $observed = false;
        $catalog = new class($this->workspace, new AudioInventoryRunCatalogCodec(128), $owner, $observed) extends AudioInventoryWorkspaceRunCatalog {
            private mixed $chain; private bool $observed;
            public function __construct($workspace, $codec, mixed &$chain, bool &$observed) { parent::__construct($workspace, $codec); $this->chain =& $chain; $this->observed =& $observed; }
            protected function closeRun(string $id): void { $this->observed = $this->chain->snapshot()['consultation_active']; parent::closeRun($id); }
        };
        $owner = $this->start($initial, null, null, $catalog);
        $result = $owner->withActiveCatalog(function (iterable $ids) use (&$owner): int {
            self::assertTrue($owner->snapshot()['consultation_active']);
            foreach (['replace', 'consult'] as $operation) {
                try {
                    if ($operation === 'replace') { $owner->replaceActiveCatalog(); }
                    else { $owner->withActiveCatalog(static function (): void { self::fail('Nested consumer invoked.'); }); }
                    self::fail('Reentrant operation accepted.');
                } catch (\DomainException) {}
            }
            return count(iterator_to_array($ids, false));
        });
        self::assertSame(2, $result); self::assertTrue($observed); self::assertFalse($owner->snapshot()['consultation_active']);
    }

    public function testCallbackFailureIsPreservedAndConsultationReleased(): void
    {
        $owner = $this->start($this->fixture()); $failure = new \RuntimeException('consumer');
        try { $owner->withActiveCatalog(static function () use ($failure): void { throw $failure; }); self::fail('Consumer failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertFalse($owner->snapshot()['consultation_active']); self::assertSame(1, $owner->replaceActiveCatalog()['revision']);
    }

    public function testPartialConsultationCannotReturnEscapedIterator(): void
    {
        $owner = $this->start($this->fixture());
        try { $owner->withActiveCatalog(static fn (iterable $ids) => $ids); self::fail('Escaped iterator accepted.'); }
        catch (\LogicException) { self::assertFalse($owner->snapshot()['consultation_active']); }
        foreach ($this->runs() as $run) { self::assertNull($run['stream']); }
    }

    public function testSecondReplacementRefusedWithoutCallingRetirementConfirmation(): void
    {
        $owner = $this->start($this->fixture()); $owner->replaceActiveCatalog(); $before = $this->runs(); $snapshot = $owner->snapshot();
        try { $owner->replaceActiveCatalog(); self::fail('Pending retirement bypassed.'); }
        catch (\DomainException) { self::assertSame($snapshot['active_catalog_id'], $owner->snapshot()['active_catalog_id']); self::assertTrue($owner->snapshot()['retirement_pending']); }
        self::assertSame(array_keys($before), array_keys($this->runs())); $this->assertPreserved($before);
    }

    private function faultingOwner(string $initial, string $mode, \Throwable $initialFailure, ?\Throwable $abortFailure = null): AudioInventoryCatalogChainOwner
    {
        $class = chainOwnerFaultClass();
        $owner = $class::start('operation', $initial, $this->workspace, $this->catalog(), $this->validator(), $this->pass());
        $owner->mode = $mode; $owner->initialFailure = $initialFailure; $owner->abortFailure = $abortFailure; return $owner;
    }

    public function testPublicationFailurePreservesOldCatalogAndAbortsAttempt(): void
    {
        $initial = $this->fixture(); $failure = new \RuntimeException('publication'); $owner = $this->faultingOwner($initial, 'publish', $failure); $before = $this->runs();
        try { $owner->replaceActiveCatalog(); self::fail('Publication failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        $this->assertAborted($owner, $initial); $this->assertPreserved($before);
    }

    public function testAbandonFailureIsSecondaryAndBlocksSubsequentOperations(): void
    {
        $initial = $this->fixture(); $failure = new \RuntimeException('publication'); $secondary = new \RuntimeException('abort');
        $owner = $this->faultingOwner($initial, 'publish', $failure, $secondary);
        try { $owner->replaceActiveCatalog(); self::fail('Initial failure concealed.'); }
        catch (\RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertSame([$secondary], $owner->getSecondaryFailures()); self::assertTrue($owner->snapshot()['blocked']);
        self::assertSame($initial, $owner->snapshot()['active_catalog_id']);
        foreach (['replace', 'consult'] as $operation) {
            try { if ($operation === 'replace') { $owner->replaceActiveCatalog(); } else { $owner->withActiveCatalog(static fn () => null); } self::fail('Blocked owner reused.'); }
            catch (\DomainException) {}
        }
        self::assertSame([$secondary], $owner->getSecondaryFailures());
    }

    public function testStaleAttemptCannotBePublished(): void
    {
        $initial = $this->fixture(); $owner = $this->faultingOwner($initial, 'stale', new \RuntimeException('unused'));
        try { $owner->replaceActiveCatalog(); self::fail('Stale token published.'); }
        catch (\DomainException) { self::assertSame($initial, $owner->snapshot()['active_catalog_id']); self::assertSame(0, $owner->snapshot()['revision']); }
        self::assertTrue($owner->snapshot()['blocked']); self::assertCount(1, $owner->getSecondaryFailures());
    }

    public function testSecondaryCloseFailureFromValidationRemainsObservable(): void
    {
        $initial = $this->fixture(); $secondary = new \RuntimeException('secondary close');
        $validator = new class($this->workspace, $this->catalog(), $this->paths, 20, $secondary) extends AudioInventoryCatalogReferenceValidator {
            public bool $inject = false;
            public function __construct($workspace, $catalog, $codec, $budget, private \Throwable $failure) { parent::__construct($workspace, $catalog, $codec, $budget); }
            protected function closeRun(string $id): void { parent::closeRun($id); if ($this->inject) { throw $this->failure; } }
        };
        $owner = $this->start($initial, $validator); $validator->inject = true;
        $ids = $this->catalog()->consume($initial, static fn (iterable $ids) => iterator_to_array($ids, false)); $this->corrupt($ids[0]);
        try { $owner->replaceActiveCatalog(); self::fail('Corruption ignored.'); }
        catch (\UnexpectedValueException) { self::assertSame([$secondary], $owner->getSecondaryFailures()); }
        $this->assertAborted($owner, $initial);
    }
}

/** Deferred declaration lets the RED run report the absent production class. */
function chainOwnerFaultClass(): string
{
    if (!class_exists(__NAMESPACE__.'\\AudioInventoryOwnerFaultInjection', false)) {
        class AudioInventoryOwnerFaultInjection extends AudioInventoryCatalogChainOwner
        {
            public string $mode; public \Throwable $initialFailure; public ?\Throwable $abortFailure = null;
            protected function publishReplacement(string $token, string $id): void {
                if ($this->mode === 'stale') { parent::abortReplacement($token); parent::publishReplacement($token, $id); return; }
                throw $this->initialFailure;
            }
            protected function abortReplacement(string $token): void {
                if ($this->abortFailure !== null) { throw $this->abortFailure; }
                parent::abortReplacement($token);
            }
        }
    }
    return AudioInventoryOwnerFaultInjection::class;
}

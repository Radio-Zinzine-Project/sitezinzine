<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Single-use preparation, without MP3 writes, temporary deletion or certification.
 * Assembly must hand over a dedicated workspace AND exporter using that same
 * workspace for exclusive synchronous use. Aliases cannot be revoked by PHP.
 * Workspace maxRuns is the hard cumulative creation limit; with no deletion it
 * also bounds active files. maxOpenStreams must cover K+3 streams during passes
 * (four during association building). Scanner directory handles are additional.
 * Workspace refusals propagate; nothing raises these budgets or retries.
 *
 * Memory is bounded batches, K IDs/heads, diagnostic budgets and maxPasses reports,
 * plus the EXISTING workspace registry proportional to owned files. Disk usage
 * accumulates retained generations; no hard disk quota or restart recovery exists.
 * Caller remains responsible for later operation-wide workspace disposal.
 */
class AudioInventoryInputPreparation
{
    private bool $used = false;
    private readonly AudioInventoryWorkspaceRunCatalog $catalog;
    private readonly AudioInventoryPathRunBuilder $pathBuilder;
    private readonly AudioInventoryAssociationRunBuilder $associationBuilder;
    private readonly AudioInventoryAssociationRunMerger $associationMerger;
    private ?AudioInventoryIncrementalRunCatalogWriter $pendingWriter = null;
    /** Only descriptors obtained directly by this operation, at most K+1. */
    private array $opened = [];
    private array $secondaryErrors = [];
    private array $physicalErrors = [];

    public function __construct(
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly AudioInventoryAssociationExporter $exporter,
        private readonly AudioInventoryScanner $scanner,
        private readonly AudioInventoryPathRunCodec $pathCodec,
        private readonly AudioInventoryAssociationRunCodec $associationCodec,
        private readonly int $maxInputRuns,
        private readonly int $maxRecordsPerRun,
        private readonly int $maxPathBytesPerRun,
        int $maxAssociationBytesPerRun,
        private readonly int $maxPhysicalDiagnostics,
        private readonly int $maxPasses,
    ) {
        if ($maxInputRuns < 2 || $maxRecordsPerRun < 1 || $maxPathBytesPerRun < 1
            || $maxAssociationBytesPerRun < 1 || $maxPhysicalDiagnostics < 0 || $maxPasses < 1) {
            throw new \InvalidArgumentException('Positive run/buffer/pass budgets, K >= 2 and nonnegative diagnostic budget are required.');
        }
        $this->catalog = $this->newCatalog();
        $this->pathBuilder = new AudioInventoryPathRunBuilder($pathCodec, $maxRecordsPerRun, $maxPathBytesPerRun);
        $this->associationBuilder = new AudioInventoryAssociationRunBuilder($associationCodec, $maxRecordsPerRun, $maxAssociationBytesPerRun);
        $this->associationMerger = new AudioInventoryAssociationRunMerger($associationCodec, $maxInputRuns);
    }

    /**
     * consumer(Iterator $paths, Iterator $associations, array $context): mixed.
     * Both iterators must be fully exhausted inside this scope. Every operation
     * on an escaped iterator, including current()/valid(), is refused afterwards.
     * Strict false interrupts without draining; any other return needs full
     * exhaustion. Consumer side effects remain provisional until method success.
     * Continue callback(stage, progress): bool may cancel SQL, physical collection
     * or after a complete merge pass. No callback may touch this workspace/SQL.
     *
     * Context deliberately contains NO storage_decision: no evidence producer is
     * implemented here. test_subset/web_full never gain absence permission from
     * mere completion, equal generations or supplied context. A future trusted
     * observer may evaluate the retained reports through StorageDecision.
     * Mode defaults to strict (original context/observer requirements).
     * observation_only allows unknown physical metadata and a null observer;
     * it is explicitly tagged in the report and scoped context, never certified.
     */
    public function withSortedInputs(string $root, array $physicalContext, ?\Closure $readGeneration, \Closure $consumer, ?\Closure $continue = null, string $mode = 'strict'): array
    {
        if ($this->used) { throw new \LogicException('Input preparation is single-use.'); }
        if (!in_array($mode, ['strict', 'observation_only'], true) || ($mode === 'strict' && $readGeneration === null)) {
            throw new \InvalidArgumentException('Expected a supported collection mode and a generation observer in strict mode.');
        }
        $this->used = true;
        $report = ['status' => 'interrupted', 'sql_export' => null, 'physical_collection' => null,
            'sql_sort' => null, 'physical_sort' => null, 'physical_errors' => [],
            'consumed_paths' => 0, 'consumed_associations' => 0, 'absence_conclusions_allowed' => false];
        if ($mode === 'observation_only') { $report['collection_mode'] = $mode; }
        try {
            $export = $this->exporter->export(fn (array $p): bool => $this->continuing($continue, 'sql', $p));
            $report['sql_export'] = $export;
            if ($export['status'] !== 'completed') { return $this->finishReport($report); }
            $this->beginSorting();
            $sql = $this->buildAssociations($export);
            [$physical, $paths] = $this->buildPhysical($root, $physicalContext, $readGeneration, $continue, $mode);
            $report['physical_collection'] = $physical;
            $report['physical_errors'] = $this->physicalErrors;
            if ($physical['status'] !== 'completed') {
                $this->abortPending();
                return $this->finishReport($report);
            }
            $sql = $this->reduce($sql, true, $continue);
            $report['sql_sort'] = $sql;
            if ($sql['status'] === 'interrupted') { return $this->finishReport($report); }
            $paths = $this->reduce($paths, false, $continue);
            $report['physical_sort'] = $paths;
            if ($paths['status'] === 'interrupted') { return $this->finishReport($report); }

            $pathId = $this->singleRun($paths['catalog_id']);
            $associationId = $this->singleRun($sql['catalog_id']);
            $this->beforeConsumption($pathId, $associationId);
            $context = ['storage' => 'emissionsMp3', 'scope' => $physical['scope'], 'references' => $export['references'],
                'physical_collection' => $physical, 'physical_errors' => $this->physicalErrors,
                'sql_uncertainties' => $export['uncertainties']];
            if ($mode === 'observation_only') { $context['collection_mode'] = $mode; }
            [$completed, $counts] = $this->consumeScoped($pathId, $associationId, $paths['records'], $sql['records'], $context, $consumer);
            $report['status'] = $completed ? 'completed' : 'interrupted';
            $report['consumed_paths'] = $counts[0]; $report['consumed_associations'] = $counts[1];
            return $this->finishReport($report);
        } catch (\Throwable $primary) {
            try { $this->closeMany(array_keys($this->opened)); } catch (\Throwable $error) { $this->secondary($error); }
            try { $this->abortPending(); } catch (\Throwable $error) { $this->secondary($error); }
            foreach ($this->exporter->getSecondaryErrors() as $error) { $this->secondary($error); }
            throw $primary;
        }
    }

    public function getSecondaryErrors(): array { return $this->secondaryErrors; }

    private function newCatalog(): AudioInventoryWorkspaceRunCatalog
    {
        // Workspace-generated IDs are 48 bytes; no MP3 path enters this codec.
        return new AudioInventoryWorkspaceRunCatalog($this->workspace, new AudioInventoryRunCatalogCodec(128));
    }

    private function newWriter(): AudioInventoryIncrementalRunCatalogWriter
    {
        return $this->pendingWriter = new AudioInventoryIncrementalRunCatalogWriter($this->workspace, 128);
    }

    private function finishWriter(int $expected): string
    {
        $writer = $this->pendingWriter;
        try { $result = $writer->finish(); }
        catch (\Throwable $primary) { foreach ($writer->getCloseFailures() as $error) { $this->secondary($error); } throw $primary; }
        if ($result['identifier_count'] !== $expected || $writer->getState() !== 'finalized') {
            throw new \UnexpectedValueException('Built catalog count is inconsistent.');
        }
        $this->pendingWriter = null;
        return $result['catalog_id'];
    }

    private function abortPending(): void
    {
        if ($this->pendingWriter === null) { return; }
        $writer = $this->pendingWriter; $this->pendingWriter = null;
        try { if ($writer->getState() !== 'finalized') { $writer->abort(); } }
        finally { foreach ($writer->getCloseFailures() as $error) { $this->secondary($error); } }
    }

    private function buildAssociations(array $export): array
    {
        $writer = $this->newWriter(); $runs = $records = $decoded = $files = 0;
        $this->consumeCatalog($export['catalog_id'], function (iterable $ids) use ($writer, &$runs, &$records, &$decoded, &$files): void {
            $source = (function () use ($ids, &$decoded, &$files): \Generator {
                foreach ($ids as $id) {
                    $stream = $this->openRead($id);
                    foreach ($this->associationCodec->read($stream) as $row) { ++$decoded; yield $row; }
                    $this->closeMany([$id]); ++$files;
                }
            })();
            $current = null;
            $built = $this->associationBuilder->build($source,
                function () use (&$current) { $current = $this->workspace->createRun(); return $this->openWrite($current); },
                function ($stream, array $meta) use (&$current, $writer, &$runs, &$records): void {
                    $this->closeMany([$current]); $this->finalizeRun($current); $writer->append($current);
                    ++$runs; $records += $meta['association_count'];
                });
            if ($built['status'] !== 'completed' || $built['associations_serialized'] !== $records || $built['runs_produced'] !== $runs) {
                throw new \UnexpectedValueException('Association builder counters are inconsistent.');
            }
        });
        if ($decoded !== $export['associations_written'] || $records !== $decoded || $files !== $export['files_exported']) {
            throw new \UnexpectedValueException('Export decoding did not preserve file/association counts.');
        }
        $id = $this->finishWriter($runs);
        $this->validateCatalogData($id, true, $runs, $records);
        return $this->stage($id, $runs, $records);
    }

    private function buildPhysical(string $root, array $context, ?\Closure $readGeneration, ?\Closure $continue, string $mode): array
    {
        $writer = $this->newWriter(); $buffer = []; $bytes = $runs = $records = $observed = 0;
        $flush = function () use (&$buffer, &$bytes, &$runs, &$records, $writer): void {
            if ($buffer === []) { return; }
            $current = null;
            $built = $this->pathBuilder->build($buffer,
                function () use (&$current) { $current = $this->workspace->createRun(); return $this->openWrite($current); },
                function ($stream, array $meta) use (&$current, &$runs, &$records, $writer): void {
                    $this->closeMany([$current]); $this->finalizeRun($current); $writer->append($current);
                    ++$runs; $records += $meta['path_count'];
                });
            if ($built['status'] !== 'completed' || $built['paths_serialized'] !== count($buffer)) {
                throw new \UnexpectedValueException('Physical builder did not consume its complete bounded batch.');
            }
            $buffer = []; $bytes = 0;
        };
        $observations = (function () use ($root): \Generator {
            foreach ($this->scanObservations($root) as $observation) {
                if (is_array($observation) && ($observation['type'] ?? null) === 'error') {
                    if (count($this->physicalErrors) >= $this->maxPhysicalDiagnostics) { throw new \LengthException('Physical diagnostic budget exhausted.'); }
                    $this->physicalErrors[] = $observation;
                }
                yield $observation;
            }
        })();
        $physical = (new AudioInventoryPhysicalCollector())->collect($observations, $context,
            function (array $observation) use (&$buffer, &$bytes, &$observed, &$runs, $flush, $continue): bool {
                $path = $observation['relative_path']; $length = strlen($path);
                if ($length > $this->maxPathBytesPerRun) { throw new \UnexpectedValueException('Path exceeds physical buffer byte budget.'); }
                if ($buffer !== [] && $bytes > $this->maxPathBytesPerRun - $length) { $flush(); }
                $buffer[] = $path; $bytes += $length; ++$observed;
                if (count($buffer) === $this->maxRecordsPerRun || $bytes === $this->maxPathBytesPerRun) { $flush(); }
                return $this->continuing($continue, 'physical', ['observed' => $observed, 'runs' => $runs]);
            }, $readGeneration, $mode);
        // Release our private scanner/decorator on interruption; never resume it.
        unset($observations);
        if ($physical['status'] !== 'completed') { return [$physical, null]; }
        $flush();
        if ($records !== $observed || count($this->physicalErrors) !== $physical['error_count']) {
            throw new \UnexpectedValueException('Physical observation/build counts differ.');
        }
        $id = $this->finishWriter($runs);
        $this->validateCatalogData($id, false, $runs, $records);
        return [$physical, $this->stage($id, $runs, $records)];
    }

    private function stage(string $id, int $runs, int $records): array
    {
        return ['status' => 'completed', 'catalog_id' => $id, 'initial_runs' => $runs, 'final_runs' => $runs, 'records' => $records, 'passes' => []];
    }

    private function reduce(array $stage, bool $associations, ?\Closure $continue): array
    {
        while ($stage['final_runs'] > 1) {
            if (count($stage['passes']) >= $this->maxPasses) { throw new \LengthException('Merge pass budget exhausted.'); }
            $before = $stage['final_runs'];
            if ($associations) { $id = $this->associationPass($stage['catalog_id'], $before); }
            else {
                $integrated = new AudioInventoryWorkspaceRunMerger($this->workspace, new AudioInventoryPathRunMerger($this->pathCodec, $this->maxInputRuns), $this->maxInputRuns);
                $pass = new AudioInventoryMergePass($this->workspace, $this->newCatalog(), $this->newCatalog(), $integrated, $this->pathCodec, $this->maxInputRuns);
                try { $result = $pass->run($stage['catalog_id']); }
                catch (\Throwable $primary) { foreach ($pass->getCloseFailures() as $error) { $this->secondary($error); } throw $primary; }
                $id = $result['output_catalog_id'];
            }
            $after = intdiv($before - 1, $this->maxInputRuns) + 1;
            $this->validateCatalogData($id, $associations, $after, $stage['records']);
            $stage['catalog_id'] = $id; $stage['final_runs'] = $after;
            $progress = ['input_runs' => $before, 'output_runs' => $after, 'records' => $stage['records']];
            $stage['passes'][] = $progress;
            if (!$this->continuing($continue, $associations ? 'sql_merge' : 'physical_merge', $progress)) { $stage['status'] = 'interrupted'; break; }
        }
        return $stage;
    }

    private function associationPass(string $input, int $expectedRuns): string
    {
        $writer = $this->newCatalog(); $processed = 0;
        try {
            $output = $this->consumeCatalog($input, function (iterable $ids) use ($writer, &$processed): string {
                return $writer->create((function () use ($ids, &$processed): \Generator {
                    $group = [];
                    foreach ($ids as $id) {
                        $group[] = $id; ++$processed;
                        if (count($group) === $this->maxInputRuns) { yield $this->mergeAssociationGroup($group); $group = []; }
                    }
                    if (count($group) > 1) { yield $this->mergeAssociationGroup($group); }
                    elseif ($group !== []) { $this->validateBlock($group[0], true); yield $group[0]; }
                })());
            });
        } catch (\Throwable $primary) { foreach ($writer->getCloseFailures() as $error) { $this->secondary($error); } throw $primary; }
        if ($processed !== $expectedRuns) { throw new \UnexpectedValueException('Association pass input count changed.'); }
        return $output;
    }

    private function mergeAssociationGroup(array $ids): string
    {
        $outputId = $this->workspace->createRun(); $output = $this->openWrite($outputId); $inputs = [];
        foreach ($ids as $id) { $inputs[] = $this->openRead($id); }
        $this->associationMerger->merge($inputs, $output);
        $this->closeMany([...$ids, $outputId]);
        $this->finalizeRun($outputId);
        return $outputId;
    }

    private function validateCatalogData(string $id, bool $associations, int $expectedRuns, int $expectedRecords): void
    {
        $runs = $records = 0;
        $this->consumeCatalog($id, function (iterable $ids) use ($associations, &$runs, &$records): void {
            foreach ($ids as $runId) { $records += $this->validateBlock($runId, $associations); ++$runs; }
        });
        if ($runs !== $expectedRuns || $records !== $expectedRecords) { throw new \UnexpectedValueException('Sorted stage did not preserve all run/record counters.'); }
    }

    private function validateBlock(string $id, bool $associations): int
    {
        $count = 0; $previous = null; $stream = $this->openRead($id);
        $codec = $associations ? $this->associationCodec : $this->pathCodec;
        foreach ($codec->read($stream) as $record) {
            $path = $associations ? $record['relative_path'] : $record;
            $this->checkOrder($previous, $path, $associations); $previous = $path; ++$count;
        }
        $this->closeMany([$id]); return $count;
    }

    private function consumeCatalog(string $id, \Closure $consumer): mixed
    {
        try { return $this->catalog->consume($id, $consumer); }
        catch (\Throwable $primary) { foreach ($this->catalog->getCloseFailures() as $error) { $this->secondary($error); } throw $primary; }
    }

    private function singleRun(string $catalogId): ?string
    {
        return $this->consumeCatalog($catalogId, static function (iterable $ids): ?string {
            $single = null;
            foreach ($ids as $id) { if ($single !== null) { throw new \UnexpectedValueException('Final catalog must contain at most one run.'); } $single = $id; }
            return $single;
        });
    }

    private function consumeScoped(?string $pathId, ?string $associationId, int $expectedPaths, int $expectedAssociations, array $context, \Closure $consumer): array
    {
        $active = true; $done = [false, false]; $counts = [0, 0]; $primary = null; $completed = false;
        try {
            $paths = $this->scopedIterator($pathId, false, $active, $done[0], $counts[0]);
            $associations = $this->scopedIterator($associationId, true, $active, $done[1], $counts[1]);
            $answer = $consumer($paths, $associations, $context);
            if ($answer !== false) {
                if (!$done[0] || !$done[1]) { throw new \LogicException('Both sorted streams must be fully exhausted inside consumption scope.'); }
                if ($counts !== [$expectedPaths, $expectedAssociations]) { throw new \UnexpectedValueException('Final stream counts differ from preparation.'); }
                $completed = true;
            }
        } catch (\Throwable $error) { $primary = $error; }
        finally {
            $active = false;
            try { $this->closeMany(array_keys($this->opened)); }
            catch (\Throwable $closing) { if ($primary === null) { $primary = $closing; } else { $this->secondary($closing); } }
        }
        if ($primary !== null) { throw $primary; }
        return [$completed, $counts];
    }

    private function scopedIterator(?string $id, bool $associations, bool &$active, bool &$done, int &$count): \Iterator
    {
        $stream = $id === null ? null : $this->openRead($id);
        $generator = (function () use ($stream, $associations, &$done, &$count): \Generator {
            $previous = null;
            if ($stream !== null) {
                $codec = $associations ? $this->associationCodec : $this->pathCodec;
                foreach ($codec->read($stream) as $record) {
                    $path = $associations ? $record['relative_path'] : $record;
                    $this->checkOrder($previous, $path, $associations); $previous = $path; ++$count; yield $record;
                }
            }
            $done = true;
        })();
        // Guard ALL iterator operations, including access to a cached current row.
        return new class($generator, static function () use (&$active): void { if (!$active) { throw new \LogicException('Sorted iterator is outside its consumption scope.'); } }) implements \Iterator {
            public function __construct(private \Generator $source, private \Closure $guard) {}
            public function rewind(): void { ($this->guard)(); $this->source->rewind(); }
            public function current(): mixed { ($this->guard)(); return $this->source->current(); }
            public function key(): mixed { ($this->guard)(); return $this->source->key(); }
            public function next(): void { ($this->guard)(); $this->source->next(); }
            public function valid(): bool { ($this->guard)(); return $this->source->valid(); }
        };
    }

    private function checkOrder(?string $previous, string $path, bool $associations): void
    {
        if ($previous !== null && (strcmp($previous, $path) > 0 || (!$associations && $previous === $path))) {
            throw new \UnexpectedValueException('Sorted run order or physical path uniqueness violated.');
        }
    }

    private function openRead(string $id): mixed { return $this->opened[$id] = $this->workspace->openRead($id); }
    private function openWrite(string $id): mixed { return $this->opened[$id] = $this->workspace->openWrite($id); }

    private function closeMany(array $ids): void
    {
        $primary = null;
        foreach ($ids as $id) {
            if (!array_key_exists($id, $this->opened)) { continue; }
            try { $this->closeRun($id); }
            catch (\Throwable $error) { if ($primary === null) { $primary = $error; } else { $this->secondary($error); } }
            finally { if (!is_resource($this->opened[$id])) { unset($this->opened[$id]); } }
        }
        if ($primary !== null) { throw $primary; }
    }

    private function continuing(?\Closure $continue, string $stage, array $progress): bool
    {
        if ($continue === null) { return true; }
        $result = $continue($stage, $progress);
        if (!is_bool($result)) { throw new \UnexpectedValueException('Continuation callback must return true or false.'); }
        return $result;
    }
    private function finishReport(array $report): array { return $report + ['secondary_errors' => $this->secondaryErrors]; }
    private function secondary(\Throwable $error): void { if (!in_array($error, $this->secondaryErrors, true)) { $this->secondaryErrors[] = $error; } }

    protected function scanObservations(string $root): iterable { return $this->scanner->scan($root); }
    protected function beginSorting(): void {}
    protected function beforeConsumption(?string $pathRun, ?string $associationRun): void {}
    protected function closeRun(string $id): void { $this->workspace->close($id); }
    protected function finalizeRun(string $id): void { $this->workspace->finalize($id); }
}

<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Single-use export, without sorting, deletion or a global list of run IDs.
 * Assembly must give this operation exclusive use of a fresh catalog writer,
 * its workspace and catalog reader, all sharing the SAME workspace. It must not
 * be modified concurrently. Files stay owned by workspace even after failure;
 * caller arranges workspace cleanup in finally. No fsync/crash recovery implied.
 *
 * One page is exported at a time. After collect() has committed and closed SQL,
 * finish() validates the catalog, then all association files are fully decoded.
 * A physically finalized file/catalog is not a published export on its own.
 * Memory: one page, one codec record, bounded URL diagnostics, plus the existing
 * workspace registry proportional to owned files (not a global O(1) guarantee).
 */
class AudioInventoryAssociationExporter
{
    private bool $used = false;
    private bool $abortAttempted = false;
    /** @var list<\Throwable> */
    private array $secondaryErrors = [];

    public function __construct(
        private readonly AudioInventoryReferenceCollector $collector,
        private readonly AudioInventoryTemporaryWorkspace $workspace,
        private readonly AudioInventoryAssociationRunCodec $codec,
        private readonly AudioInventoryIncrementalRunCatalogWriter $writer,
        private readonly AudioInventoryWorkspaceRunCatalog $catalog,
        private readonly int $maxUncertainties,
        private readonly int $pageSize = 500,
    ) {
        if ($maxUncertainties < 0 || $pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('Expected a nonnegative diagnostic budget and page size between 1 and 500.');
        }
    }

    /**
     * Optional continuation callback receives only scalar progress counters after
     * each fully exported page. It must return bool and never touch SQL/workspace.
     * False interrupts even on a terminal page: no catalog ID is published.
     * Diagnostic overflow throws instead of silently dropping URL uncertainties.
     * Exceptions propagate unchanged; consult getSecondaryErrors() on failure.
     * completed means a technically complete export of recognized associations,
     * NOT exhaustive references when references_complete=false, nor disk evidence.
     *
     * @return array{status:string,catalog_id:?string,pages_received:int,files_exported:int,associations_written:int,catalog_identifier_count:int,references:array,references_complete:bool,content_validated:bool,uncertainties:array,secondary_errors:array}
     */
    public function export(?\Closure $continue = null): array
    {
        if ($this->used || $this->writer->getState() !== 'open') {
            throw new \LogicException('Export requires an unused exporter and an open exclusively owned catalog writer.');
        }
        $this->used = true;
        $pages = $files = $written = $cursor = 0;
        $terminal = false;
        $uncertainties = [];
        try {
            $references = $this->collectPages(function (array $page) use (&$pages, &$files, &$written, &$cursor, &$terminal, &$uncertainties, $continue): bool {
                $this->validatePage($page, $cursor, $terminal);
                if (count($page['uncertainties']) > $this->maxUncertainties - count($uncertainties)) {
                    throw new \LengthException('URL uncertainty diagnostic budget exhausted.');
                }
                foreach ($page['uncertainties'] as $uncertainty) { $uncertainties[] = $uncertainty; }
                ++$pages;
                $cursor = $page['next_after_id'];
                $terminal = $page['exhausted'];
                if ($page['associations'] !== []) {
                    $id = $this->workspace->createRun();
                    $stream = $this->workspace->openWrite($id);
                    $failure = null;
                    $count = 0;
                    try {
                        $count = $this->writeAssociations($stream, $page['associations']);
                        if ($count !== count($page['associations'])) {
                            throw new \UnexpectedValueException('Codec count differs from page association count.');
                        }
                    } catch (\Throwable $error) { $failure = $error; }
                    $failure = $this->closePreservingFailure($id, $failure);
                    if ($failure !== null) { throw $failure; }
                    $this->finalizeRun($id);
                    $this->writer->append($id);
                    ++$files;
                    $written += $count;
                }
                if ($continue === null) { return true; }
                $answer = $continue(['pages_received' => $pages, 'files_exported' => $files, 'associations_written' => $written]);
                if (!is_bool($answer)) { throw new \UnexpectedValueException('Export continuation must return true or false.'); }
                return $answer;
            });

            $this->validateSummary($references, $pages, $written, count($uncertainties), $cursor, $terminal);
            if ($references['status'] === 'interrupted') {
                $this->abortCatalog();
                $this->captureSecondaryErrors();
                return $this->report('interrupted', null, $pages, $files, $written, 0, $references, $uncertainties, false);
            }

            $catalogReport = $this->writer->finish();
            if ($this->writer->getState() !== 'finalized'
                || !is_string($catalogReport['catalog_id'] ?? null) || $catalogReport['catalog_id'] === ''
                || ($catalogReport['identifier_count'] ?? null) !== $files) {
                throw new \UnexpectedValueException('Catalog final state, identifier or count is inconsistent with exported files.');
            }

            // Catalog and one association reader coexist: at most two file streams.
            $validatedFiles = $validatedAssociations = 0;
            $this->catalog->consume($catalogReport['catalog_id'], function (iterable $ids) use (&$validatedFiles, &$validatedAssociations): void {
                foreach ($ids as $id) {
                    $stream = $this->workspace->openRead($id);
                    $failure = null;
                    try {
                        foreach ($this->codec->read($stream) as $unused) { ++$validatedAssociations; }
                    } catch (\Throwable $error) { $failure = $error; }
                    $failure = $this->closePreservingFailure($id, $failure);
                    if ($failure !== null) { throw $failure; }
                    ++$validatedFiles;
                }
            });
            if ($validatedFiles !== $files || $validatedAssociations !== $written) {
                throw new \UnexpectedValueException('Validated file or association count differs from the export.');
            }
            $this->captureSecondaryErrors();
            return $this->report('completed', $catalogReport['catalog_id'], $pages, $files, $written, $validatedFiles, $references, $uncertainties, true);
        } catch (\Throwable $primary) {
            try { $this->abortCatalog(); }
            catch (\Throwable $secondary) { $this->addSecondary($secondary); }
            $this->captureSecondaryErrors();
            throw $primary;
        }
    }

    /** @return list<\Throwable> No logs or traces are emitted by this component. */
    public function getSecondaryErrors(): array { return $this->secondaryErrors; }

    private function validatePage(array $page, int $cursor, bool $terminal): void
    {
        if ($terminal || count($page) !== 4
            || !is_array($page['associations'] ?? null) || !array_is_list($page['associations'])
            || !is_array($page['uncertainties'] ?? null) || !array_is_list($page['uncertainties'])
            || !is_bool($page['exhausted'] ?? null) || !is_int($page['next_after_id'] ?? null)
            || $page['next_after_id'] < $cursor || (!$page['exhausted'] && $page['next_after_id'] <= $cursor)
            || count($page['associations']) > 2 * $this->pageSize || count($page['uncertainties']) > $this->pageSize) {
            throw new \UnexpectedValueException('Invalid SQL reference page structure or cursor.');
        }
        foreach ($page['uncertainties'] as $uncertainty) {
            if (!is_array($uncertainty) || count($uncertainty) !== 3
                || !is_int($uncertainty['emission_id'] ?? null) || $uncertainty['emission_id'] <= 0
                || ($uncertainty['field'] ?? null) !== 'url' || ($uncertainty['reason'] ?? null) !== 'uncertain_local_url') {
                throw new \UnexpectedValueException('Invalid URL uncertainty diagnostic.');
            }
        }
    }

    private function validateSummary(array $summary, int $pages, int $associations, int $uncertainties, int $cursor, bool $terminal): void
    {
        $status = $summary['status'] ?? null;
        if (!in_array($status, ['completed', 'interrupted'], true)
            || ($status === 'completed' && !$terminal)
            || ($summary['pages_read'] ?? null) !== $pages
            || ($summary['association_count'] ?? null) !== $associations
            || ($summary['uncertainty_count'] ?? null) !== $uncertainties
            || ($summary['next_after_id'] ?? null) !== $cursor
            || ($summary['temporal_consistency'] ?? null) !== 'repeatable_read'
            || ($summary['references_complete'] ?? null) !== ($status === 'completed' && $uncertainties === 0)) {
            throw new \UnexpectedValueException('SQL collection summary is inconsistent with exported pages.');
        }
    }

    private function report(string $status, ?string $id, int $pages, int $files, int $associations, int $ids, array $references, array $uncertainties, bool $validated): array
    {
        return ['status' => $status, 'catalog_id' => $id, 'pages_received' => $pages,
            'files_exported' => $files, 'associations_written' => $associations, 'catalog_identifier_count' => $ids,
            'references' => $references, 'references_complete' => $references['references_complete'],
            'content_validated' => $validated, 'uncertainties' => $uncertainties, 'secondary_errors' => $this->secondaryErrors];
    }

    private function closePreservingFailure(string $id, ?\Throwable $primary): ?\Throwable
    {
        try { $this->closeRun($id); }
        catch (\Throwable $closing) {
            if ($primary === null) { return $closing; }
            $this->addSecondary($closing);
        }
        return $primary;
    }

    private function abortCatalog(): void
    {
        if ($this->abortAttempted || $this->writer->getState() === 'finalized') { return; }
        $this->abortAttempted = true;
        $this->writer->abort();
    }

    private function addSecondary(\Throwable $error): void
    {
        if (!in_array($error, $this->secondaryErrors, true)) { $this->secondaryErrors[] = $error; }
    }

    private function captureSecondaryErrors(): void
    {
        foreach ($this->writer->getCloseFailures() as $error) { $this->addSecondary($error); }
        foreach ($this->catalog->getCloseFailures() as $error) { $this->addSecondary($error); }
    }

    // Narrow delegation seams, consistent with the existing integrated components.
    protected function collectPages(\Closure $consume): array { return $this->collector->collect($consume, $this->pageSize); }
    protected function writeAssociations(mixed $stream, array $rows): int { return $this->codec->write($stream, $rows); }
    protected function closeRun(string $id): void { $this->workspace->close($id); }
    protected function finalizeRun(string $id): void { $this->workspace->finalize($id); }
}

<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/**
 * Pure in-process lifecycle state, with no filesystem or persistence dependency.
 * One active catalog, one attempt, and one last retired catalog are retained.
 * Publication increments revision; begin/abort/retirement do not change it.
 * Retirement confirmation is declarative, never evidence of physical cleanup.
 *
 * Constant history: current and last known retired catalogs cannot be published
 * again. Publication overwrites the last retired ID; older IDs are forgotten.
 * Preventing reuse of those older IDs belongs to the future chain owner, which
 * must control creation and provenance. No global non-reuse is claimed here.
 *
 * Consultation is synchronous and non-nested; all incompatible transitions are
 * refused until its callback exits. A lazy result does not extend that scope.
 * This state neither validates catalogs/merge reports nor proves workspace
 * exclusivity. It grants no deletion permission, has no durable journal and
 * cannot support recovery after process restart or cross-process coordination.
 */
final class AudioInventoryCatalogChainState
{
    private int $revision = 0;
    private bool $consultationActive = false;
    private ?string $attemptId = null;
    private int $attemptSequence = 0;
    private readonly string $chainNonce;
    private ?string $lastRetiredCatalogId = null;
    private bool $retirementPending = false;

    public function __construct(
        private readonly string $operationId,
        private string $activeCatalogId,
    ) {
        $this->assertIdentifier($operationId);
        $this->assertIdentifier($activeCatalogId);
        // Different instances, even with the same declared operation ID, must
        // not accept each other's attempt tokens. No caller-supplied token.
        $this->chainNonce = bin2hex(random_bytes(16));
    }

    /** @param \Closure(string):mixed $consumer */
    public function withActiveCatalog(\Closure $consumer): mixed
    {
        $this->assertNoConsultation();
        if ($this->attemptId !== null) {
            throw new \DomainException('A replacement attempt prevents consultation.');
        }
        $this->consultationActive = true;
        try {
            return $consumer($this->activeCatalogId);
        } finally {
            $this->consultationActive = false;
        }
    }

    public function beginReplacement(string $expectedCatalogId): string
    {
        $this->assertIdentifier($expectedCatalogId);
        $this->assertNoConsultation();
        if ($expectedCatalogId !== $this->activeCatalogId) {
            throw new \DomainException('Expected catalog is no longer active.');
        }
        if ($this->attemptId !== null || $this->retirementPending) {
            throw new \DomainException('A replacement or retirement is already pending.');
        }
        if ($this->attemptSequence === PHP_INT_MAX) {
            throw new \OverflowException('Replacement attempt sequence exhausted.');
        }
        $token = $this->chainNonce.':'.($this->attemptSequence + 1);
        ++$this->attemptSequence;
        $this->attemptId = $token;
        return $token;
    }

    public function publishReplacement(string $attemptId, string $newCatalogId): void
    {
        $this->assertNoConsultation();
        $this->assertAttempt($attemptId);
        $this->assertIdentifier($newCatalogId);
        if ($newCatalogId === $this->activeCatalogId || $newCatalogId === $this->lastRetiredCatalogId) {
            throw new \DomainException('The active or last known retired catalog cannot be reactivated.');
        }
        if ($this->revision === PHP_INT_MAX) {
            throw new \OverflowException('Catalog revision exhausted.');
        }
        // All validation precedes mutation. No callbacks or external operations
        // occur between activating the new catalog and retiring the old one.
        $this->lastRetiredCatalogId = $this->activeCatalogId;
        $this->activeCatalogId = $newCatalogId;
        $this->retirementPending = true;
        ++$this->revision;
        $this->attemptId = null;
    }

    public function abortReplacement(string $attemptId): void
    {
        $this->assertNoConsultation();
        $this->assertAttempt($attemptId);
        $this->attemptId = null;
    }

    /** Declares retirement complete; does not inspect, close or delete any file. */
    public function confirmRetirement(string $expectedCatalogId): void
    {
        $this->assertNoConsultation();
        if (!$this->retirementPending || $expectedCatalogId !== $this->lastRetiredCatalogId) {
            throw new \DomainException('No matching catalog retirement is pending.');
        }
        $this->retirementPending = false;
        // Keep this one retired ID until the next publication, without history.
    }

    /**
     * Scalar snapshot copied by value. Neither token nor mutable state is exposed.
     * last_retired_catalog_id remains known after confirmation; retirement_pending
     * distinguishes an outstanding retirement from that retained local memory.
     * @return array{operation_id:string, active_catalog_id:string, revision:int, consultation_active:bool, replacement_in_progress:bool, retirement_pending:bool, last_retired_catalog_id:?string}
     */
    public function snapshot(): array
    {
        return [
            'operation_id' => $this->operationId,
            'active_catalog_id' => $this->activeCatalogId,
            'revision' => $this->revision,
            'consultation_active' => $this->consultationActive,
            'replacement_in_progress' => $this->attemptId !== null,
            'retirement_pending' => $this->retirementPending,
            'last_retired_catalog_id' => $this->lastRetiredCatalogId,
        ];
    }

    private function assertIdentifier(string $id): void
    {
        if (trim($id) === '') { throw new \InvalidArgumentException('A nonblank opaque identifier is required.'); }
    }

    private function assertNoConsultation(): void
    {
        if ($this->consultationActive) {
            throw new \DomainException('A catalog consultation is already in progress.');
        }
    }

    private function assertAttempt(string $id): void
    {
        if ($this->attemptId === null || $id !== $this->attemptId) {
            throw new \DomainException('Unknown, foreign or expired replacement attempt.');
        }
    }
}

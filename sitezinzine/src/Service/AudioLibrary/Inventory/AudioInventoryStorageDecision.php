<?php

declare(strict_types=1);

namespace App\Service\AudioLibrary\Inventory;

/** Pure consistency policy over supplied evidence, never a producer or authenticator of proofs. */
final class AudioInventoryStorageDecision
{
    /**
     * Expected policy: library_id, storage_kind (real|synthetic), generation,
     * scope and a nonempty list of trusted_sources. Values are compared exactly.
     * Invalid policy throws; missing or malformed observations fail closed.
     *
     * Observations:
     * - availability: accessible|absent|inaccessible|unknown;
     * - identity: library_id, storage_kind, generation, scope, source, evidence_id;
     * - collection: the same binding/provenance fields, plus status
     *   (completed|interrupted|failed), nonnegative integer error_count,
     *   generation_before and generation_after;
     * - completeness: the same binding/provenance fields, plus status
     *   (established|pending|failed) and validity (current|stale|unknown).
     *
     * Trusted observers must establish origin authenticity, collection coverage
     * and freshness before calling this policy. Source names and evidence IDs
     * are not authenticated here. Freshness is supplied explicitly and checked
     * against the expected generation: no clock, invented TTL or environment
     * heuristic is used. A witness, file count or declarative boolean cannot
     * substitute for the structured collection and completeness evidence.
     *
     * Precedence: availability, identity, collection, completeness. Reasons
     * identify the first failing stage, retaining its binding contradictions.
     * verified authorizes absence conclusions only within the expected scope,
     * generation and storage kind, never deletion or SQL/disk snapshot claims.
     *
     * @return array{state:'verified'|'unavailable'|'unverified'|'incomplete', reasons:list<string>, library_id:?string, storage_kind:?string, generation:?string, absence_conclusions_allowed:bool}
     */
    public function decide(array $expected, array $observations): array
    {
        $this->validatePolicy($expected);
        $identity = $observations['identity'] ?? null;
        $result = $this->initialResult($identity);

        $availability = $observations['availability'] ?? null;
        if ($availability === 'absent' || $availability === 'inaccessible') {
            return $this->refuse($result, 'unavailable', ['storage_' . $availability]);
        }
        if ($availability !== 'accessible') {
            return $this->refuse($result, 'unverified', ['availability_unknown']);
        }
        if ($identity === null) {
            return $this->refuse($result, 'unverified', ['identity_missing']);
        }
        if (!$this->isEvidence($identity)) {
            return $this->refuse($result, 'unverified', ['identity_invalid']);
        }
        $reasons = $this->bindingMismatches($expected, $identity, 'identity');
        if ($reasons !== []) {
            return $this->refuse($result, 'unverified', $reasons);
        }
        if (!in_array($identity['source'], $expected['trusted_sources'], true)) {
            return $this->refuse($result, 'unverified', ['identity_source_untrusted']);
        }

        $collection = $observations['collection'] ?? null;
        if ($collection === null) {
            return $this->refuse($result, 'unverified', ['collection_missing']);
        }
        if (!$this->isEvidence($collection)
            || !in_array($collection['status'] ?? null, ['completed', 'interrupted', 'failed'], true)
            || !is_int($collection['error_count'] ?? null) || $collection['error_count'] < 0
            || !$this->isText($collection['generation_before'] ?? null)
            || !$this->isText($collection['generation_after'] ?? null)) {
            return $this->refuse($result, 'unverified', ['collection_invalid']);
        }
        if (!in_array($collection['source'], $expected['trusted_sources'], true)) {
            return $this->refuse($result, 'unverified', ['collection_source_untrusted']);
        }
        $reasons = [];
        if ($collection['status'] !== 'completed') {
            $reasons[] = 'collection_' . $collection['status'];
        }
        if ($collection['error_count'] > 0) {
            $reasons[] = 'collection_errors';
        }
        $reasons = array_merge($reasons, $this->bindingMismatches($expected, $collection, 'collection'));
        if ($collection['generation_before'] !== $expected['generation'] || $collection['generation_after'] !== $expected['generation']) {
            $reasons[] = 'collection_generation_mismatch';
        }
        if ($collection['generation_before'] !== $collection['generation_after']) {
            $reasons[] = 'collection_generation_changed';
        }
        if ($reasons !== []) {
            return $this->refuse($result, 'incomplete', array_values(array_unique($reasons)));
        }

        $completeness = $observations['completeness'] ?? null;
        if ($completeness === null) {
            return $this->refuse($result, 'unverified', ['completeness_missing']);
        }
        if (!$this->isEvidence($completeness)
            || !in_array($completeness['status'] ?? null, ['established', 'pending', 'failed'], true)
            || !in_array($completeness['validity'] ?? null, ['current', 'stale', 'unknown'], true)) {
            return $this->refuse($result, 'unverified', ['completeness_invalid']);
        }
        $reasons = $this->bindingMismatches($expected, $completeness, 'completeness');
        if ($reasons !== []) {
            return $this->refuse($result, 'unverified', $reasons);
        }
        if (!in_array($completeness['source'], $expected['trusted_sources'], true)) {
            return $this->refuse($result, 'unverified', ['completeness_source_untrusted']);
        }
        if ($completeness['status'] === 'failed') {
            return $this->refuse($result, 'incomplete', ['completeness_failed']);
        }
        if ($completeness['status'] !== 'established') {
            return $this->refuse($result, 'unverified', ['completeness_not_established']);
        }
        if ($completeness['validity'] !== 'current') {
            return $this->refuse($result, 'unverified', [$completeness['validity'] === 'stale' ? 'completeness_stale' : 'completeness_validity_unknown']);
        }

        $result['state'] = 'verified';
        $result['absence_conclusions_allowed'] = true;
        return $result;
    }

    private function validatePolicy(array $expected): void
    {
        if (!$this->isBinding($expected) || !is_array($expected['trusted_sources'] ?? null) || $expected['trusted_sources'] === []) {
            throw new \InvalidArgumentException('Explicit library, kind, generation, scope and trusted sources are required.');
        }
        foreach ($expected['trusted_sources'] as $source) {
            if (!$this->isText($source)) {
                throw new \InvalidArgumentException('Trusted source identifiers must be nonempty strings.');
            }
        }
    }

    private function isBinding(mixed $value): bool
    {
        return is_array($value)
            && $this->isText($value['library_id'] ?? null)
            && in_array($value['storage_kind'] ?? null, ['real', 'synthetic'], true)
            && $this->isText($value['generation'] ?? null)
            && $this->isText($value['scope'] ?? null);
    }

    private function isEvidence(mixed $value): bool
    {
        return $this->isBinding($value)
            && $this->isText($value['source'] ?? null)
            && $this->isText($value['evidence_id'] ?? null);
    }

    private function isText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function bindingMismatches(array $expected, array $evidence, string $prefix): array
    {
        $reasons = [];
        foreach (['library_id', 'storage_kind', 'generation', 'scope'] as $field) {
            if ($evidence[$field] !== $expected[$field]) {
                $reasons[] = $prefix . '_' . $field . '_mismatch';
            }
        }
        return $reasons;
    }

    private function initialResult(mixed $identity): array
    {
        $identity = is_array($identity) ? $identity : [];
        return [
            'state' => 'unverified', 'reasons' => [],
            'library_id' => $this->isText($identity['library_id'] ?? null) ? $identity['library_id'] : null,
            'storage_kind' => in_array($identity['storage_kind'] ?? null, ['real', 'synthetic'], true) ? $identity['storage_kind'] : null,
            'generation' => $this->isText($identity['generation'] ?? null) ? $identity['generation'] : null,
            'absence_conclusions_allowed' => false,
        ];
    }

    private function refuse(array $result, string $state, array $reasons): array
    {
        $result['state'] = $state;
        $result['reasons'] = $reasons;
        return $result;
    }
}

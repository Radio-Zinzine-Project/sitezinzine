<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Inventory;

use App\Service\AudioLibrary\Inventory\AudioInventoryStorageDecision;
use PHPUnit\Framework\TestCase;

/** Evidence is synthetic: these tests neither establish nor authenticate a real storage proof. */
final class AudioInventoryStorageDecisionTest extends TestCase
{
    public function testMatchingEstablishedEvidenceAllowsAbsenceConclusions(): void
    {
        $observations = self::observations();
        $before = $observations;
        $expected = self::expected();
        $expectedBefore = $expected;
        self::assertSame([
            'state' => 'verified', 'reasons' => [], 'library_id' => 'zinzine-historical',
            'storage_kind' => 'real', 'generation' => 'generation-2', 'absence_conclusions_allowed' => true,
        ], (new AudioInventoryStorageDecision())->decide($expected, $observations));
        self::assertSame($before, $observations);
        self::assertSame($expectedBefore, $expected);
    }

    /** @dataProvider refusedEvidence */
    public function testMissingContradictoryOrIncompleteEvidenceNeverAuthorizesAbsence(
        array $observations,
        string $state,
        array $reasons,
    ): void {
        $before = $observations;
        $result = (new AudioInventoryStorageDecision())->decide(self::expected(), $observations);
        self::assertSame($state, $result['state']);
        self::assertSame($reasons, $result['reasons']);
        self::assertFalse($result['absence_conclusions_allowed']);
        self::assertSame($before, $observations);
        self::assertSame(['state', 'reasons', 'library_id', 'storage_kind', 'generation', 'absence_conclusions_allowed'], array_keys($result));
    }

    public static function refusedEvidence(): array
    {
        $valid = self::observations();
        $replace = static fn (string $section, array $changes): array => array_replace($valid, [$section => array_replace($valid[$section], $changes)]);

        return [
            'no observations' => [[], 'unverified', ['availability_unknown']],
            'directory existence is insufficient' => [['directory_exists' => true], 'unverified', ['availability_unknown']],
            'accessible empty directory without identity' => [['availability' => 'accessible', 'file_count' => 0], 'unverified', ['identity_missing']],
            'absent storage' => [array_replace($valid, ['availability' => 'absent']), 'unavailable', ['storage_absent']],
            'inaccessible storage' => [array_replace($valid, ['availability' => 'inaccessible']), 'unavailable', ['storage_inaccessible']],
            'unknown availability' => [array_replace($valid, ['availability' => 'unknown']), 'unverified', ['availability_unknown']],
            'malformed availability' => [array_replace($valid, ['availability' => true]), 'unverified', ['availability_unknown']],
            'identity absent' => [array_replace($valid, ['identity' => null]), 'unverified', ['identity_missing']],
            'boolean identity is not evidence' => [array_replace($valid, ['identity' => true]), 'unverified', ['identity_invalid']],
            'identity evidence has no origin' => [$replace('identity', ['source' => '']), 'unverified', ['identity_invalid']],
            'identity evidence has no identifier' => [$replace('identity', ['evidence_id' => '']), 'unverified', ['identity_invalid']],
            'different library' => [$replace('identity', ['library_id' => 'another-library']), 'unverified', ['identity_library_id_mismatch']],
            'synthetic cannot impersonate real' => [$replace('identity', ['storage_kind' => 'synthetic']), 'unverified', ['identity_storage_kind_mismatch']],
            'unknown storage kind' => [$replace('identity', ['storage_kind' => 'unknown']), 'unverified', ['identity_invalid']],
            'stale identity generation' => [$replace('identity', ['generation' => 'generation-1']), 'unverified', ['identity_generation_mismatch']],
            'different identity scope' => [$replace('identity', ['scope' => 'one-category']), 'unverified', ['identity_scope_mismatch']],
            'untrusted identity source' => [$replace('identity', ['source' => 'environment-variable']), 'unverified', ['identity_source_untrusted']],
            'no physical collection' => [array_replace($valid, ['collection' => null]), 'unverified', ['collection_missing']],
            'collection boolean is not a report' => [array_replace($valid, ['collection' => true]), 'unverified', ['collection_invalid']],
            'collection without provenance' => [$replace('collection', ['source' => '']), 'unverified', ['collection_invalid']],
            'collection source untrusted' => [$replace('collection', ['source' => 'unknown-scanner']), 'unverified', ['collection_source_untrusted']],
            'collection from another library' => [$replace('collection', ['library_id' => 'other']), 'incomplete', ['collection_library_id_mismatch']],
            'collection of synthetic storage' => [$replace('collection', ['storage_kind' => 'synthetic']), 'incomplete', ['collection_storage_kind_mismatch']],
            'collection without error count' => [$replace('collection', ['error_count' => null]), 'unverified', ['collection_invalid']],
            'numeric error count string is not coerced' => [$replace('collection', ['error_count' => '0']), 'unverified', ['collection_invalid']],
            'negative error count is invalid' => [$replace('collection', ['error_count' => -1]), 'unverified', ['collection_invalid']],
            'unknown collection status' => [$replace('collection', ['status' => 'maybe']), 'unverified', ['collection_invalid']],
            'interrupted collection' => [$replace('collection', ['status' => 'interrupted']), 'incomplete', ['collection_interrupted']],
            'failed collection' => [$replace('collection', ['status' => 'failed']), 'incomplete', ['collection_failed']],
            'completed traversal containing errors' => [$replace('collection', ['error_count' => 1]), 'incomplete', ['collection_errors']],
            'interruption with errors' => [$replace('collection', ['status' => 'interrupted', 'error_count' => 2]), 'incomplete', ['collection_interrupted', 'collection_errors']],
            'collection covers a previous generation' => [$replace('collection', ['generation_before' => 'generation-1', 'generation_after' => 'generation-1']), 'incomplete', ['collection_generation_mismatch']],
            'generation changes during traversal' => [$replace('collection', ['generation_after' => 'generation-3']), 'incomplete', ['collection_generation_mismatch', 'collection_generation_changed']],
            'collection only covers a subset' => [$replace('collection', ['scope' => 'one-category']), 'incomplete', ['collection_scope_mismatch']],
            'identity witness without completeness proof' => [array_replace($valid, ['completeness' => null]), 'unverified', ['completeness_missing']],
            'simple completeness boolean' => [array_replace($valid, ['completeness' => true]), 'unverified', ['completeness_invalid']],
            'proof missing its provenance' => [$replace('completeness', ['source' => '']), 'unverified', ['completeness_invalid']],
            'proof missing its identity' => [$replace('completeness', ['evidence_id' => '']), 'unverified', ['completeness_invalid']],
            'proof for another library' => [$replace('completeness', ['library_id' => 'another-library']), 'unverified', ['completeness_library_id_mismatch']],
            'proof for synthetic storage' => [$replace('completeness', ['storage_kind' => 'synthetic']), 'unverified', ['completeness_storage_kind_mismatch']],
            'proof for old generation' => [$replace('completeness', ['generation' => 'generation-1']), 'unverified', ['completeness_generation_mismatch']],
            'proof for different scope' => [$replace('completeness', ['scope' => 'one-category']), 'unverified', ['completeness_scope_mismatch']],
            'proof from untrusted source' => [$replace('completeness', ['source' => 'unverified-marker-file']), 'unverified', ['completeness_source_untrusted']],
            'proof not established' => [$replace('completeness', ['status' => 'pending']), 'unverified', ['completeness_not_established']],
            'proof failed' => [$replace('completeness', ['status' => 'failed']), 'incomplete', ['completeness_failed']],
            'proof expired according to its validator' => [$replace('completeness', ['validity' => 'stale']), 'unverified', ['completeness_stale']],
            'proof freshness unknown' => [$replace('completeness', ['validity' => 'unknown']), 'unverified', ['completeness_validity_unknown']],
            'malformed proof status' => [$replace('completeness', ['status' => true]), 'unverified', ['completeness_invalid']],
            'malformed proof validity' => [$replace('completeness', ['validity' => true]), 'unverified', ['completeness_invalid']],
        ];
    }

    public function testObservedMetadataRemainsVisibleEvenWhenIdentityDoesNotMatch(): void
    {
        $observations = self::observations();
        $observations['identity']['library_id'] = 'other';
        $observations['identity']['storage_kind'] = 'synthetic';
        $observations['identity']['generation'] = 'old';
        $result = (new AudioInventoryStorageDecision())->decide(self::expected(), $observations);
        self::assertSame('other', $result['library_id']);
        self::assertSame('synthetic', $result['storage_kind']);
        self::assertSame('old', $result['generation']);
        self::assertSame(['identity_library_id_mismatch', 'identity_storage_kind_mismatch', 'identity_generation_mismatch'], $result['reasons']);
        self::assertFalse($result['absence_conclusions_allowed']);
    }

    public function testUnknownMetadataIsNullAndNeverInventedFromExpectation(): void
    {
        $result = (new AudioInventoryStorageDecision())->decide(self::expected(), ['availability' => 'accessible']);
        self::assertNull($result['library_id']);
        self::assertNull($result['storage_kind']);
        self::assertNull($result['generation']);
    }

    public function testSyntheticStorageCanOnlyBeVerifiedAgainstItsOwnExplicitExpectation(): void
    {
        $expected = self::expected();
        $expected['library_id'] = 'isolated-fixture-library';
        $expected['storage_kind'] = 'synthetic';
        $observations = self::observations();
        foreach (['identity', 'collection', 'completeness'] as $section) {
            $observations[$section]['library_id'] = $expected['library_id'];
            $observations[$section]['storage_kind'] = 'synthetic';
        }
        $result = (new AudioInventoryStorageDecision())->decide($expected, $observations);
        self::assertSame('verified', $result['state']);
        self::assertTrue($result['absence_conclusions_allowed']);
        self::assertSame('synthetic', $result['storage_kind']);
    }

    public function testEnvironmentNamesFileCountsAndDeclarativeFlagsCannotCreateEvidence(): void
    {
        $decision = new AudioInventoryStorageDecision();
        foreach (['dev', 'preprod', 'prod', 'test'] as $environment) {
            $result = $decision->decide(self::expected(), [
                'environment' => $environment, 'availability' => 'accessible',
                'directory_exists' => true, 'file_count' => 10000, 'references_match' => true, 'complete' => true,
            ]);
            self::assertSame('unverified', $result['state']);
            self::assertFalse($result['absence_conclusions_allowed']);
        }
        $observations = self::observations();
        $observations['environment'] = 'dev';
        $observations['file_count'] = 0;
        self::assertSame('verified', $decision->decide(self::expected(), $observations)['state']);
    }

    /** @dataProvider invalidExpectations */
    public function testInvalidPolicyIsRejectedInsteadOfGuessingAnIdentity(array $expected): void
    {
        $decision = new AudioInventoryStorageDecision();
        $this->expectException(\InvalidArgumentException::class);
        $decision->decide($expected, self::observations());
    }

    public static function invalidExpectations(): array
    {
        $expected = self::expected();
        return [
            'missing policy' => [[]],
            'empty library' => [array_replace($expected, ['library_id' => ''])],
            'unknown kind' => [array_replace($expected, ['storage_kind' => 'production'])],
            'missing generation' => [array_replace($expected, ['generation' => null])],
            'empty scope' => [array_replace($expected, ['scope' => ''])],
            'no trusted source' => [array_replace($expected, ['trusted_sources' => []])],
            'invalid trusted source' => [array_replace($expected, ['trusted_sources' => [true]])],
        ];
    }

    public function testObservationOnlyPartialCollectionDoesNotBecomeEvidence(): void
    {
        $report = (new \App\Service\AudioLibrary\Inventory\AudioInventoryPhysicalCollector())->collect([],
            ['scope' => self::expected()['scope'], 'source' => 'controlled-transfer', 'evidence_id' => 'actual-observation'], static fn () => true, null, 'observation_only');
        $observations = self::observations(); $observations['collection'] = $report;
        $decision = (new AudioInventoryStorageDecision())->decide(self::expected(), $observations);
        self::assertSame('unverified', $decision['state']); self::assertFalse($decision['absence_conclusions_allowed']);
    }

    private static function expected(): array
    {
        return ['library_id' => 'zinzine-historical', 'storage_kind' => 'real', 'generation' => 'generation-2',
            'scope' => 'emissionsMp3/all', 'trusted_sources' => ['controlled-transfer']];
    }

    private static function observations(): array
    {
        $binding = ['library_id' => 'zinzine-historical', 'storage_kind' => 'real', 'generation' => 'generation-2',
            'scope' => 'emissionsMp3/all', 'source' => 'controlled-transfer'];
        return [
            'availability' => 'accessible',
            'identity' => $binding + ['evidence_id' => 'identity-attestation-2'],
            'collection' => $binding + ['evidence_id' => 'physical-collection-2', 'status' => 'completed', 'error_count' => 0,
                'generation_before' => 'generation-2', 'generation_after' => 'generation-2'],
            'completeness' => $binding + ['evidence_id' => 'completion-attestation-2', 'status' => 'established', 'validity' => 'current'],
        ];
    }
}

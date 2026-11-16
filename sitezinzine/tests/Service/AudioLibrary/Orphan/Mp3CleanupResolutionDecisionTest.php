<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\Orphan;

use App\Service\AudioLibrary\Orphan\Mp3CleanupResolutionDecision;
use PHPUnit\Framework\TestCase;

/** Decisions consume a locked-state snapshot; these tests provide no transaction or lock guarantee. */
final class Mp3CleanupResolutionDecisionTest extends TestCase
{
    public function testUnknownStatusRaisesADomainException(): void
    {
        $decision = new Mp3CleanupResolutionDecision();

        $this->expectException(\DomainException::class);
        $decision->decide(17, 'unknown', 42, ['issue_id' => 17, 'last_event_id' => 42]);
    }

    /** @dataProvider activeStatuses */
    public function testMatchingTokenAllowsResolutionWithoutExecutingIt(string $status): void
    {
        $decision = new Mp3CleanupResolutionDecision();
        $token = ['issue_id' => 17, 'last_event_id' => 42];
        $before = $token;
        $statusBefore = $status;

        self::assertSame('allowed', $decision->decide(17, $status, 42, $token));
        self::assertSame($before, $token);
        self::assertSame($statusBefore, $status);
    }

    public static function activeStatuses(): array
    {
        return [['open'], ['investigating']];
    }

    public function testNewEventMakesTheConsultationStale(): void
    {
        $decision = new Mp3CleanupResolutionDecision();
        $token = ['issue_id' => 17, 'last_event_id' => 42];
        $before = $token;
        $status = 'open';

        self::assertSame('stale_view', $decision->decide(17, $status, 43, $token));
        self::assertSame($before, $token);
        self::assertSame('open', $status);
    }

    public function testAbsentTokenIsInvalidRatherThanAnAutomaticRefresh(): void
    {
        $decision = new Mp3CleanupResolutionDecision();

        self::assertSame('invalid_token', $decision->decide(17, 'open', 42, null));
    }

    /** @dataProvider malformedTokens */
    public function testMalformedTokenIsExplicitlyRejected(mixed $token): void
    {
        $decision = new Mp3CleanupResolutionDecision();
        $before = $token;

        self::assertSame('invalid_token', $decision->decide(17, 'open', 42, $token));
        self::assertSame($before, $token);
    }

    public static function malformedTokens(): array
    {
        return [
            'not an array' => ['17:42'],
            'empty' => [[]],
            'missing issue' => [['last_event_id' => 42]],
            'missing event' => [['issue_id' => 17]],
            'numeric strings' => [['issue_id' => '17', 'last_event_id' => '42']],
            'zero issue' => [['issue_id' => 0, 'last_event_id' => 42]],
            'negative event' => [['issue_id' => 17, 'last_event_id' => -1]],
        ];
    }

    public function testTokenForAnotherIssueCannotAuthorizeThisIssue(): void
    {
        $decision = new Mp3CleanupResolutionDecision();

        self::assertSame('invalid_token', $decision->decide(18, 'open', 42, ['issue_id' => 17, 'last_event_id' => 42]));
    }

    public function testIssueWithoutEventsCannotBeResolved(): void
    {
        $decision = new Mp3CleanupResolutionDecision();

        self::assertSame('no_events', $decision->decide(17, 'open', null, ['issue_id' => 17, 'last_event_id' => 42]));
    }

    public function testAlreadyResolvedIssueNeverAuthorizesANewResolution(): void
    {
        $decision = new Mp3CleanupResolutionDecision();
        // The resolver owns the stored date: the decision receives no object or date to overwrite.
        $status = 'resolved';
        $token = ['issue_id' => 17, 'last_event_id' => 42];

        self::assertSame('already_resolved', $decision->decide(17, $status, 42, $token));
        self::assertSame('resolved', $status);
        self::assertSame(['issue_id' => 17, 'last_event_id' => 42], $token);
    }

    public function testReevaluationKeepsTheSubmittedTokenInsteadOfAcceptingNewEvents(): void
    {
        $decision = new Mp3CleanupResolutionDecision();
        $token = ['issue_id' => 17, 'last_event_id' => 42];

        self::assertSame('allowed', $decision->decide(17, 'open', 42, $token));
        self::assertSame('stale_view', $decision->decide(17, 'open', 43, $token));
        self::assertSame('stale_view', $decision->decide(17, 'open', 44, $token));
        self::assertSame(['issue_id' => 17, 'last_event_id' => 42], $token);
    }
}

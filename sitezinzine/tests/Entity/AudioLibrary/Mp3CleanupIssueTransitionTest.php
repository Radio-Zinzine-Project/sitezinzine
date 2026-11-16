<?php

declare(strict_types=1);

namespace App\Tests\Entity\AudioLibrary;

use App\Entity\AudioLibrary\Mp3CleanupEvent;
use App\Entity\AudioLibrary\Mp3CleanupIssue;
use PHPUnit\Framework\TestCase;

/** Pure entity transitions, without ORM persistence or filesystem collaborators. */
final class Mp3CleanupIssueTransitionTest extends TestCase
{
    public function testOpenIssueCanStartInvestigationWithoutBeingResolved(): void
    {
        $issue = new Mp3CleanupIssue();

        $issue->startInvestigation();

        self::assertSame('investigating', $issue->getStatus());
        self::assertNull($issue->getResolvedAt());
    }

    public function testStartingInvestigationTwiceIsIdempotent(): void
    {
        $issue = new Mp3CleanupIssue();
        $issue->startInvestigation();
        $issue->startInvestigation();

        self::assertSame('investigating', $issue->getStatus());
        self::assertNull($issue->getResolvedAt());
    }

    public function testResolvedIssueCannotRestartInvestigationAndKeepsItsResolution(): void
    {
        $issue = new Mp3CleanupIssue();
        $issue->resolve(new \DateTimeImmutable('2026-11-15 12:13:14.123456+00:00'));
        $firstResolution = $issue->getResolvedAt();
        $caught = null;

        try {
            $issue->startInvestigation();
        } catch (\DomainException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(\DomainException::class, $caught);
        self::assertNotSame('', $caught->getMessage());
        self::assertSame('resolved', $issue->getStatus());
        self::assertSame($firstResolution, $issue->getResolvedAt());
    }

    /** @dataProvider resolvableStates */
    public function testResolutionFromEitherActiveStatePreservesInstantInUtc(bool $investigating): void
    {
        $issue = new Mp3CleanupIssue();
        if ($investigating) {
            $issue->startInvestigation();
        }
        $provided = new \DateTimeImmutable('2026-11-15 01:02:03.123456+05:30');
        $original = $provided->format('Y-m-d H:i:s.u P');
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');
            $issue->resolve($provided);
            $resolved = $issue->getResolvedAt();

            self::assertSame('resolved', $issue->getStatus());
            self::assertInstanceOf(\DateTimeImmutable::class, $resolved);
            self::assertSame('UTC', $resolved->getTimezone()->getName());
            self::assertSame('2026-11-14 19:32:03.123456', $resolved->format('Y-m-d H:i:s.u'));
            self::assertSame($provided->format('U.u'), $resolved->format('U.u'));
            self::assertSame($original, $provided->format('Y-m-d H:i:s.u P'));
            self::assertSame('Pacific/Honolulu', date_default_timezone_get());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public static function resolvableStates(): array
    {
        return ['open' => [false], 'investigating' => [true]];
    }

    public function testRepeatedResolutionKeepsTheFirstDate(): void
    {
        $issue = new Mp3CleanupIssue();
        $issue->resolve(new \DateTimeImmutable('2026-11-15 12:13:14.000007+02:00'));
        $first = $issue->getResolvedAt();

        $issue->resolve(new \DateTimeImmutable('2026-11-20 09:00:00.987654-03:00'));

        self::assertSame('resolved', $issue->getStatus());
        self::assertSame($first, $issue->getResolvedAt());
        self::assertSame('2026-11-15 10:13:14.000007', $issue->getResolvedAt()->format('Y-m-d H:i:s.u'));
    }

    public function testTransitionsPreserveTheEventCollectionAndHistoricalObjects(): void
    {
        $issue = new Mp3CleanupIssue();
        $events = $issue->getEvents();
        $first = new Mp3CleanupEvent();
        $second = new Mp3CleanupEvent();
        $events->add($first);
        $events->add($second);
        $before = serialize($events->toArray());

        $issue->startInvestigation();
        $issue->startInvestigation();
        self::assertSame($events, $issue->getEvents());
        self::assertSame([$first, $second], $events->toArray());
        self::assertSame($before, serialize($events->toArray()));

        $issue->resolve(new \DateTimeImmutable('2026-11-15 12:00:00.123456+00:00'));
        $issue->resolve(new \DateTimeImmutable('2026-11-16 12:00:00.000000+00:00'));
        self::assertSame($events, $issue->getEvents());
        self::assertSame([$first, $second], $events->toArray());
        self::assertSame($before, serialize($events->toArray()));
    }

    public function testThereAreNoPublicArbitraryStatusOrResolutionSetters(): void
    {
        $class = new \ReflectionClass(Mp3CleanupIssue::class);
        foreach (['setStatus', 'setResolvedAt'] as $method) {
            self::assertFalse($class->hasMethod($method) && $class->getMethod($method)->isPublic());
        }
        self::assertTrue($class->getProperty('status')->isPrivate());
        self::assertTrue($class->getProperty('resolvedAt')->isPrivate());
    }
}

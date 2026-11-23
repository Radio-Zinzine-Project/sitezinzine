<?php

declare(strict_types=1);

namespace App\Tests\Service\AudioLibrary\RadioExport\Manifest;

use App\Service\AudioLibrary\RadioExport\Manifest\RadioDifferentialPlan;
use PHPUnit\Framework\TestCase;

final class RadioDifferentialPlanTest extends TestCase
{
    private const WEEK = '2026-11-17';

    /** @dataProvider changes */
    public function testChanges(array $before, array $after, array $types, array $copies, array $removed): void
    {
        $planner = new RadioDifferentialPlan();
        $a = $before === [] ? null : $planner->plan(self::WEEK, 'A', hash('sha256', 'A'), $before);
        $b = $planner->plan(self::WEEK, 'B', hash('sha256', 'B'), $after, $a, $a === null ? [] : [$this->verified('A', $before)]);
        self::assertSame($types, array_column($b['expected'], 'changes', 'diffusionId'));
        self::assertSame($copies, $b['copyIds']);
        self::assertSame($removed, array_column($b['removed'], 'diffusionId'));
        self::assertSame($b, $planner->plan(self::WEEK, 'B', hash('sha256', 'B'), array_reverse($after), $a,
            $a === null ? [] : [$this->verified('A', array_reverse($before))]));
    }

    public static function changes(): iterable
    {
        $one = self::item(1, 'First.mp3', 'a');
        $two = self::item(2, 'Second.mp3', 'a');
        yield 'first preparation and identical bytes under different names' => [[], [$two, $one], [1 => ['added'], 2 => ['added']], [1, 2], []];
        yield 'only unchanged' => [[$one, $two], [$two, $one], [1 => ['unchanged'], 2 => ['unchanged']], [], []];
        yield 'multiple replacements at the same names' => [[$one, $two], [self::item(1, 'First.mp3', 'b'), self::item(2, 'Second.mp3', 'b')], [1 => ['replaced'], 2 => ['replaced']], [1, 2], []];
        yield 'renamed without changing bytes' => [[$one], [self::item(1, 'NewTitle.mp3', 'a')], [1 => ['renamed']], [1], []];
        yield 'moved broadcast and changed content' => [[$one], [self::item(1, 'NewTime.mp3', 'b')], [1 => ['renamed', 'replaced']], [1], []];
        yield 'removed without replacement' => [[$one], [], [], [], [1]];
        yield 'added broadcast' => [[$one], [$one, $two], [1 => ['unchanged'], 2 => ['added']], [2], []];
        yield 'source unavailable' => [[$one], [self::item(1, 'First.mp3', null)], [1 => ['unavailable']], [], []];
        yield 'recreated broadcast' => [[$one], [self::item(3, 'First.mp3', 'a')], [3 => ['added']], [3], [1]];
    }

    public function testChainPointsDirectlyAtTheArchivesThatContainTheBytes(): void
    {
        $planner = new RadioDifferentialPlan();
        $first = self::item(1, 'First.mp3', 'a');
        $second = self::item(2, 'Second.mp3', 'b');
        $a = $planner->plan(self::WEEK, 'A', hash('sha256', 'A'), [$first]);
        $publishedA = $this->verified('A', [$first]);
        $b = $planner->plan(self::WEEK, 'B', hash('sha256', 'B'), [$first, $second], $a, [$publishedA]);
        $publishedB = $this->verified('B', [$second]);
        $c = $planner->plan(self::WEEK, 'C', hash('sha256', 'C'), [$second, $first], $b, [$publishedB, $publishedA]);
        self::assertSame(['A', 'B'], array_column(array_column($c['expected'], 'origin'), 'attemptId'));
        self::assertSame([], $c['copyIds']);
        self::assertSame($c, $planner->plan(self::WEEK, 'C', hash('sha256', 'C'), [$first, $second], $b, [$publishedA, $publishedB]));

        $withoutProof = $planner->plan(self::WEEK, 'C', hash('sha256', 'C'), [$first, $second], $b, [$publishedB]);
        self::assertSame([1], $withoutProof['copyIds']);
        self::assertNull($withoutProof['expected'][0]['origin']);
        self::assertSame('B', $withoutProof['expected'][1]['origin']['attemptId']);
    }

    public function testAnUnverifiedOrMismatchedArchiveDoesNotProvideProvenance(): void
    {
        $planner = new RadioDifferentialPlan();
        $file = self::item(1, 'First.mp3', 'a');
        $a = $planner->plan(self::WEEK, 'A', hash('sha256', 'A'), [$file]);
        $wrongArtifact = $this->verified('A', [self::item(1, 'First.mp3', 'b')]);
        $partial = $this->verified('A', [$file]);
        unset($partial['archive']);
        foreach ([[], [$wrongArtifact], [$partial], [$partial + ['archive' => null]]] as $proof) {
            $b = $planner->plan(self::WEEK, 'B', hash('sha256', 'B'), [$file], $a, $proof);
            self::assertSame([1], $b['copyIds']);
            self::assertNull($b['expected'][0]['origin']);
        }
        $b = $planner->plan(self::WEEK, 'B', hash('sha256', 'B'), [self::item(1, 'First.mp3', null)], $a);
        self::assertSame([], $b['copyIds']);
        self::assertSame('source_absent', $b['expected'][0]['issue']);
        self::assertSame($file['artifact'], $b['expected'][0]['previous']);
    }

    public function testCurrentOccurrenceCannotSupplyItsOwnProvenance(): void
    {
        $planner = new RadioDifferentialPlan();
        $file = self::item(1, 'First.mp3', 'a');
        $a = $planner->plan(self::WEEK, 'A', hash('sha256', 'A'), [$file]);
        $file['origin'] = ['attemptId' => 'A', 'artifact' => $file['artifact']];
        $b = $planner->plan(self::WEEK, 'B', hash('sha256', 'B'), [$file], $a);
        self::assertNull($b['expected'][0]['origin']);
        self::assertSame([1], $b['copyIds']);
    }

    private static function item(int $id, string $name, ?string $digest): array
    {
        return ['diffusionId' => $id, 'filename' => $name, 'admission' => 'candidate',
            'artifact' => $digest === null ? null : ['diffusionId' => $id, 'filename' => $name, 'size' => 7, 'sha256' => str_repeat($digest, 64)],
            'issue' => $digest === null ? 'source_absent' : null];
    }

    private function verified(string $id, array $items): array
    {
        return ['version' => 2, 'week' => self::WEEK, 'attemptId' => $id, 'status' => 'ready', 'revision' => hash('sha256', $id),
            'archive' => ['path' => 'audio.zip', 'size' => 90, 'sha256' => str_repeat('9', 64)],
            'artifacts' => array_column($items, 'artifact')];
    }
}

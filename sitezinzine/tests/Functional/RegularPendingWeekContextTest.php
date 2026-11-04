<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Diffusion;
use App\Entity\PendingRebroadcast;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Service\GridAssignmentService;
use App\Tests\Support\FunctionalTestCase;

final class RegularPendingWeekContextTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->loginUser($this->createUser('ROLE_ADMIN'));
    }

    /** @dataProvider invalidWeeks */
    public function testExplicitInvalidWeekReturnsJson400(mixed $week): void
    {
        $this->client->request('GET', '/admin/pending-rebroadcasts', ['weekStart' => $week]);
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertSame([], $data['items']);
    }

    public static function invalidWeeks(): iterable
    {
        foreach (['', 'no-date', '2099-02-30', '2099-13-01', '2099-1-6', '2099-01-06T00:00:00', ['2099-01-06'], null] as $index => $week) {
            yield 'invalid ' . $index => [$week];
        }
    }

    public function testMissingWeekKeepsLegacyVisibilityAndMondayIsNormalizedLikeTheGrid(): void
    {
        $fixture = $this->group();
        self::assertContains($fixture['pending']->getId(), $this->ids([]));
        self::assertNotContains($fixture['pending']->getId(), $this->ids(['weekStart' => '2099-01-06']));
        self::assertNotContains($fixture['pending']->getId(), $this->ids(['weekStart' => '2099-01-12']));
        self::assertContains($fixture['pending']->getId(), $this->ids(['weekStart' => '2099-01-13']));
    }

    public function testPartialUnidentifiableHistoryAlsoKeepsLegacyVisibility(): void
    {
        $fixture = $this->group();
        $unknown = (new Diffusion())->setEmission($fixture['emission'])->setAssignmentGroupKey($fixture['key'])
            ->setNombreDiffusion(2)->setSchedule(new \DateTime('2099-02-03 10:00:00'), 60)->markAsPublished();
        $this->entityManager->persist($unknown);
        $this->entityManager->flush();
        self::assertContains($fixture['pending']->getId(), $this->ids(['weekStart' => '2099-01-06']));
    }

    public function testRegularPendingWithoutAdditionalOptionKeepsLegacyVisibility(): void
    {
        $fixture = $this->group();
        // Représente une ancienne ligne conservée, pas un nouveau parcours de création.
        $fixture['rule']->setHasAdditionalRebroadcast(false);
        $this->entityManager->flush();
        self::assertContains($fixture['pending']->getId(), $this->ids(['weekStart' => '2099-01-06']));
    }

    private function group(): array
    {
        $category = $this->createCategory();
        $rule = (new ProgrammationRule())->setCategory($category)->setRuleNumber(1)->setIsActive(true)
            ->setHasAdditionalRebroadcast(true)->setValidFrom(new \DateTimeImmutable('2099-01-01'))
            ->setValidUntil(new \DateTimeImmutable('2099-12-31'));
        $slot = (new ProgrammationRuleSlot())->setBroadcastRank(1)->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('10:00'))->setDurationMinutes(60)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)->setIsActive(true);
        $rule->addSlot($slot);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();
        $emission = $this->createEmission($this->marker, null, $category);
        self::assertTrue(static::getContainer()->get(GridAssignmentService::class)->assign($slot, $emission, new \DateTimeImmutable('2099-01-06 10:00:00')));
        $key = sprintf('rule_%d_origin_20990106_1000', $rule->getId());
        $pending = $this->entityManager->getRepository(PendingRebroadcast::class)->findOneBy(['assignmentGroupKey' => $key]);
        self::assertNotNull($pending);
        return compact('rule', 'emission', 'key', 'pending');
    }

    private function ids(array $query): array
    {
        $connection = $this->entityManager->getConnection();
        $before = [];
        foreach (['pending_rebroadcast', 'diffusion_draft', 'diffusion', 'grid_slot_arbitration'] as $table) {
            $before[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id');
        }
        $this->client->request('GET', '/admin/pending-rebroadcasts', $query);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' ORDER BY id'));
        }
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        return array_column(array_values(array_filter($data['items'], static fn(array $item): bool => $item['type'] === 'pending_rebroadcast')), 'id');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Service\PendingRebroadcastService;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PendingRebroadcastServiceIntegrationTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private PendingRebroadcastService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDoctrine();

        $this->service = static::getContainer()
            ->get(PendingRebroadcastService::class);
    }

    protected function tearDown(): void
    {
        try {
            $this->closeEntityManager();
        } finally {
            parent::tearDown();
        }
    }

    public function testPopulateCountsDiffusionsSeparatelyForEmissionsSharingSameGroup(): void
    {
        $category = $this->createCategory();

        $emissionA = $this->createEmission(
            $category,
            'Integration pending A'
        );

        $emissionB = $this->createEmission(
            $category,
            'Integration pending B'
        );

        $groupKey = 'manual_shared_' . bin2hex(random_bytes(6));

        $diffusionA1 = $this->createDiffusion(
            $emissionA,
            $groupKey,
            '2026-09-01 08:00:00',
            1
        );

        $this->createDiffusion(
            $emissionA,
            $groupKey,
            '2026-09-02 08:00:00',
            2
        );

        $this->createDiffusion(
            $emissionA,
            $groupKey,
            '2026-09-03 08:00:00',
            3
        );

        $diffusionB1 = $this->createDiffusion(
            $emissionB,
            $groupKey,
            '2026-09-04 08:00:00',
            1
        );

        $this->entityManager->flush();

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusionA1, $diffusionB1],
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        self::assertCount(1, $created);
        self::assertSame($emissionB, $created[0]->getEmission());
        self::assertSame($groupKey, $created[0]->getAssignmentGroupKey());
        self::assertTrue($created[0]->isPending());

        $this->entityManager->flush();

        $stored = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy(['assignmentGroupKey' => $groupKey]);

        self::assertCount(1, $stored);
        self::assertSame(
            $emissionB->getId(),
            $stored[0]->getEmission()?->getId()
        );
    }

    public function testPopulateDoesNotDuplicatePendingBeforeFlush(): void
    {
        $category = $this->createCategory();

        $emission = $this->createEmission(
            $category,
            'Integration pending idempotence'
        );

        $groupKey = 'manual_idempotent_' . bin2hex(random_bytes(6));

        $diffusion = $this->createDiffusion(
            $emission,
            $groupKey,
            '2026-09-05 08:00:00',
            1
        );

        $this->entityManager->flush();

        $before = new \DateTimeImmutable('2026-09-15 00:00:00');

        $first = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        // Aucun flush entre les deux appels.
        $second = $this->service->populateFromPublishedDiffusions(
            [$diffusion],
            $before
        );

        self::assertCount(1, $first);
        self::assertSame([], $second);

        $this->entityManager->flush();

        $stored = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy([
                'emission' => $emission,
                'assignmentGroupKey' => $groupKey,
            ]);

        self::assertCount(1, $stored);
        self::assertSame($first[0]->getId(), $stored[0]->getId());
    }

    public function testPopulateDoesNotRecreateDismissedPendingForSameEmission(): void
    {
        $category = $this->createCategory();

        $emissionA = $this->createEmission(
            $category,
            'Integration dismissed A'
        );

        $emissionB = $this->createEmission(
            $category,
            'Integration dismissed B'
        );

        $groupKey = 'manual_dismissed_' . bin2hex(random_bytes(6));

        $diffusionA = $this->createDiffusion(
            $emissionA,
            $groupKey,
            '2026-09-01 08:00:00',
            1
        );

        $diffusionB = $this->createDiffusion(
            $emissionB,
            $groupKey,
            '2026-09-01 09:00:00',
            1
        );

        $dismissed = (new PendingRebroadcast())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->dismiss();

        $this->entityManager->persist($dismissed);
        $this->entityManager->flush();

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusionA, $diffusionB],
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        self::assertCount(1, $created);
        self::assertSame($emissionB, $created[0]->getEmission());
        self::assertTrue($created[0]->isPending());

        $this->entityManager->flush();

        $stored = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy(['assignmentGroupKey' => $groupKey]);

        self::assertCount(2, $stored);

        $byEmission = [];

        foreach ($stored as $pending) {
            $byEmission[$pending->getEmission()?->getId()] = $pending;
        }

        self::assertTrue($byEmission[$emissionA->getId()]->isDismissed());
        self::assertTrue($byEmission[$emissionB->getId()]->isPending());
    }

    public function testPopulateIgnoresOnlyEmissionWithPlacedManualRebroadcast(): void
    {
        $category = $this->createCategory();

        $emissionA = $this->createEmission(
            $category,
            'Integration placed A'
        );

        $emissionB = $this->createEmission(
            $category,
            'Integration placed B'
        );

        $groupKey = 'manual_placed_' . bin2hex(random_bytes(6));

        $diffusionA = $this->createDiffusion(
            $emissionA,
            $groupKey,
            '2026-09-01 08:00:00',
            1
        );

        $diffusionB = $this->createDiffusion(
            $emissionB,
            $groupKey,
            '2026-09-01 09:00:00',
            1
        );

        $draft = (new \App\Entity\DiffusionDraft())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->setDraftType(
                \App\Entity\DiffusionDraft::TYPE_MANUAL_REBROADCAST
            )
            ->setNombreDiffusion(2)
            ->setSchedule(
                new \DateTimeImmutable('2026-09-10 10:00:00'),
                60
            );

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        $created = $this->service->populateFromPublishedDiffusions(
            [$diffusionA, $diffusionB],
            new \DateTimeImmutable('2026-09-15 00:00:00')
        );

        self::assertCount(1, $created);
        self::assertSame($emissionB, $created[0]->getEmission());
        self::assertSame($groupKey, $created[0]->getAssignmentGroupKey());

        $this->entityManager->flush();

        $stored = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy(['assignmentGroupKey' => $groupKey]);

        self::assertCount(1, $stored);
        self::assertSame(
            $emissionB->getId(),
            $stored[0]->getEmission()?->getId()
        );
    }

    private function createCategory(): Categories
    {
        $marker = bin2hex(random_bytes(6));

        do {
            $slug = chr(random_int(65, 90))
                . chr(random_int(65, 90))
                . chr(random_int(65, 90));
        } while (
            null !== $this->entityManager
            ->getRepository(Categories::class)
            ->findOneBy(['slug' => $slug])
        );

        $editor = (new Editeur())
            ->setName('Integration editor ' . $marker)
            ->setUpdateAt(new \DateTime());

        $category = (new Categories())
            ->setTitre('Integration category ' . $marker)
            ->setSlug($slug)
            ->setDuree(60)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setDescriptif('Categorie de test integration')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($editor);

        $this->entityManager->persist($editor);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    private function createEmission(
        Categories $category,
        string $title
    ): Emission {
        $emission = (new Emission())
            ->setTitre($title)
            ->setKeyword('integration')
            ->setDatepub(new \DateTime('2026-09-01'))
            ->setRef('INT-' . bin2hex(random_bytes(6)))
            ->setDuree(60)
            ->setUrl('https://example.test/integration.mp3')
            ->setDescriptif('Emission de test integration')
            ->setCategorie($category)
            ->setUpdatedAt(new \DateTime())
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        return $emission;
    }

    private function createDiffusion(
        Emission $emission,
        string $groupKey,
        string $startsAt,
        int $number
    ): Diffusion {
        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setSchedule(
                new \DateTimeImmutable($startsAt),
                60
            )
            ->setNombreDiffusion($number)
            ->setAssignmentGroupKey($groupKey)
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->entityManager->persist($diffusion);

        return $diffusion;
    }
}

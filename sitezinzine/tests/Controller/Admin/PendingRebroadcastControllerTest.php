<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Entity\User;
use App\Entity\Diffusion;
use App\Entity\Categories;
use App\Entity\Editeur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PendingRebroadcastControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);

        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (
            isset($this->entityManager)
            && $this->entityManager->getConnection()->isTransactionActive()
        ) {
            $this->entityManager->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    private function createUser(array $roles): User
    {
        $user = new User();

        $user
            ->setUsername('pending_' . uniqid())
            ->setEmail('pending_' . uniqid() . '@example.test')
            ->setPassword('password')
            ->setRoles($roles)
            ->setVerified(true)
            ->setApproved(true);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }


    private function createEmission(?Categories $categorie = null): Emission
    {
        $emission = new Emission();

        $emission
            ->setTitre('Émission test parc ' . uniqid())
            ->setDuree(60)
            ->setDatepub(new \DateTime())
            ->setUpdatedAt(new \DateTime())
            ->setCategorie($categorie);

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        return $emission;
    }


    private function createDraft(
        Emission $emission,
        \DateTimeImmutable $startsAt,
        int $nombreDiffusion,
        string $groupKey
    ): DiffusionDraft {
        $draft = new DiffusionDraft();

        $draft
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_REGULAR)
            ->setNombreDiffusion($nombreDiffusion)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule($startsAt, 60);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    private function mockRegularOverlap(): void
    {
        $regularOverlap = [
            'slotId' => 123,
            'startsAt' => '2026-10-10 10:00:00',
            'endsAt' => '2026-10-10 11:00:00',
            'isBlocking' => true,
            'isCancelled' => false,
        ];

        $daySegments = [
            [$regularOverlap],
            [],
            [],
            [],
            [],
            [],
            [],
        ];

        $gridBuilder = $this->createMock(
            \App\Service\ProgrammationGridBuilder::class
        );

        $gridBuilder
            ->method('buildForWeek')
            ->willReturn($daySegments);

        static::getContainer()->set(
            \App\Service\ProgrammationGridBuilder::class,
            $gridBuilder
        );

        $projectionService = $this->createMock(
            \App\Service\GridOccurrenceProjectionService::class
        );

        $projectionService
            ->method('applyForWeek')
            ->willReturn($daySegments);

        static::getContainer()->set(
            \App\Service\GridOccurrenceProjectionService::class,
            $projectionService
        );

        $conflictDetector = $this->createMock(
            \App\Service\GridConflictDetector::class
        );

        $conflictDetector
            ->method('findBlockingOverlapsForRange')
            ->willReturn([$regularOverlap]);

        static::getContainer()->set(
            \App\Service\GridConflictDetector::class,
            $conflictDetector
        );
    }

    public function testDuplicatePendingRebroadcastCreatesIndependentPendingRebroadcast(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_123';

        $original = new PendingRebroadcast();

        $original
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($original);
        $this->entityManager->flush();

        $originalId = $original->getId();
        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $originalId . '/duplicate'
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('pendingRebroadcastId', $data);

        $duplicateId = (int) $data['pendingRebroadcastId'];

        $this->assertNotSame(
            $originalId,
            $duplicateId
        );

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $originalAfterRequest = $repository->find($originalId);
        $duplicate = $repository->find($duplicateId);

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $originalAfterRequest
        );

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $duplicate
        );

        $this->assertSame(
            $emissionId,
            $duplicate->getEmission()?->getId()
        );

        $this->assertSame(
            $groupKey,
            $duplicate->getAssignmentGroupKey()
        );

        $groupPendingRebroadcasts = $repository
            ->findByAssignmentGroupKey($groupKey);

        $this->assertCount(
            2,
            $groupPendingRebroadcasts
        );
    }


    /** @dataProvider regularDuplicationOptions */
    public function testDuplicateRegularPendingNeverCreatesSecondProposal(bool $optionEnabled): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $editor = (new Editeur())->setName('Pending authorization editor')->setUpdateAt(new \DateTime());
        $category = (new Categories())->setTitre('Pending authorization ' . uniqid())->setSlug('PEN')
            ->setEditeur($editor)->setDuree(60)->setDescriptif('Pending authorization fixture')
            ->setActive(true)->setSoftDelete(false)->setUpdatedAt(new \DateTime());
        $rule = (new \App\Entity\ProgrammationRule())->setCategory($category)->setRuleNumber(1)->setHasAdditionalRebroadcast(true);
        foreach ([$editor, $category, $rule] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $emission = $this->createEmission($category);
        $key = sprintf('rule_%d_origin_20990106_2000', $rule->getId());
        $pending = (new PendingRebroadcast())->setEmission($emission)->setAssignmentGroupKey($key);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();
        $pendingId = $pending->getId();
        $rule->setHasAdditionalRebroadcast($optionEnabled);
        $this->entityManager->flush();
        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/pending-rebroadcasts/' . $pendingId . '/duplicate');
        $this->entityManager->clear();
        $repository = $this->entityManager->getRepository(PendingRebroadcast::class);
        self::assertNotNull($repository->find($pendingId));
        self::assertCount(1, $repository->findByAssignmentGroupKey($key), 'Duplicating a regular pending must not create a second proposal, regardless of current eligibility.');
    }

    public static function regularDuplicationOptions(): array
    {
        return ['option_disabled_after_creation' => [false], 'option_enabled_single_extra' => [true]];
    }

    public function testDuplicatePendingRebroadcastReturnsNotFoundWhenPendingDoesNotExist(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/999999999/duplicate'
        );

        $this->assertResponseStatusCodeSame(404);

        $this->entityManager->clear();

        $pendingRebroadcasts = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findAll();

        $this->assertCount(0, $pendingRebroadcasts);
    }

    public function testDeletePendingRebroadcastReturnsNotFoundWhenPendingDoesNotExist(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $pendingToKeep = new PendingRebroadcast();

        $pendingToKeep
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pendingToKeep);
        $this->entityManager->flush();

        $pendingToKeepId = $pendingToKeep->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'DELETE',
            '/admin/pending-rebroadcasts/999999999'
        );

        $this->assertResponseStatusCodeSame(404);

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingToKeepId)
        );

        $remainingPending = $pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        $this->assertCount(1, $remainingPending);
        $this->assertSame(
            $pendingToKeepId,
            $remainingPending[0]->getId()
        );
    }

    public function testPlacePendingRebroadcastCreatesManualRebroadcastAndRemovesOnlyPlacedPending(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            2,
            $groupKey
        );

        $pendingToPlace = new PendingRebroadcast();
        $pendingToPlace
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $pendingToKeep = new PendingRebroadcast();
        $pendingToKeep
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pendingToPlace);
        $this->entityManager->persist($pendingToKeep);
        $this->entityManager->flush();

        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();
        $pendingToPlaceId = $pendingToPlace->getId();
        $pendingToKeepId = $pendingToKeep->getId();
        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingToPlaceId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-10 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);

        $createdDraftId = (int) $data['draftId'];

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * Seule l'occurrence placée quitte le parc.
     */
        $this->assertNull(
            $pendingRepository->find($pendingToPlaceId)
        );

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingToKeepId)
        );

        /*
     * Les deux diffusions régulières restent intactes.
     */
        $firstRegularAfterPlacement = $draftRepository->find($firstRegularId);
        $secondRegularAfterPlacement = $draftRepository->find($secondRegularId);

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $firstRegularAfterPlacement
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $secondRegularAfterPlacement
        );

        $this->assertSame(
            1,
            $firstRegularAfterPlacement->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $secondRegularAfterPlacement->getNombreDiffusion()
        );

        /*
     * Le pending devient une vraie rediffusion ponctuelle programmée.
     */
        $createdDraft = $draftRepository->find($createdDraftId);

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $createdDraft
        );

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $createdDraft->getDraftType()
        );

        $this->assertSame(
            $emissionId,
            $createdDraft->getEmission()?->getId()
        );

        $this->assertSame(
            $groupKey,
            $createdDraft->getAssignmentGroupKey()
        );

        $this->assertSame(
            3,
            $createdDraft->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-10 10:00:00',
            $createdDraft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        /*
     * Le groupe contient toujours les deux régulières
     * et maintenant la ponctuelle placée.
     */
        $groupDrafts = $draftRepository->findByAssignmentGroupKey(
            $groupKey
        );

        $this->assertCount(3, $groupDrafts);

        /*
     * Le doublon pending reste disponible dans le parc.
     */
        $remainingPending = $pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        $this->assertCount(1, $remainingPending);

        $this->assertSame(
            $pendingToKeepId,
            $remainingPending[0]->getId()
        );
    }

    public function testPlacePendingRebroadcastRefusesDateBeforeLastRegularEndsAt(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            2,
            $groupKey
        );

        $pending = new PendingRebroadcast();

        $pending
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();
        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();

        $this->client->loginUser($admin);

        /*
     * La seconde régulière commence à 10:00 et dure 60 minutes.
     * Une ponctuelle à 10:30 est donc antérieure à la fin
     * de toutes les régulières du groupe (11:00).
     */
        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-08 10:30:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($data['success']);

        $this->assertSame(
            'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.',
            $data['error']
        );

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * L'échec du placement ne consomme pas le pending.
     */
        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingId)
        );

        /*
     * Les régulières restent intactes.
     */
        $firstRegularAfterFailure = $draftRepository->find($firstRegularId);
        $secondRegularAfterFailure = $draftRepository->find($secondRegularId);

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $firstRegularAfterFailure
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $secondRegularAfterFailure
        );

        $this->assertSame(
            1,
            $firstRegularAfterFailure->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $secondRegularAfterFailure->getNombreDiffusion()
        );

        /*
     * Aucun draft ponctuel n'a été créé.
     */
        $groupDrafts = $draftRepository->findByAssignmentGroupKey(
            $groupKey
        );

        $manualRebroadcasts = array_values(array_filter(
            $groupDrafts,
            static fn(DiffusionDraft $draft): bool =>
            DiffusionDraft::TYPE_MANUAL_REBROADCAST === $draft->getDraftType()
        ));

        $this->assertCount(0, $manualRebroadcasts);
    }

    public function testPlacePendingRebroadcastRefusesStartOutsideQuarterHour(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $regular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $regularId = $regular->getId();
        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-07 10:07:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($data['success']);

        $this->assertSame(
            'L’heure doit être alignée sur un quart d’heure.',
            $data['error']
        );

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * Le pending reste disponible puisque le placement a été refusé.
     */
        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingId)
        );

        /*
     * La régulière reste intacte.
     */
        $regularAfterFailure = $draftRepository->find($regularId);

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $regularAfterFailure
        );

        $this->assertSame(
            1,
            $regularAfterFailure->getNombreDiffusion()
        );

        /*
     * Aucun draft ponctuel n'a été créé.
     */
        $groupDrafts = $draftRepository->findByAssignmentGroupKey(
            $groupKey
        );

        $manualRebroadcasts = array_values(array_filter(
            $groupDrafts,
            static fn(DiffusionDraft $draft): bool =>
            DiffusionDraft::TYPE_MANUAL_REBROADCAST === $draft->getDraftType()
        ));

        $this->assertCount(0, $manualRebroadcasts);
    }

    public function testPlacePendingRebroadcastRefusesOverlapWithExistingDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $blockingEmission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $blockingDraft = (new DiffusionDraft())
            ->setEmission($blockingEmission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('manual_blocking_test')
            ->setSchedule(
                new \DateTimeImmutable('2026-10-10 10:30:00'),
                60
            );

        $this->entityManager->persist($pending);
        $this->entityManager->persist($blockingDraft);
        $this->entityManager->flush();

        $pendingId = $pending->getId();
        $blockingDraftId = $blockingDraft->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-10 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($data['success']);

        $this->assertSame(
            'Ce créneau chevauche déjà une programmation existante.',
            $data['error']
        );

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingId)
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $draftRepository->find($blockingDraftId)
        );

        $groupDrafts = $draftRepository
            ->findByAssignmentGroupKey($groupKey);

        $manualRebroadcasts = array_values(array_filter(
            $groupDrafts,
            static fn(DiffusionDraft $draft): bool =>
            DiffusionDraft::TYPE_MANUAL_REBROADCAST === $draft->getDraftType()
        ));

        $this->assertCount(0, $manualRebroadcasts);
    }

    public function testPlacePendingRebroadcastRenumbersExistingManualRebroadcastsChronologically(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            2,
            $groupKey
        );

        $laterRebroadcast = (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setNombreDiffusion(3)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-10-12 10:00:00'),
                60
            );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($laterRebroadcast);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();
        $laterRebroadcastId = $laterRebroadcast->getId();
        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        /*
     * On place la nouvelle ponctuelle avant celle déjà existante.
     * Elle doit donc devenir n°3 et l'ancienne n°4.
     */
        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-10 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);

        $newRebroadcastId = (int) $data['draftId'];

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $firstRegularAfterPlacement = $draftRepository->find($firstRegularId);
        $secondRegularAfterPlacement = $draftRepository->find($secondRegularId);
        $newRebroadcast = $draftRepository->find($newRebroadcastId);
        $laterRebroadcastAfterPlacement = $draftRepository->find(
            $laterRebroadcastId
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $firstRegularAfterPlacement
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $secondRegularAfterPlacement
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $newRebroadcast
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $laterRebroadcastAfterPlacement
        );

        /*
     * Les rangs réguliers ne bougent jamais.
     */
        $this->assertSame(
            1,
            $firstRegularAfterPlacement->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $secondRegularAfterPlacement->getNombreDiffusion()
        );

        /*
     * Les ponctuelles sont classées chronologiquement
     * après le dernier rang régulier.
     */
        $this->assertSame(
            3,
            $newRebroadcast->getNombreDiffusion()
        );

        $this->assertSame(
            4,
            $laterRebroadcastAfterPlacement->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-10 10:00:00',
            $newRebroadcast->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-12 10:00:00',
            $laterRebroadcastAfterPlacement
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertNull(
            $pendingRepository->find($pendingId)
        );
    }

    public function testPlacePendingRebroadcastRefusesOverlapWithRegularProgramming(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $regular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $regularId = $regular->getId();
        $pendingId = $pending->getId();

        $this->mockRegularOverlap();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-10 10:15:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($data['success']);

        $this->assertSame(
            'Ce créneau chevauche déjà une programmation régulière.',
            $data['error']
        );

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * Le pending n'est pas consommé puisque le placement
     * a été refusé.
     */
        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingId)
        );

        /*
     * La diffusion régulière du groupe reste intacte.
     */
        $regularAfterFailure = $draftRepository->find($regularId);

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $regularAfterFailure
        );

        $this->assertSame(
            1,
            $regularAfterFailure->getNombreDiffusion()
        );

        /*
     * Aucune rediffusion ponctuelle n'a été créée.
     */
        $groupDrafts = $draftRepository
            ->findByAssignmentGroupKey($groupKey);

        $manualRebroadcasts = array_values(array_filter(
            $groupDrafts,
            static fn(DiffusionDraft $draft): bool =>
            DiffusionDraft::TYPE_MANUAL_REBROADCAST === $draft->getDraftType()
        ));

        $this->assertCount(0, $manualRebroadcasts);
    }

    public function testPlacePendingRebroadcastRefusesWhenRegularGroupNoLongerExists(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        /*
     * Le pending existe encore dans le parc, mais plus aucun Draft
     * régulier correspondant à son groupe n'existe.
     */
        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-10 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseStatusCodeSame(400);

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($data['success']);

        $this->assertSame(
            'Aucune diffusion régulière trouvée pour ce groupe.',
            $data['error']
        );

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * Le pending n'est pas perdu.
     */
        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingId)
        );

        /*
     * Aucun Draft n'a été créé à la suite du refus.
     */
        $this->assertCount(
            0,
            $draftRepository->findByAssignmentGroupKey($groupKey)
        );
    }

    public function testPlacePendingRebroadcastUsesFifteenMinutesWhenEmissionDurationIsZero(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $emission->setDuree(0);

        $groupKey = 'rule_123_origin_20261006_1000';

        $regular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $regularId = $regular->getId();
        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-10 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);

        $createdDraftId = (int) $data['draftId'];

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $regularAfterPlacement = $draftRepository->find($regularId);
        $createdDraft = $draftRepository->find($createdDraftId);

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $regularAfterPlacement
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $createdDraft
        );

        /*
     * Une émission sans durée exploitable reçoit le minimum
     * d'un créneau de grille : 15 minutes.
     */
        $this->assertSame(
            15,
            $createdDraft->getDurationMinutes()
        );

        $this->assertSame(
            '2026-10-10 10:15:00',
            $createdDraft->getEndsAt()?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $createdDraft->getDraftType()
        );

        $this->assertSame(
            2,
            $createdDraft->getNombreDiffusion()
        );

        /*
     * Le pending placé a bien quitté le parc.
     */
        $this->assertNull(
            $pendingRepository->find($pendingId)
        );
    }

    public function testIndexOnlyIncludesPendingEmissionPassagesWhenRegularGroupIsReassigned(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emissionA = $this->createEmission();
        $emissionB = $this->createEmission();
        $groupKey = 'rule_8_origin_20261022_1400';
        $dates = [1 => '2026-10-22 14:00:00', 2 => '2026-10-25 20:00:00'];
        $historicalIds = [];

        foreach ($dates as $rank => $date) {
            $diffusion = (new Diffusion())
                ->setEmission($emissionA)
                ->setAssignmentGroupKey($groupKey)
                ->setSchedule(new \DateTime($date), 60)
                ->setNombreDiffusion($rank)
                ->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);
            $this->entityManager->persist($diffusion);
            $this->createDraft($emissionB, new \DateTimeImmutable($date), $rank, $groupKey);
            $historicalIds[$rank] = $diffusion->getId();
        }

        $pending = (new PendingRebroadcast())
            ->setEmission($emissionB)
            ->setAssignmentGroupKey($groupKey);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();
        $pendingId = $pending->getId();
        $emissionAId = $emissionA->getId();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/pending-rebroadcasts');
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $items = array_values(array_filter($data['items'], static fn(array $item): bool => $item['id'] === $pendingId));
        $this->assertCount(1, $items);

        // Recharger les anciennes Diffusion avant l'assertion RED sur le JSON.
        $this->entityManager->clear();
        foreach ($historicalIds as $rank => $id) {
            $historical = $this->entityManager->find(Diffusion::class, $id);
            $this->assertInstanceOf(Diffusion::class, $historical);
            $this->assertSame($id, $historical->getId());
            $this->assertSame($emissionAId, $historical->getEmission()->getId());
            $this->assertSame($groupKey, $historical->getAssignmentGroupKey());
            $this->assertSame($dates[$rank], $historical->getHoraireDiffusion()->format('Y-m-d H:i:s'));
            $this->assertSame($rank, $historical->getNombreDiffusion());
            $this->assertSame(Diffusion::STATUS_UNPUBLISHED, $historical->getPublicationStatus());
            $this->assertSame(60, $historical->getDurationMinutes());
        }

        $this->assertSame([
            ['date' => $dates[1], 'number' => 1],
            ['date' => $dates[2], 'number' => 2],
        ], $items[0]['previousDiffusions'], 'Seuls les deux passages de la nouvelle émission doivent apparaître.');
    }

    public function testIndexIncludesCurrentDraftLinkedToAnotherEmissionUnpublishedDiffusion(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emissionA = $this->createEmission();
        $emissionB = $this->createEmission();
        $groupKey = 'rule_8_origin_20261022_1400';
        $historical = (new Diffusion())
            ->setEmission($emissionA)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(new \DateTime('2026-10-22 14:00:00'), 60)
            ->setNombreDiffusion(1)
            ->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);
        $this->entityManager->persist($historical);
        $draft = $this->createDraft($emissionB, new \DateTimeImmutable('2026-10-25 20:00:00'), 2, $groupKey);
        $draft->setPublishedDiffusion($historical);
        $pending = (new PendingRebroadcast())
            ->setEmission($emissionB)
            ->setAssignmentGroupKey($groupKey);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();
        $pendingId = $pending->getId();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/pending-rebroadcasts');
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $items = array_values(array_filter($data['items'], static fn(array $item): bool => $item['id'] === $pendingId));
        $this->assertCount(1, $items);
        $this->assertSame([
            ['date' => '2026-10-25 20:00:00', 'number' => 2],
        ], $items[0]['previousDiffusions'], 'Le passage actuel du draft doit rester visible malgré son lien vers une autre émission.');
    }

    public function testIndexIncludesUnpublishedDiffusionsInGroupHistory(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_123';

        $publishedDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTime('2026-10-06 10:00:00'),
                60
            )
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $unpublishedDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTime('2026-10-08 10:00:00'),
                60
            )
            ->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($publishedDiffusion);
        $this->entityManager->persist($unpublishedDiffusion);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/pending-rebroadcasts'
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);

        $item = null;

        foreach ($data['items'] as $candidate) {
            if ($pendingId === (int) $candidate['id']) {
                $item = $candidate;
                break;
            }
        }

        $this->assertNotNull(
            $item,
            'Le PendingRebroadcast créé pour le test doit être présent dans la réponse.'
        );

        $this->assertSame(
            [
                [
                    'date' => '2026-10-06 10:00:00',
                    'number' => 1,
                ],
                [
                    'date' => '2026-10-08 10:00:00',
                    'number' => 2,
                ],
            ],
            $item['previousDiffusions']
        );
    }

    public function testIndexIncludesFutureDraftsWithoutDuplicatingLinkedDiffusions(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_123';

        /*
     * Première diffusion : elle existe comme Diffusion.
     */
        $publishedDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTime('2026-10-06 10:00:00'),
                60
            )
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $this->entityManager->persist($publishedDiffusion);

        /*
     * Draft correspondant à cette Diffusion.
     *
     * Il appartient toujours au même groupe et conserve son lien vers
     * la Diffusion publiée. Il ne doit donc PAS créer une deuxième ligne
     * dans l'historique du groupe.
     */
        $linkedDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-10-06 10:00:00'),
                60
            )
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
            ->setPublishedDiffusion($publishedDiffusion);

        /*
     * Deuxième passage : il est planifié dans une semaine future
     * mais n'existe encore que sous forme de DiffusionDraft.
     *
     * Il doit apparaître dans le tooltip.
     */
        $futureDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-10-20 10:00:00'),
                60
            )
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT);

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($linkedDraft);
        $this->entityManager->persist($futureDraft);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/pending-rebroadcasts'
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);

        $item = null;

        foreach ($data['items'] as $candidate) {
            if ($pendingId === (int) $candidate['id']) {
                $item = $candidate;
                break;
            }
        }

        $this->assertNotNull(
            $item,
            'Le PendingRebroadcast créé pour le test doit être présent dans la réponse.'
        );

        $this->assertSame(
            [
                [
                    'date' => '2026-10-06 10:00:00',
                    'number' => 1,
                ],
                [
                    'date' => '2026-10-20 10:00:00',
                    'number' => 2,
                ],
            ],
            $item['previousDiffusions']
        );
    }

    public function testIndexUsesRescheduledDraftDateForUnpublishedLinkedDiffusionWithoutDuplicate(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_rescheduled_test';

        // Première diffusion publiée du groupe.
        $firstDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTime('2026-10-06 10:00:00'),
                60
            )
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        // Ancienne programmation dévalidée.
        $unpublishedDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTime('2026-10-08 10:00:00'),
                60
            )
            ->setPublicationStatus(Diffusion::STATUS_UNPUBLISHED);

        // Le draft lié a été déplacé vers une date future.
        $rescheduledDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-10 14:00:00'),
                60
            )
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
            ->setPublishedDiffusion($unpublishedDiffusion);

        // Un autre groupe de la même émission ne doit pas apparaître.
        $otherGroupDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('manual_other_group_test')
            ->setSchedule(
                new \DateTime('2026-11-12 10:00:00'),
                60
            )
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($firstDiffusion);
        $this->entityManager->persist($unpublishedDiffusion);
        $this->entityManager->persist($rescheduledDraft);
        $this->entityManager->persist($otherGroupDiffusion);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/pending-rebroadcasts');

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);

        $item = null;

        foreach ($data['items'] as $candidate) {
            if ($pendingId === (int) $candidate['id']) {
                $item = $candidate;
                break;
            }
        }

        $this->assertNotNull(
            $item,
            'Le PendingRebroadcast du groupe doit être présent.'
        );

        // Une occurrence par passage, à la date réellement programmée.
        $this->assertSame(
            [
                [
                    'date' => '2026-10-06 10:00:00',
                    'number' => 1,
                ],
                [
                    'date' => '2026-11-10 14:00:00',
                    'number' => 2,
                ],
            ],
            $item['previousDiffusions']
        );
    }


    public function testIndexIncludesRecentNonRegularEmissionsWithoutCreatingPending(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        // Création de l'éditeur obligatoire pour la catégorie.
        $editeur = (new Editeur())
            ->setName('Éditeur test ' . uniqid())
            ->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);

        // Catégorie sans règle de programmation : non régulière.
        $category = (new Categories())
            ->setTitre('Catégorie ponctuelle ' . uniqid())
            ->setDuree(60)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setDescriptif('Catégorie de test')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($editeur);

        $this->entityManager->persist($category);

        // Émission récente appartenant à cette catégorie.
        $emission = $this->createEmission($category);
        $emissionId = $emission->getId();

        // Vérification de l'absence de PendingRebroadcast initial.
        $this->assertSame(
            0,
            $this->entityManager
                ->getRepository(PendingRebroadcast::class)
                ->count(['emission' => $emission])
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/pending-rebroadcasts'
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);
        $this->assertIsArray($data['items']);

        // Recherche de notre émission dans les résultats.
        $matchingItems = array_values(array_filter(
            $data['items'],
            static fn(array $item): bool =>
            $item['emissionId'] === $emissionId
        ));

        // L'émission doit apparaître une seule fois.
        $this->assertCount(1, $matchingItems);

        $item = $matchingItems[0];

        // Elle est identifiée comme émission non régulière.
        $this->assertSame(
            'non_regular_emission',
            $item['type']
        );

        $this->assertSame(
            $emissionId,
            $item['emissionId']
        );

        $this->assertSame(
            $emission->getTitre(),
            $item['title']
        );

        $this->assertSame(
            $category->getTitre(),
            $item['category']
        );

        $this->assertSame(
            60,
            $item['durationMinutes']
        );

        // Aucun groupe d'affectation régulier.
        $this->assertNull(
            $item['assignmentGroupKey']
        );

        // La consultation du parc ne doit créer aucun Pending.
        $this->assertSame(
            0,
            $this->entityManager
                ->getRepository(PendingRebroadcast::class)
                ->count(['emission' => $emission])
        );
    }

    public function testRecentNonRegularEmissionLeavesPoolAfterManualSpecialPlacement(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $editeur = (new Editeur())
            ->setName('Éditeur test placement ' . uniqid())
            ->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);

        $category = (new Categories())
            ->setTitre('Catégorie ponctuelle placement ' . uniqid())
            ->setDuree(60)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setDescriptif('Catégorie de test')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($editeur);

        $this->entityManager->persist($category);

        $emission = $this->createEmission($category);
        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        // 1. L'émission est initialement disponible dans le parc.
        $this->client->request('GET', '/admin/pending-rebroadcasts');

        $this->assertResponseIsSuccessful();

        $before = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $matchingBefore = array_values(array_filter(
            $before['items'],
            static fn(array $item): bool =>
            $item['emissionId'] === $emissionId
                && $item['type'] === 'non_regular_emission'
        ));

        $this->assertCount(1, $matchingBefore);

        // 2. Placement de l'émission comme programmation ponctuelle.
        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emissionId,
                'startsAt' => '2026-11-03 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $placement = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($placement['success']);
        $this->assertArrayHasKey('draftId', $placement);

        $draftId = (int) $placement['draftId'];

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $draft = $draftRepository->find($draftId);

        $this->assertInstanceOf(DiffusionDraft::class, $draft);

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $draft->getDraftType()
        );

        $this->assertSame(
            $emissionId,
            $draft->getEmission()?->getId()
        );

        $this->assertSame(
            '2026-11-03 10:00:00',
            $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        // 3. L'émission disparaît du parc après placement.
        $this->client->request('GET', '/admin/pending-rebroadcasts');

        $this->assertResponseIsSuccessful();

        $afterPlacement = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $matchingAfterPlacement = array_values(array_filter(
            $afterPlacement['items'],
            static fn(array $item): bool =>
            $item['emissionId'] === $emissionId
                && $item['type'] === 'non_regular_emission'
        ));

        $this->assertCount(0, $matchingAfterPlacement);

        // 4. Suppression réelle de l'affectation via l'API de la grille.
        $this->client->request(
            'POST',
            '/admin/grid-drafts/delete',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(
                [
                    'draftId' => $draftId,
                    'deleteMode' => 'single',
                ],
                JSON_THROW_ON_ERROR
            )
        );

        $this->assertResponseIsSuccessful();

        $deletion = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($deletion['success']);
        $this->assertSame($draftId, $deletion['draftId']);
        $this->assertSame('single', $deletion['deleteMode']);
        $this->assertSame(1, $deletion['deletedCount']);

        // 5. Le draft a réellement disparu de la base.
        $this->entityManager->clear();

        $this->assertNull(
            $draftRepository->find($draftId)
        );

        // 6. L'émission redevient disponible dans le parc.
        $this->client->request('GET', '/admin/pending-rebroadcasts');

        $this->assertResponseIsSuccessful();

        $afterDeletion = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($afterDeletion['success']);

        $matchingAfterDeletion = array_values(array_filter(
            $afterDeletion['items'],
            static fn(array $item): bool =>
            $item['emissionId'] === $emissionId
                && $item['type'] === 'non_regular_emission'
        ));

        // Retour dans le parc, sans doublon.
        $this->assertCount(1, $matchingAfterDeletion);

        $returnedItem = $matchingAfterDeletion[0];

        $this->assertSame($emissionId, $returnedItem['emissionId']);
        $this->assertSame('non_regular_emission', $returnedItem['type']);
        $this->assertSame(60, $returnedItem['durationMinutes']);
        $this->assertNull($returnedItem['assignmentGroupKey']);

        // 7. Le cycle n'a créé aucune PendingRebroadcast.
        $this->assertSame(
            0,
            $this->entityManager
                ->getRepository(PendingRebroadcast::class)
                ->count([
                    'emission' => $this->entityManager
                        ->getReference(Emission::class, $emissionId),
                ])
        );
    }

    public function testIndexKeepsPublishedDiffusionDateWhenLinkedDraftHasDifferentSchedule(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_published_priority_test';

        // La Diffusion publiée fait autorité.
        $publishedDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTime('2026-11-10 10:00:00'),
                60
            )
            ->setPublicationStatus(Diffusion::STATUS_PUBLISHED);

        // Son draft lié possède volontairement un horaire différent.
        $linkedDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-10 14:00:00'),
                60
            )
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
            ->setPublishedDiffusion($publishedDiffusion);

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($publishedDiffusion);
        $this->entityManager->persist($linkedDraft);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/pending-rebroadcasts');

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);

        $item = null;

        foreach ($data['items'] as $candidate) {
            if ($pendingId === (int) $candidate['id']) {
                $item = $candidate;
                break;
            }
        }

        $this->assertNotNull(
            $item,
            'Le PendingRebroadcast doit être présent dans la réponse.'
        );

        // La date publiée reste prioritaire, sans doublon.
        $this->assertSame(
            [
                [
                    'date' => '2026-11-10 10:00:00',
                    'number' => 1,
                ],
            ],
            $item['previousDiffusions']
        );
    }

    public function testDeletePendingRebroadcastDismissesOnlySelectedOccurrence(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            2,
            $groupKey
        );

        $pendingToDismiss = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $pendingToKeep = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pendingToDismiss);
        $this->entityManager->persist($pendingToKeep);
        $this->entityManager->flush();

        $dismissedId = $pendingToDismiss->getId();
        $keptId = $pendingToKeep->getId();
        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'DELETE',
            '/admin/pending-rebroadcasts/' . $dismissedId
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $dismissed = $pendingRepository->find($dismissedId);
        $kept = $pendingRepository->find($keptId);

        $this->assertInstanceOf(PendingRebroadcast::class, $dismissed);
        $this->assertTrue($dismissed->isDismissed());

        $this->assertInstanceOf(PendingRebroadcast::class, $kept);
        $this->assertTrue($kept->isPending());

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $draftRepository->find($firstRegularId)
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $draftRepository->find($secondRegularId)
        );

        $groupPendings = $pendingRepository
            ->findByAssignmentGroupKey($groupKey);

        $this->assertCount(2, $groupPendings);
    }

    public function testDismissedPendingIsHiddenFromPoolButRemainsInDatabase(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'DELETE',
            '/admin/pending-rebroadcasts/' . $pendingId
        );

        $this->assertResponseIsSuccessful();

        $this->client->request(
            'GET',
            '/admin/pending-rebroadcasts'
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $visibleIds = array_map(
            static fn(array $item): int => (int) $item['id'],
            array_filter(
                $data['items'],
                static fn(array $item): bool => ($item['type'] ?? null) !== 'non_regular_emission'
            )
        );

        $this->assertNotContains($pendingId, $visibleIds);

        $this->entityManager->clear();

        $dismissed = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->find($pendingId);

        $this->assertInstanceOf(PendingRebroadcast::class, $dismissed);
        $this->assertTrue($dismissed->isDismissed());
    }

    public function testPlaceDismissedPendingRebroadcastIsRefusedWithoutCreatingDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        // Une diffusion régulière permettrait normalement
        // le placement de la rediffusion supplémentaire.
        $regularDraft = $this->createDraft(
            $emission,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            1,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $pendingId = $pending->getId();
        $regularDraftId = $regularDraft->getId();

        $this->client->loginUser($admin);

        // L'administrateur exclut volontairement cette occurrence.
        $this->client->request(
            'DELETE',
            '/admin/pending-rebroadcasts/' . $pendingId
        );

        $this->assertResponseIsSuccessful();

        // L'identifiant existe toujours, mais le Pending est exclu.
        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $dismissedPending = $pendingRepository->find($pendingId);

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $dismissedPending
        );

        $this->assertTrue($dismissedPending->isDismissed());

        // Tentative de placement direct par l'API,
        // malgré l'exclusion précédente.
        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'startsAt' => '2026-10-10 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        // Le placement doit être refusé.
        $this->assertResponseStatusCodeSame(400);

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($data['success']);
        $this->assertNotEmpty($data['error']);

        // Vérification de l'état réellement persisté.
        $this->entityManager->clear();

        $pendingAfterRequest = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->find($pendingId);

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingAfterRequest
        );

        // L'exclusion ne doit pas disparaître.
        $this->assertTrue($pendingAfterRequest->isDismissed());

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $groupDrafts = $draftRepository
            ->findByAssignmentGroupKey($groupKey);

        // Seul le Draft régulier initial doit exister.
        $this->assertCount(1, $groupDrafts);

        $this->assertSame(
            $regularDraftId,
            $groupDrafts[0]->getId()
        );

        // Aucune rediffusion manuelle supplémentaire.
        $manualRebroadcasts = array_filter(
            $groupDrafts,
            static fn(DiffusionDraft $draft): bool =>
            DiffusionDraft::TYPE_MANUAL_REBROADCAST
                === $draft->getDraftType()
        );

        $this->assertCount(0, $manualRebroadcasts);
    }

    public function testFirstManualSpecialPlacementCreatesPendingBeforePublication(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $editeur = (new Editeur())
            ->setName('Éditeur test pending initial ' . uniqid())
            ->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);

        $category = (new Categories())
            ->setTitre('Catégorie ponctuelle pending ' . uniqid())
            ->setDuree(60)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setDescriptif('Catégorie de test')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($editeur);

        $this->entityManager->persist($category);

        $emission = $this->createEmission($category);
        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        // Première affectation d'une émission non régulière.
        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emissionId,
                'startsAt' => '2026-11-03 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('draftId', $response);

        $draftId = (int) $response['draftId'];

        $this->entityManager->clear();

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertInstanceOf(DiffusionDraft::class, $draft);

        // Le premier placement reste une diffusion ponctuelle de rang 1.
        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $draft->getDraftType()
        );

        $this->assertSame(1, $draft->getNombreDiffusion());

        $groupKey = 'manual_' . $draftId;

        $this->assertSame(
            $groupKey,
            $draft->getAssignmentGroupKey()
        );

        // Aucun passage par la validation de grille n'a eu lieu.
        $this->assertNull($draft->getPublishedDiffusion());

        // Le premier placement doit créer immédiatement un Pending.
        $pendings = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findByAssignmentGroupKey($groupKey);

        $this->assertCount(
            1,
            $pendings,
            'Le premier placement doit créer un PendingRebroadcast avant publication.'
        );

        $pending = $pendings[0];

        $this->assertSame(
            $emissionId,
            $pending->getEmission()?->getId()
        );

        $this->assertSame(
            $groupKey,
            $pending->getAssignmentGroupKey()
        );

        $this->assertTrue($pending->isPending());
    }

    public function testManualSpecialPlacementCreatesPendingForOlderNonRegularEmissionWithoutDiffusion(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $editeur = (new Editeur())
            ->setName('Éditeur test pending ancien ' . uniqid())
            ->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);

        // Catégorie non régulière, sans règle de programmation.
        $category = (new Categories())
            ->setTitre('Catégorie ponctuelle ancienne ' . uniqid())
            ->setDuree(60)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setDescriptif('Catégorie de test')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($editeur);

        $this->entityManager->persist($category);

        // Émission publiée depuis plus de dix jours.
        $emission = $this->createEmission($category);
        $emission->setDatepub(new \DateTime('-30 days'));
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        // Aucun historique ni draft avant la première affectation.
        $this->assertSame(
            0,
            $this->entityManager
                ->getRepository(Diffusion::class)
                ->count(['emission' => $emission])
        );

        $this->assertSame(
            0,
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->count(['emission' => $emission])
        );

        $this->client->loginUser($admin);

        // Première affectation manuelle de l'émission non régulière.
        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emissionId,
                'startsAt' => '2026-11-03 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('draftId', $response);

        $draftId = (int) $response['draftId'];

        $this->entityManager->clear();

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertInstanceOf(DiffusionDraft::class, $draft);

        // Le placement crée une diffusion ponctuelle de rang 1.
        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $draft->getDraftType()
        );

        $this->assertSame(1, $draft->getNombreDiffusion());

        $groupKey = 'manual_' . $draftId;

        $this->assertSame(
            $groupKey,
            $draft->getAssignmentGroupKey()
        );

        // La grille n'a pas encore été publiée.
        $this->assertNull($draft->getPublishedDiffusion());

        // Une émission ancienne doit également générer son Pending.
        $pendings = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findByAssignmentGroupKey($groupKey);

        $this->assertCount(
            1,
            $pendings,
            'Une émission ponctuelle ancienne doit générer un PendingRebroadcast dès sa première affectation.'
        );

        $pending = $pendings[0];

        $this->assertSame(
            $emissionId,
            $pending->getEmission()?->getId()
        );

        $this->assertSame(
            $groupKey,
            $pending->getAssignmentGroupKey()
        );

        $this->assertTrue($pending->isPending());
    }

    public function testTwoManualSpecialPlacementsCreateIndependentPendingRebroadcasts(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $editeur = (new Editeur())
            ->setName('Éditeur test groupes indépendants ' . uniqid())
            ->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);

        // Catégorie non régulière, sans règle de programmation.
        $category = (new Categories())
            ->setTitre('Catégorie ponctuelle ' . uniqid())
            ->setDuree(60)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setDescriptif('Catégorie de test')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($editeur);

        $this->entityManager->persist($category);

        // Émission ancienne pour ne pas dépendre du filtre des dix jours.
        $emission = $this->createEmission($category);
        $emission->setDatepub(new \DateTime('-30 days'));
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $placements = [
            '2026-11-03 10:00:00',
            '2026-11-10 10:00:00',
        ];

        $draftIds = [];

        foreach ($placements as $startsAt) {
            $this->client->request(
                'POST',
                '/admin/grid-drafts/manual',
                [
                    'emissionId' => $emissionId,
                    'startsAt' => $startsAt,
                    'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                    'durationMinutes' => 60,
                ]
            );

            $this->assertResponseIsSuccessful();

            $response = json_decode(
                (string) $this->client->getResponse()->getContent(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $this->assertTrue($response['success']);
            $this->assertArrayHasKey('draftId', $response);

            $draftIds[] = (int) $response['draftId'];
        }

        // Deux placements distincts doivent créer deux drafts.
        $this->assertCount(2, array_unique($draftIds));

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $groupKeys = [];

        foreach ($draftIds as $draftId) {
            $draft = $draftRepository->find($draftId);

            $this->assertInstanceOf(DiffusionDraft::class, $draft);

            $this->assertSame(
                DiffusionDraft::TYPE_MANUAL_SPECIAL,
                $draft->getDraftType()
            );

            $this->assertSame(1, $draft->getNombreDiffusion());

            $this->assertSame(
                $emissionId,
                $draft->getEmission()?->getId()
            );

            // Chaque placement possède son propre groupe.
            $groupKey = 'manual_' . $draftId;

            $this->assertSame(
                $groupKey,
                $draft->getAssignmentGroupKey()
            );

            $this->assertNull($draft->getPublishedDiffusion());

            $groupKeys[] = $groupKey;

            // Exactement un pending pour ce groupe.
            $pendings = $pendingRepository
                ->findByAssignmentGroupKey($groupKey);

            $this->assertCount(1, $pendings);

            $this->assertSame(
                $emissionId,
                $pendings[0]->getEmission()?->getId()
            );

            $this->assertSame(
                $groupKey,
                $pendings[0]->getAssignmentGroupKey()
            );
        }

        // Les deux groupes doivent être indépendants.
        $this->assertCount(2, array_unique($groupKeys));

        // Aucun passage n'a encore été publié.
        $this->assertSame(
            0,
            $this->entityManager
                ->getRepository(Diffusion::class)
                ->count([
                    'emission' => $this->entityManager
                        ->getReference(Emission::class, $emissionId),
                ])
        );

        // Deux groupes, donc exactement deux pendings.
        $this->assertSame(
            2,
            $pendingRepository->count([
                'emission' => $this->entityManager
                    ->getReference(Emission::class, $emissionId),
            ])
        );
    }

    public function testDeletingFirstManualDiffusionAlsoDeletesRebroadcastsAndPending(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        /*
     * Groupe manuel :
     * - première diffusion : rang 1 ;
     * - deux rediffusions : rangs 2 et 3 ;
     * - un PendingRebroadcast associé.
     */
        $firstDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setNombreDiffusion(1)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-03 10:00:00'),
                60
            );

        $this->entityManager->persist($firstDraft);
        $this->entityManager->flush();

        $groupKey = 'manual_' . $firstDraft->getId();
        $firstDraft->setAssignmentGroupKey($groupKey);

        $secondDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-04 10:00:00'),
                60
            );

        $thirdDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setNombreDiffusion(3)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-05 10:00:00'),
                60
            );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($secondDraft);
        $this->entityManager->persist($thirdDraft);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $secondDraftId = $secondDraft->getId();
        $thirdDraftId = $thirdDraft->getId();
        $pendingId = $pending->getId();

        $this->assertCount(
            3,
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->findBy(['assignmentGroupKey' => $groupKey])
        );

        $this->client->loginUser($admin);

        /*
     * L'utilisateur supprime uniquement le premier post-it.
     * La suppression doit entraîner celle du groupe manuel entier.
     */
        $this->client->request(
            'POST',
            '/admin/grid-drafts/delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'draftId' => $firstDraftId,
                'deleteMode' => 'single',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($response['success']);
        $this->assertSame($firstDraftId, $response['draftId']);
        $this->assertSame('single', $response['deleteMode']);
        $this->assertSame(3, $response['deletedCount']);

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        /*
     * Les trois drafts ont réellement disparu.
     */
        $this->assertNull($draftRepository->find($firstDraftId));
        $this->assertNull($draftRepository->find($secondDraftId));
        $this->assertNull($draftRepository->find($thirdDraftId));

        $this->assertCount(
            0,
            $draftRepository->findByAssignmentGroupKey($groupKey)
        );

        /*
     * Le Pending du groupe a également été supprimé.
     */
        $this->assertNull($pendingRepository->find($pendingId));

        $this->assertCount(
            0,
            $pendingRepository->findByAssignmentGroupKey($groupKey)
        );
    }

    public function testPlaceManualGroupPendingReservesUnpublishedLinkedRebroadcastRank(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $firstStartsAt = new \DateTimeImmutable('2099-07-08 14:00:00');
        $rebroadcastStartsAt = $firstStartsAt->modify('+7 days');
        $newStartsAt = $firstStartsAt->modify('+14 days');

        $firstDraft = $this->createDraft($emission, $firstStartsAt, 1, 'temporary');
        $groupKey = 'manual_' . $firstDraft->getId();
        $firstDraft
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setAssignmentGroupKey($groupKey);
        $rebroadcastDraft = $this->createDraft($emission, $rebroadcastStartsAt, 2, $groupKey);
        $rebroadcastDraft->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST);
        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $rebroadcastDraftId = $rebroadcastDraft->getId();
        $pendingId = $pending->getId();
        $publicationService = static::getContainer()->get(\App\Service\GridPublicationService::class);
        $publicationService->publishWeek($firstStartsAt);
        $publicationService->publishWeek($rebroadcastStartsAt);

        $firstDiffusion = $firstDraft->getPublishedDiffusion();
        $rebroadcastDiffusion = $rebroadcastDraft->getPublishedDiffusion();
        self::assertInstanceOf(Diffusion::class, $firstDiffusion);
        self::assertInstanceOf(Diffusion::class, $rebroadcastDiffusion);
        $firstDiffusionId = $firstDiffusion->getId();
        $rebroadcastDiffusionId = $rebroadcastDiffusion->getId();
        self::assertTrue($firstDiffusion->isPublished());
        self::assertTrue($rebroadcastDiffusion->isPublished());
        self::assertSame(2, $rebroadcastDiffusion->getNombreDiffusion());

        static::getContainer()->get(\App\Service\GridUnpublicationService::class)
            ->unpublishWeek($rebroadcastStartsAt);
        $this->entityManager->clear();

        $draftRepository = $this->entityManager->getRepository(DiffusionDraft::class);
        $rebroadcastBeforePlacement = $draftRepository->find($rebroadcastDraftId);
        self::assertTrue($rebroadcastBeforePlacement->isDraft());
        self::assertSame($rebroadcastDiffusionId, $rebroadcastBeforePlacement->getPublishedDiffusion()?->getId());
        self::assertFalse($rebroadcastBeforePlacement->getPublishedDiffusion()->isPublished());
        self::assertSame(2, $rebroadcastBeforePlacement->getNombreDiffusion());

        $this->client->loginUser($admin);
        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['startsAt' => $newStartsAt->format('Y-m-d H:i:s')], JSON_THROW_ON_ERROR)
        );
        $this->assertResponseIsSuccessful();
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($response['success']);
        $this->entityManager->clear();

        $firstAfter = $draftRepository->find($firstDraftId);
        $rebroadcastAfter = $draftRepository->find($rebroadcastDraftId);
        $newDraft = $draftRepository->find($response['draftId']);
        self::assertSame($firstDiffusionId, $firstAfter->getPublishedDiffusion()?->getId());
        self::assertSame($rebroadcastDiffusionId, $rebroadcastAfter->getPublishedDiffusion()?->getId());
        self::assertTrue($firstAfter->getPublishedDiffusion()->isPublished());
        self::assertFalse($rebroadcastAfter->getPublishedDiffusion()->isPublished());
        self::assertSame(1, $firstAfter->getPublishedDiffusion()->getNombreDiffusion());
        self::assertSame(2, $rebroadcastAfter->getPublishedDiffusion()->getNombreDiffusion());
        self::assertTrue($rebroadcastAfter->isDraft());
        self::assertSame(DiffusionDraft::TYPE_MANUAL_REBROADCAST, $newDraft->getDraftType());
        self::assertNull($newDraft->getPublishedDiffusion());
        foreach ([$firstAfter, $rebroadcastAfter, $newDraft] as $draft) {
            self::assertSame($groupKey, $draft->getAssignmentGroupKey());
        }
        self::assertCount(2, $this->entityManager->getRepository(Diffusion::class)->findBy(['assignmentGroupKey' => $groupKey]));
        self::assertNull($this->entityManager->getRepository(PendingRebroadcast::class)->find($pendingId));

        $groupDrafts = $draftRepository->findByAssignmentGroupKey($groupKey);
        self::assertSame(
            [1, 2, 3],
            array_map(static fn(DiffusionDraft $draft): ?int => $draft->getNombreDiffusion(), $groupDrafts)
        );
    }

    public function testPlaceManualGroupPendingStartsRebroadcastAtRankTwo(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $firstDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setNombreDiffusion(1)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-03 10:00:00'),
                60
            );

        $this->entityManager->persist($firstDraft);
        $this->entityManager->flush();

        $groupKey = 'manual_' . $firstDraft->getId();
        $firstDraft->setAssignmentGroupKey($groupKey);

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $pendingId = $pending->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingId . '/place',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'startsAt' => '2026-11-04 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('draftId', $response);

        $rebroadcastId = (int) $response['draftId'];

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $firstAfter = $draftRepository->find($firstDraftId);
        $rebroadcast = $draftRepository->find($rebroadcastId);

        $this->assertInstanceOf(DiffusionDraft::class, $firstAfter);
        $this->assertInstanceOf(DiffusionDraft::class, $rebroadcast);

        $this->assertSame(1, $firstAfter->getNombreDiffusion());
        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $firstAfter->getDraftType()
        );

        $this->assertSame(2, $rebroadcast->getNombreDiffusion());
        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $rebroadcast->getDraftType()
        );
        $this->assertSame(
            $groupKey,
            $rebroadcast->getAssignmentGroupKey()
        );

        $this->assertCount(
            2,
            $draftRepository->findByAssignmentGroupKey($groupKey)
        );

        $this->assertNull($pendingRepository->find($pendingId));
    }

    public function testPlaceManualGroupPendingRenumbersRebroadcastsChronologically(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $firstDraft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setNombreDiffusion(1)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-03 10:00:00'),
                60
            );

        $this->entityManager->persist($firstDraft);
        $this->entityManager->flush();

        $groupKey = 'manual_' . $firstDraft->getId();
        $firstDraft->setAssignmentGroupKey($groupKey);

        $laterRebroadcast = (new DiffusionDraft())
            ->setEmission($emission)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                new \DateTimeImmutable('2026-11-06 10:00:00'),
                60
            );

        $pendingToPlace = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $pendingToKeep = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($laterRebroadcast);
        $this->entityManager->persist($pendingToPlace);
        $this->entityManager->persist($pendingToKeep);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $laterRebroadcastId = $laterRebroadcast->getId();
        $pendingToPlaceId = $pendingToPlace->getId();
        $pendingToKeepId = $pendingToKeep->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/pending-rebroadcasts/' . $pendingToPlaceId . '/place',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'startsAt' => '2026-11-04 10:00:00',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('draftId', $response);

        $newRebroadcastId = (int) $response['draftId'];

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $firstAfter = $draftRepository->find($firstDraftId);
        $newRebroadcast = $draftRepository->find($newRebroadcastId);
        $laterAfter = $draftRepository->find($laterRebroadcastId);

        $this->assertInstanceOf(DiffusionDraft::class, $firstAfter);
        $this->assertInstanceOf(DiffusionDraft::class, $newRebroadcast);
        $this->assertInstanceOf(DiffusionDraft::class, $laterAfter);

        // La première diffusion conserve son rang.
        $this->assertSame(1, $firstAfter->getNombreDiffusion());

        // La nouvelle rediffusion, plus ancienne, devient n°2.
        $this->assertSame(2, $newRebroadcast->getNombreDiffusion());
        $this->assertSame(
            '2026-11-04 10:00:00',
            $newRebroadcast->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        // L'ancienne rediffusion passe au rang 3.
        $this->assertSame(3, $laterAfter->getNombreDiffusion());
        $this->assertSame(
            '2026-11-06 10:00:00',
            $laterAfter->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        $this->assertCount(
            3,
            $draftRepository->findByAssignmentGroupKey($groupKey)
        );

        // Seul le Pending placé est consommé.
        $this->assertNull(
            $pendingRepository->find($pendingToPlaceId)
        );

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($pendingToKeepId)
        );
    }

    public function testIndexIncludesActiveRegularAndManualPendingRebroadcastsButExcludesDismissed(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $manualGroupKey = 'manual_12345';
        $regularGroupKey = 'rule_123_origin_20261006_1000';

        $manualPending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($manualGroupKey);

        $regularPending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($regularGroupKey);

        $dismissedRegular = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('rule_124_origin_20261006_1000')
            ->dismiss();
        $dismissedManual = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey('manual_12346')
            ->dismiss();

        $this->entityManager->persist($manualPending);
        $this->entityManager->persist($regularPending);
        $this->entityManager->persist($dismissedRegular);
        $this->entityManager->persist($dismissedManual);
        $this->entityManager->flush();

        $manualPendingId = $manualPending->getId();
        $regularPendingId = $regularPending->getId();
        $dismissedRegularId = $dismissedRegular->getId();
        $dismissedManualId = $dismissedManual->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/pending-rebroadcasts'
        );

        $this->assertResponseIsSuccessful();

        $data = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($data['success']);

        $pendingItems = array_values(array_filter(
            $data['items'],
            static fn(array $item): bool =>
            'pending_rebroadcast' === $item['type']
        ));

        $visiblePendingIds = array_map(
            static fn(array $item): int => (int) $item['id'],
            $pendingItems
        );

        // Le Pending manuel reste visible.
        $this->assertContains(
            $manualPendingId,
            $visiblePendingIds
        );

        // Les exclusions volontaires restent masquées, quelle que soit l'origine.
        $this->assertNotContains(
            $dismissedRegularId,
            $visiblePendingIds
        );
        $this->assertNotContains($dismissedManualId, $visiblePendingIds);

        // Le filtrage ne supprime aucune donnée.
        $this->entityManager->clear();

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($manualPendingId)
        );

        $this->assertInstanceOf(
            PendingRebroadcast::class,
            $pendingRepository->find($regularPendingId)
        );
        $this->assertTrue($pendingRepository->find($dismissedRegularId)->isDismissed());
        $this->assertTrue($pendingRepository->find($dismissedManualId)->isDismissed());

        // Nouvelle règle métier : les Pending réguliers actifs sont aussi visibles.
        $this->assertContains(
            $regularPendingId,
            $visiblePendingIds,
            'Le contrôleur doit retourner le Pending régulier actif.'
        );
    }
}

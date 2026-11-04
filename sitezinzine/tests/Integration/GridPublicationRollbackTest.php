<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Service\GridPublicationService;
use App\Service\PendingRebroadcastPopulatorInterface;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GridPublicationRollbackTest extends KernelTestCase
{
    use DoctrineTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDoctrine();
    }

    protected function tearDown(): void
    {
        try {
            $this->closeEntityManager();
        } finally {
            parent::tearDown();
        }
    }

    public function testPublicationFailureInPendingPoolRollsBackDiffusionAndDraft(): void
    {
        $startsAt = new \DateTimeImmutable('2099-11-12 14:00:00');
        $weekDate = new \DateTimeImmutable('2099-11-12 00:00:00');

        $groupKey = 'phpunit_rollback_' . bin2hex(random_bytes(6));

        $emission = (new Emission())
            ->setTitre('Émission PHPUnit - rollback publication')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-11-12 10:00:00'))
            ->setRef('PHPUNIT-ROLLBACK-' . bin2hex(random_bytes(4)))
            ->setDuree(60)
            ->setDescriptif('Test de rollback transactionnel.');

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setSchedule($startsAt, 60)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        $emissionId = $emission->getId();
        $draftId = $draft->getId();

        self::assertNotNull($emissionId);
        self::assertNotNull($draftId);

        // L'interface est remplacée uniquement pour ce test.
        $populator = $this->createMock(
            PendingRebroadcastPopulatorInterface::class
        );

        $populator
            ->expects(self::once())
            ->method('populateFromPublishedDiffusions')
            ->willThrowException(
                new \RuntimeException('Échec simulé du parc')
            );

        static::getContainer()->set(
            PendingRebroadcastPopulatorInterface::class,
            $populator
        );

        $publicationService = static::getContainer()
            ->get(GridPublicationService::class);

        try {
            $publicationService->publishWeek($weekDate);

            self::fail(
                'La publication aurait dû échouer pendant le peuplement du parc.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Échec simulé du parc',
                $exception->getMessage()
            );
        }

        /*
         * Après un rollback déclenché par wrapInTransaction(),
         * l'EntityManager peut être fermé.
         *
         * On utilise donc directement la connexion DBAL pour
         * vérifier les données persistées.
         */
        $connection = $this->entityManager->getConnection();

        $diffusionCount = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM diffusion
             WHERE emission_id = :emissionId
             AND assignment_group_key = :groupKey',
            [
                'emissionId' => $emissionId,
                'groupKey' => $groupKey,
            ]
        );

        self::assertSame(
            0,
            $diffusionCount,
            'Aucune Diffusion ne doit subsister après le rollback.'
        );

        $draftStatus = $connection->fetchOne(
            'SELECT publication_status FROM diffusion_draft
             WHERE id = :draftId',
            [
                'draftId' => $draftId,
            ]
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draftStatus,
            'Le Draft doit rester à son état initial.'
        );
    }
}

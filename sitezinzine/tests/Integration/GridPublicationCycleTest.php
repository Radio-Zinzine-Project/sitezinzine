<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Entity\PendingRebroadcast;
use App\Repository\PendingRebroadcastRepository;
use App\Service\GridPublicationService;
use App\Service\GridUnpublicationService;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GridPublicationCycleTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private GridPublicationService $publicationService;
    private GridUnpublicationService $unpublicationService;
    private PendingRebroadcastRepository $pendingRebroadcastRepository;

    protected function setUp(): void
    {
        $this->bootDoctrine();

        $container = static::getContainer();

        $this->publicationService = $container
            ->get(GridPublicationService::class);

        $this->unpublicationService = $container
            ->get(GridUnpublicationService::class);
        $this->pendingRebroadcastRepository = $container
            ->get(PendingRebroadcastRepository::class);
    }

    protected function tearDown(): void
    {
        $this->closeEntityManager();

        parent::tearDown();
    }

    public function testCompletePublicationUnpublicationModificationAndRepublicationCycle(): void
    {
        /*
         * Même semaine radio :
         *
         * mercredi 08/07/2099
         * →
         * vendredi 10/07/2099
         *
         * La semaine radio va du mardi 07/07
         * au mardi 14/07 exclus.
         */
        $initialStartsAt = new \DateTimeImmutable(
            '2099-07-08 14:00:00'
        );

        $modifiedStartsAt = new \DateTimeImmutable(
            '2099-07-10 18:30:00'
        );

        /*
         * Émission.
         */
        $emission = (new Emission())
            ->setTitre('Émission PHPUnit - cycle complet')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-07-08 10:00:00')
            )
            ->setRef('PHPUNIT-CYCLE')
            ->setDuree(60)
            ->setDescriptif(
                'Émission utilisée pour tester le cycle complet de validation.'
            );

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        self::assertNotNull($emission->getId());

        /*
         * État initial :
         *
         * un Draft existe,
         * aucune Diffusion n'existe encore.
         */
        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setDraftType(
                DiffusionDraft::TYPE_MANUAL_SPECIAL
            )
            ->setSchedule(
                $initialStartsAt,
                60
            )
            ->setAssignmentGroupKey(
                'phpunit_cycle_initial'
            );

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        self::assertNotNull($draft->getId());
        self::assertTrue($draft->isDraft());
        self::assertNull(
            $draft->getPublishedDiffusion()
        );

        $draftId = $draft->getId();

        /*
         * ==========================================================
         * ÉTAPE 1
         * Première validation.
         * ==========================================================
         */
        $firstPublication = $this->publicationService
            ->publishWeek(
                new \DateTimeImmutable(
                    '2099-07-08 00:00:00'
                )
            );

        self::assertTrue(
            $firstPublication['published']
        );

        self::assertSame(
            1,
            $firstPublication['createdCount']
        );

        self::assertSame(
            0,
            $firstPublication['updatedCount']
        );

        self::assertSame(
            1,
            $firstPublication['publishedDraftCount']
        );

        $this->clearEntityManager();

        /** @var DiffusionDraft|null $draftAfterPublication */
        $draftAfterPublication = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        self::assertNotNull(
            $draftAfterPublication
        );

        self::assertTrue(
            $draftAfterPublication->isPublished()
        );

        self::assertNotNull(
            $draftAfterPublication->getPublishedAt()
        );

        self::assertNotNull(
            $draftAfterPublication->getPublishedDiffusion()
        );

        $firstDiffusion = $draftAfterPublication
            ->getPublishedDiffusion();

        self::assertInstanceOf(
            Diffusion::class,
            $firstDiffusion
        );

        self::assertNotNull(
            $firstDiffusion->getId()
        );

        $diffusionId = $firstDiffusion->getId();

        self::assertTrue(
            $firstDiffusion->isPublished()
        );

        self::assertSame(
            '2099-07-08 14:00:00',
            $firstDiffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-07-08 15:00:00',
            $firstDiffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            60,
            $firstDiffusion->getDurationMinutes()
        );

        /*
         * ==========================================================
         * ÉTAPE 2
         * Dévalidation.
         * ==========================================================
         */
        $unpublication = $this->unpublicationService
            ->unpublishWeek(
                new \DateTimeImmutable(
                    '2099-07-08 00:00:00'
                )
            );

        self::assertSame(
            1,
            $unpublication['unpublishedDiffusionCount']
        );

        self::assertSame(
            1,
            $unpublication['restoredDraftCount']
        );

        $this->clearEntityManager();

        /** @var DiffusionDraft|null $draftAfterUnpublication */
        $draftAfterUnpublication = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        /** @var Diffusion|null $diffusionAfterUnpublication */
        $diffusionAfterUnpublication = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        self::assertNotNull(
            $draftAfterUnpublication
        );

        self::assertNotNull(
            $diffusionAfterUnpublication
        );

        self::assertTrue(
            $draftAfterUnpublication->isDraft()
        );

        self::assertNull(
            $draftAfterUnpublication->getPublishedAt()
        );

        /*
         * Le lien vers l'ancienne Diffusion doit être conservé.
         */
        self::assertNotNull(
            $draftAfterUnpublication->getPublishedDiffusion()
        );

        self::assertSame(
            $diffusionId,
            $draftAfterUnpublication
                ->getPublishedDiffusion()
                ?->getId()
        );

        self::assertTrue(
            $diffusionAfterUnpublication->isUnpublished()
        );

        /*
         * ==========================================================
         * ÉTAPE 3
         * Modification du Draft pendant que la semaine
         * est dévalidée.
         *
         * On modifie :
         * - l'horaire ;
         * - la durée ;
         * - nombreDiffusion ;
         * - assignmentGroupKey.
         * ==========================================================
         */
        $draftAfterUnpublication
            ->setSchedule(
                $modifiedStartsAt,
                90
            )
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey(
                'phpunit_cycle_modified'
            );

        $this->entityManager->flush();

        self::assertTrue(
            $draftAfterUnpublication->isDraft()
        );

        self::assertSame(
            '2099-07-10 18:30:00',
            $draftAfterUnpublication
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-07-10 20:00:00',
            $draftAfterUnpublication
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        /*
         * Le lien historique est toujours conservé
         * malgré la modification du Draft.
         */
        self::assertSame(
            $diffusionId,
            $draftAfterUnpublication
                ->getPublishedDiffusion()
                ?->getId()
        );

        /*
         * ==========================================================
         * ÉTAPE 4
         * Revalidation.
         * ==========================================================
         */
        $secondPublication = $this->publicationService
            ->publishWeek(
                new \DateTimeImmutable(
                    '2099-07-08 00:00:00'
                )
            );

        self::assertTrue(
            $secondPublication['published']
        );

        /*
         * C'est le point principal :
         *
         * aucune nouvelle Diffusion ne doit être créée.
         */
        self::assertSame(
            0,
            $secondPublication['createdCount']
        );

        self::assertSame(
            1,
            $secondPublication['updatedCount']
        );

        self::assertSame(
            1,
            $secondPublication['publishedDraftCount']
        );

        /*
         * ==========================================================
         * ÉTAPE 5
         * Vérification finale depuis la BDD.
         * ==========================================================
         */
        $this->clearEntityManager();

        /** @var DiffusionDraft|null $finalDraft */
        $finalDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        /** @var Diffusion|null $finalDiffusion */
        $finalDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        self::assertNotNull(
            $finalDraft
        );

        self::assertNotNull(
            $finalDiffusion
        );

        self::assertTrue(
            $finalDraft->isPublished()
        );

        self::assertNotNull(
            $finalDraft->getPublishedAt()
        );

        self::assertNotNull(
            $finalDraft->getPublishedDiffusion()
        );

        /*
         * Toujours exactement la même Diffusion.
         */
        self::assertSame(
            $diffusionId,
            $finalDraft
                ->getPublishedDiffusion()
                ?->getId()
        );

        self::assertSame(
            $diffusionId,
            $finalDiffusion->getId()
        );

        self::assertTrue(
            $finalDiffusion->isPublished()
        );

        /*
         * Les anciennes valeurs ont été remplacées
         * par celles du Draft modifié.
         */
        self::assertSame(
            '2099-07-10 18:30:00',
            $finalDiffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-07-10 20:00:00',
            $finalDiffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            90,
            $finalDiffusion->getDurationMinutes()
        );

        self::assertSame(
            2,
            $finalDiffusion->getNombreDiffusion()
        );

        self::assertSame(
            'phpunit_cycle_modified',
            $finalDiffusion->getAssignmentGroupKey()
        );

        self::assertSame(
            $emission->getId(),
            $finalDiffusion
                ->getEmission()
                ?->getId()
        );
    }

    public function testRepublicationDoesNotDuplicatePendingRebroadcast(): void
    {
        /*
     * On utilise volontairement une semaine passée.
     *
     * Contrairement au test historique en 2099, cette Diffusion se trouve
     * avant la borne temporelle du parc et doit donc être prise en compte.
     */
        $startsAt = new \DateTimeImmutable(
            '2026-09-16 14:00:00'
        );

        $weekDate = new \DateTimeImmutable(
            '2026-09-16 00:00:00'
        );

        $assignmentGroupKey = 'phpunit_pending_cycle';

        $emission = (new Emission())
            ->setTitre('Émission PHPUnit - parc à rediff')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2026-09-16 10:00:00')
            )
            ->setRef('PHPUNIT-PENDING-CYCLE')
            ->setDuree(60)
            ->setDescriptif(
                'Émission utilisée pour tester le parc pendant une republication.'
            );

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        self::assertNotNull($emission->getId());

        $emissionId = $emission->getId();

        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setDraftType(
                DiffusionDraft::TYPE_MANUAL_SPECIAL
            )
            ->setSchedule(
                $startsAt,
                60
            )
            ->setAssignmentGroupKey(
                $assignmentGroupKey
            );

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        self::assertNotNull($draft->getId());

        $draftId = $draft->getId();

        /*
     * ==========================================================
     * ÉTAPE 1
     * Première publication.
     * ==========================================================
     */
        $firstPublication = $this->publicationService
            ->publishWeek($weekDate);

        self::assertTrue(
            $firstPublication['published']
        );

        self::assertSame(
            1,
            $firstPublication['createdCount']
        );

        $this->clearEntityManager();

        $pendingAfterFirstPublication =
            $this->pendingRebroadcastRepository
            ->findByAssignmentGroupKey(
                $assignmentGroupKey
            );

        self::assertCount(
            1,
            $pendingAfterFirstPublication
        );

        $firstPending = $pendingAfterFirstPublication[0];

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $firstPending
        );

        self::assertSame(
            $emissionId,
            $firstPending->getEmission()?->getId()
        );

        self::assertSame(
            $assignmentGroupKey,
            $firstPending->getAssignmentGroupKey()
        );

        self::assertNotNull(
            $firstPending->getId()
        );

        $pendingId = $firstPending->getId();

        /*
     * ==========================================================
     * ÉTAPE 2
     * Dévalidation.
     *
     * La Pending appartient au parc et ne doit pas disparaître
     * lorsque la semaine retourne en brouillon.
     * ==========================================================
     */
        $unpublication = $this->unpublicationService
            ->unpublishWeek($weekDate);

        self::assertSame(
            1,
            $unpublication['unpublishedDiffusionCount']
        );

        self::assertSame(
            1,
            $unpublication['restoredDraftCount']
        );

        $this->clearEntityManager();

        $pendingAfterUnpublication =
            $this->pendingRebroadcastRepository
            ->findByAssignmentGroupKey(
                $assignmentGroupKey
            );

        self::assertCount(
            1,
            $pendingAfterUnpublication
        );

        self::assertSame(
            $pendingId,
            $pendingAfterUnpublication[0]->getId()
        );

        /*
     * Le Draft doit bien être revenu à l'état brouillon
     * avant de tester la republication.
     */
        $draftAfterUnpublication = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        self::assertNotNull(
            $draftAfterUnpublication
        );

        self::assertTrue(
            $draftAfterUnpublication->isDraft()
        );

        /*
     * ==========================================================
     * ÉTAPE 3
     * Republication de la même semaine.
     *
     * L'émission possède déjà une Pending dans le parc :
     * aucune seconde Pending ne doit être créée.
     * ==========================================================
     */
        $secondPublication = $this->publicationService
            ->publishWeek($weekDate);

        self::assertTrue(
            $secondPublication['published']
        );

        self::assertSame(
            0,
            $secondPublication['createdCount']
        );

        self::assertSame(
            1,
            $secondPublication['updatedCount']
        );

        $this->clearEntityManager();

        $pendingAfterRepublication =
            $this->pendingRebroadcastRepository
            ->findByAssignmentGroupKey(
                $assignmentGroupKey
            );

        self::assertCount(
            1,
            $pendingAfterRepublication
        );

        /*
     * Ce n'est pas simplement "toujours une Pending" :
     * c'est exactement la même ligne qui doit être restée dans le parc.
     */
        self::assertSame(
            $pendingId,
            $pendingAfterRepublication[0]->getId()
        );

        self::assertSame(
            $emissionId,
            $pendingAfterRepublication[0]
                ->getEmission()
                ?->getId()
        );

        self::assertSame(
            $assignmentGroupKey,
            $pendingAfterRepublication[0]
                ->getAssignmentGroupKey()
        );
    }
}

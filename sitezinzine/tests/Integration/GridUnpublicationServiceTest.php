<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Service\GridUnpublicationService;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GridUnpublicationServiceTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private GridUnpublicationService $unpublicationService;

    protected function setUp(): void
    {
        $this->bootDoctrine();

        $this->unpublicationService = static::getContainer()
            ->get(GridUnpublicationService::class);
    }

    protected function tearDown(): void
    {
        $this->closeEntityManager();

        parent::tearDown();
    }

    public function testUnpublishWeekRestoresDraftAndKeepsDiffusionLink(): void
    {
        $startsAt = new \DateTimeImmutable(
            '2099-06-11 14:00:00'
        );

        /*
         * Émission utilisée par la Diffusion et son Draft.
         */
        $emission = (new Emission())
            ->setTitre('Émission PHPUnit - dévalidation')
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-06-11 10:00:00')
            )
            ->setRef('PHPUNIT-UNPUBLISH')
            ->setDuree(60)
            ->setDescriptif(
                'Émission créée pour tester la dévalidation.'
            );

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        self::assertNotNull($emission->getId());

        /*
         * Diffusion actuellement publiée.
         */
        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey(
                'phpunit_unpublication_group'
            )
            ->setSchedule(
                new \DateTime('2099-06-11 14:00:00'),
                60
            )
            ->markAsPublished();

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        self::assertNotNull($diffusion->getId());
        self::assertTrue($diffusion->isPublished());

        /*
         * Draft correspondant à cette Diffusion.
         *
         * On le place dans l'état obtenu après une validation :
         * - statut published
         * - publishedAt renseigné
         * - publishedDiffusion liée
         */
        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setDraftType(
                DiffusionDraft::TYPE_MANUAL_SPECIAL
            )
            ->setSchedule($startsAt, 60)
            ->setAssignmentGroupKey(
                'phpunit_unpublication_group'
            );

        $draft->markAsPublished(
            $diffusion,
            new \DateTimeImmutable(
                '2099-06-11 13:00:00'
            )
        );

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        self::assertNotNull($draft->getId());
        self::assertTrue($draft->isPublished());
        self::assertNotNull($draft->getPublishedAt());

        self::assertSame(
            $diffusion->getId(),
            $draft->getPublishedDiffusion()?->getId()
        );

        $diffusionId = $diffusion->getId();
        $draftId = $draft->getId();

        /*
         * Avant toute écriture, la preview doit reconnaître
         * que la semaine peut être dévalidée.
         */
        $preview = $this->unpublicationService->previewWeek(
            new \DateTimeImmutable('2099-06-11 00:00:00')
        );

        self::assertSame(
            '2099-06-09 00:00:00',
            $preview['weekStart']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-06-16 00:00:00',
            $preview['weekEnd']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            1,
            $preview['publishedDiffusionCount']
        );

        self::assertSame(
            1,
            $preview['linkedDraftCount']
        );

        self::assertSame(
            0,
            $preview['missingDraftCount']
        );

        self::assertTrue($preview['canUnpublish']);
        self::assertNull($preview['reason']);

        /*
         * Dévalidation réelle.
         */
        $result = $this->unpublicationService->unpublishWeek(
            new \DateTimeImmutable('2099-06-11 00:00:00')
        );

        self::assertSame(
            1,
            $result['unpublishedDiffusionCount']
        );

        self::assertSame(
            1,
            $result['restoredDraftCount']
        );

        /*
         * On relit réellement les entités depuis Doctrine.
         */
        $this->clearEntityManager();

        /** @var Diffusion|null $reloadedDiffusion */
        $reloadedDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        /** @var DiffusionDraft|null $reloadedDraft */
        $reloadedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        self::assertNotNull($reloadedDiffusion);
        self::assertNotNull($reloadedDraft);

        /*
         * La Diffusion n'est PAS supprimée.
         * Elle devient simplement non publiée.
         */
        self::assertTrue(
            $reloadedDiffusion->isUnpublished()
        );

        /*
         * Le Draft redevient la source de vérité éditable.
         */
        self::assertTrue(
            $reloadedDraft->isDraft()
        );

        self::assertNull(
            $reloadedDraft->getPublishedAt()
        );

        /*
         * POINT CRITIQUE :
         *
         * publishedDiffusion doit rester liée.
         *
         * La prochaine validation utilisera cet ID pour
         * mettre à jour cette même Diffusion au lieu
         * d'en créer une nouvelle.
         */
        self::assertNotNull(
            $reloadedDraft->getPublishedDiffusion()
        );

        self::assertSame(
            $diffusionId,
            $reloadedDraft
                ->getPublishedDiffusion()
                ?->getId()
        );

        /*
         * Les données métier de la Diffusion restent présentes.
         */
        self::assertSame(
            '2099-06-11 14:00:00',
            $reloadedDiffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-06-11 15:00:00',
            $reloadedDiffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            60,
            $reloadedDiffusion->getDurationMinutes()
        );

        self::assertSame(
            'phpunit_unpublication_group',
            $reloadedDiffusion->getAssignmentGroupKey()
        );
    }
}
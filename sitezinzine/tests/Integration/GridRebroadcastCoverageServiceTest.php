<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\GridSlotArbitration;
use App\Service\GridRebroadcastCoverageService;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GridRebroadcastCoverageServiceTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private GridRebroadcastCoverageService $coverageService;

    protected function setUp(): void
    {
        $this->bootDoctrine();

        $this->coverageService = static::getContainer()
            ->get(GridRebroadcastCoverageService::class);
    }

    protected function tearDown(): void
    {
        $this->closeEntityManager();

        parent::tearDown();
    }

    public function testPreviewDetectsMissingRegularRebroadcast(): void
    {
        $context = $this->createRebroadcastContext(
            ruleNumber: 1,
            categorySlug: 'PCT',
            categoryTitle: 'Catégorie PHPUnit couverture',
            emissionTitle: 'Émission PHPUnit - couverture rediffusion',
            emissionRef: 'PHPUNIT-COVERAGE',
            assignmentGroupKey: 'phpunit_coverage_group'
        );

        $preview = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            '2099-08-04 00:00:00',
            $preview['weekStart']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-08-11 00:00:00',
            $preview['weekEnd']->format('Y-m-d H:i:s')
        );

        self::assertSame(1, $preview['total']);
        self::assertCount(1, $preview['items']);
        self::assertSame(1, $preview['fillableCount']);

        self::assertSame(
            1,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_NORMAL]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_OVERRIDE]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_PENDING_SOURCE]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_ARBITRATED]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_AMBIGUOUS]
        );

        $item = $preview['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_MISSING,
            $item['status']
        );

        self::assertSame(
            $context['rule']->getId(),
            $item['ruleId']
        );

        self::assertSame(1, $item['ruleNumber']);

        self::assertSame(
            $context['rebroadcastSlot']->getId(),
            $item['slotId']
        );

        self::assertSame(2, $item['broadcastRank']);

        self::assertSame(
            '2099-08-04 14:00:00',
            $item['firstBroadcastStartsAt']
        );

        self::assertSame(
            '2099-08-06 20:00:00',
            $item['targetStartsAt']
        );

        self::assertSame(
            '2099-08-06 20:00:00',
            $item['originalStartsAt']
        );

        self::assertSame(
            $context['sourceDiffusion']->getId(),
            $item['sourceDiffusionId']
        );

        self::assertSame(
            $context['emission']->getId(),
            $item['expectedEmissionId']
        );

        self::assertSame(
            'Émission PHPUnit - couverture rediffusion',
            $item['expectedEmissionTitle']
        );

        self::assertNull($item['draftId']);
        self::assertNull($item['programmedEmissionId']);
        self::assertNull($item['programmedEmissionTitle']);

        self::assertSame(
            [],
            $item['ambiguousSourceDiffusionIds']
        );

        self::assertFalse($item['isProjectedOverride']);
        self::assertFalse($item['isCancelled']);
        self::assertFalse($item['isRescheduledOrigin']);
        self::assertNull($item['projectionType']);
    }

    public function testFillMissingRebroadcastCreatesRegularDraft(): void
    {
        $context = $this->createRebroadcastContext(
            ruleNumber: 2,
            categorySlug: 'PCR',
            categoryTitle: 'Catégorie PHPUnit réparation',
            emissionTitle: 'Émission PHPUnit - réparation rediffusion',
            emissionRef: 'PHPUNIT-REPAIR',
            assignmentGroupKey: 'phpunit_repair_group'
        );

        /*
         * Avant réparation :
         * la rediffusion doit bien être manquante.
         */
        $before = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(1, $before['total']);
        self::assertSame(1, $before['fillableCount']);

        self::assertSame(
            1,
            $before['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_MISSING,
            $before['items'][0]['status']
        );

        /*
         * Réparation automatique.
         */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        /*
         * Un Draft doit avoir été créé.
         */
        self::assertSame(
            1,
            $result['createdCount']
        );

        /*
         * Le groupe existait déjà sur la Diffusion source :
         * il ne doit donc pas avoir besoin d'être généré.
         */
        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        /*
         * On vide seulement l'identity map Doctrine afin de relire
         * réellement les données persistées.
         */
        $this->clearEntityManager();

        /*
         * On relit le Draft créé à partir du slot de rediffusion
         * et de son horaire théorique.
         */
        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($context['rebroadcastSlot']->getId());

        self::assertNotNull($rebroadcastSlot);

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertNotNull($draft);
        self::assertNotNull($draft->getId());

        /*
         * Le Draft doit pointer vers la même émission que
         * la première diffusion.
         */
        self::assertNotNull($draft->getEmission());

        self::assertSame(
            $context['emission']->getId(),
            $draft->getEmission()->getId()
        );

        self::assertSame(
            'Émission PHPUnit - réparation rediffusion',
            $draft->getEmission()->getTitre()
        );

        /*
         * Il s'agit bien d'une rediffusion régulière.
         */
        self::assertSame(
            DiffusionDraft::TYPE_REGULAR,
            $draft->getDraftType()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draft->getPublicationStatus()
        );

        /*
         * Le nombreDiffusion doit correspondre au broadcastRank
         * du slot : rang 2 = première rediffusion.
         */
        self::assertSame(
            2,
            $draft->getNombreDiffusion()
        );

        /*
         * Vérification du slot.
         */
        self::assertNotNull($draft->getSlot());

        self::assertSame(
            $rebroadcastSlot->getId(),
            $draft->getSlot()->getId()
        );

        self::assertSame(
            2,
            $draft->getSlot()->getBroadcastRank()
        );

        /*
         * Vérification complète de l'horaire.
         */
        self::assertSame(
            '2099-08-06 20:00:00',
            $draft->getHoraireDiffusion()
                ->format('Y-m-d H:i:s')
        );

        self::assertSame(
            60,
            $draft->getDurationMinutes()
        );

        self::assertNotNull($draft->getEndsAt());

        self::assertSame(
            '2099-08-06 21:00:00',
            $draft->getEndsAt()
                ->format('Y-m-d H:i:s')
        );

        /*
         * Le groupe doit être repris depuis la Diffusion source.
         */
        self::assertSame(
            'phpunit_repair_group',
            $draft->getAssignmentGroupKey()
        );

        /*
         * Ce Draft vient d'être créé : il n'est évidemment
         * pas encore lié à une Diffusion publiée.
         */
        self::assertNull(
            $draft->getPublishedDiffusion()
        );

        self::assertNull(
            $draft->getPublishedAt()
        );

        /*
         * Après réparation, le même créneau ne doit plus
         * apparaître comme missing.
         */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(1, $after['total']);
        self::assertSame(0, $after['fillableCount']);

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_NORMAL]
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_NORMAL,
            $after['items'][0]['status']
        );

        self::assertSame(
            $draft->getId(),
            $after['items'][0]['draftId']
        );

        self::assertSame(
            $context['emission']->getId(),
            $after['items'][0]['programmedEmissionId']
        );
    }

    public function testExistingDifferentDraftIsDetectedAsOverrideAndIsNotFilled(): void
    {
        $context = $this->createRebroadcastContext(
            ruleNumber: 4,
            categorySlug: 'PCO',
            categoryTitle: 'Catégorie PHPUnit override',
            emissionTitle: 'Émission PHPUnit - source override',
            emissionRef: 'PHPUNIT-OVERRIDE-SOURCE',
            assignmentGroupKey: 'phpunit_override_group'
        );

        /*
     * ==========================================================
     * ÉMISSION DE REMPLACEMENT
     * ==========================================================
     *
     * La règle attend l'émission source créée par le helper.
     * On programme volontairement une AUTRE émission sur
     * le créneau de rediffusion.
     */
        $overrideEmission = (new Emission())
            ->setTitre(
                'Émission PHPUnit - remplacement override'
            )
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-05 12:00:00')
            )
            ->setRef('PHPUNIT-OVERRIDE-REPLACEMENT')
            ->setDuree(60)
            ->setDescriptif(
                'Émission volontairement placée à la place de la rediffusion.'
            )
            ->setCategorie($context['category'])
            ->setEditeur($context['editeur']);

        $this->entityManager->persist($overrideEmission);

        /*
     * ==========================================================
     * DRAFT EXISTANT SUR LA REDIFFUSION
     * ==========================================================
     */
        $overrideDraft = (new DiffusionDraft())
            ->setEmission($overrideEmission)
            ->setSlot($context['rebroadcastSlot'])
            ->setSchedule(
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                ),
                60
            )
            ->setNombreDiffusion(2)
            ->setDraftType(
                DiffusionDraft::TYPE_REGULAR
            )
            ->setAssignmentGroupKey(
                'phpunit_override_replacement_group'
            )
            ->setPublicationStatus(
                DiffusionDraft::STATUS_DRAFT
            );

        $this->entityManager->persist($overrideDraft);
        $this->entityManager->flush();

        self::assertNotNull(
            $overrideEmission->getId()
        );

        self::assertNotNull(
            $overrideDraft->getId()
        );

        /*
     * On conserve les IDs avant tout éventuel clear Doctrine.
     */
        $overrideDraftId = $overrideDraft->getId();
        $overrideEmissionId = $overrideEmission->getId();
        $sourceEmissionId = $context['emission']->getId();

        /*
     * ==========================================================
     * PREVIEW
     * ==========================================================
     *
     * Le service doit constater que le créneau est occupé
     * par une émission différente de celle attendue.
     */
        $preview = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $preview['total']
        );

        self::assertCount(
            1,
            $preview['items']
        );

        /*
     * Un override n'est PAS un créneau remplissable.
     */
        self::assertSame(
            0,
            $preview['fillableCount']
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            1,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_OVERRIDE]
        );

        $item = $preview['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_OVERRIDE,
            $item['status']
        );

        /*
     * La rediffusion théorique attend toujours
     * l'émission de la Diffusion source.
     */
        self::assertSame(
            $sourceEmissionId,
            $item['expectedEmissionId']
        );

        self::assertSame(
            'Émission PHPUnit - source override',
            $item['expectedEmissionTitle']
        );

        /*
     * Mais le Draft réellement programmé contient
     * l'émission de remplacement.
     */
        self::assertSame(
            $overrideDraftId,
            $item['draftId']
        );

        self::assertSame(
            $overrideEmissionId,
            $item['programmedEmissionId']
        );

        self::assertSame(
            'Émission PHPUnit - remplacement override',
            $item['programmedEmissionTitle']
        );

        self::assertNotSame(
            $item['expectedEmissionId'],
            $item['programmedEmissionId']
        );

        /*
     * ==========================================================
     * TENTATIVE DE REMPLISSAGE AUTOMATIQUE
     * ==========================================================
     *
     * fillMissingRebroadcastsForWeek() ne doit traiter
     * QUE les STATUS_MISSING.
     *
     * Notre override doit donc être laissé intact.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        self::assertSame(
            0,
            $result['createdCount']
        );

        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        /*
     * On relit réellement la base.
     */
        $this->clearEntityManager();

        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find(
                $context['rebroadcastSlot']->getId()
            );

        self::assertNotNull($rebroadcastSlot);

        $draftAfterFill = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        /*
     * Il doit toujours s'agir exactement du Draft
     * que nous avions placé manuellement.
     */
        self::assertNotNull($draftAfterFill);

        self::assertSame(
            $overrideDraftId,
            $draftAfterFill->getId()
        );

        self::assertNotNull(
            $draftAfterFill->getEmission()
        );

        self::assertSame(
            $overrideEmissionId,
            $draftAfterFill->getEmission()->getId()
        );

        self::assertSame(
            'Émission PHPUnit - remplacement override',
            $draftAfterFill->getEmission()->getTitre()
        );

        /*
     * Son contenu ne doit pas avoir été modifié.
     */
        self::assertSame(
            'phpunit_override_replacement_group',
            $draftAfterFill->getAssignmentGroupKey()
        );

        self::assertSame(
            2,
            $draftAfterFill->getNombreDiffusion()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draftAfterFill->getPublicationStatus()
        );

        /*
     * Une nouvelle preview doit toujours signaler
     * exactement le même override.
     */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_OVERRIDE]
        );

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $after['fillableCount']
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_OVERRIDE,
            $after['items'][0]['status']
        );

        self::assertSame(
            $overrideDraftId,
            $after['items'][0]['draftId']
        );

        self::assertSame(
            $overrideEmissionId,
            $after['items'][0]['programmedEmissionId']
        );
    }

    public function testFillMissingRebroadcastGeneratesAssignmentGroupKeyWhenSourceHasNone(): void
    {
        $context = $this->createRebroadcastContext(
            ruleNumber: 3,
            categorySlug: 'PCG',
            categoryTitle: 'Catégorie PHPUnit génération groupe',
            emissionTitle: 'Émission PHPUnit - génération groupe',
            emissionRef: 'PHPUNIT-GROUP',
            assignmentGroupKey: ''
        );

        /*
     * La Diffusion source ne possède volontairement
     * aucun assignmentGroupKey.
     */
        self::assertNull(
            $context['sourceDiffusion']->getAssignmentGroupKey()
        );

        /*
     * Avant réparation, la rediffusion doit être manquante.
     */
        $before = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(1, $before['total']);
        self::assertSame(1, $before['fillableCount']);

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_MISSING,
            $before['items'][0]['status']
        );

        /*
     * Réparation automatique.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        self::assertSame(
            1,
            $result['createdCount']
        );

        /*
     * Cette fois la Diffusion source devait être modifiée,
     * puisqu'elle n'avait pas encore de groupe.
     */
        self::assertSame(
            1,
            $result['updatedSourceDiffusionCount']
        );

        /*
     * La clé attendue est construite à partir :
     *
     * - de l'ID réel de la règle ;
     * - de l'horaire de la première diffusion.
     */
        $expectedGroupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $context['rule']->getId()
        );

        /*
     * On vide l'identity map pour vérifier ce qui a
     * réellement été enregistré en base.
     */
        $sourceDiffusionId =
            $context['sourceDiffusion']->getId();

        $rebroadcastSlotId =
            $context['rebroadcastSlot']->getId();

        $emissionId =
            $context['emission']->getId();

        $this->clearEntityManager();

        /*
     * ==========================================================
     * DIFFUSION SOURCE
     * ==========================================================
     */
        $sourceDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($sourceDiffusionId);

        self::assertNotNull($sourceDiffusion);

        self::assertSame(
            $expectedGroupKey,
            $sourceDiffusion->getAssignmentGroupKey()
        );

        /*
     * ==========================================================
     * DRAFT DE REDIFFUSION
     * ==========================================================
     */
        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rebroadcastSlotId);

        self::assertNotNull($rebroadcastSlot);

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertNotNull($draft);
        self::assertNotNull($draft->getId());

        /*
     * Le Draft doit utiliser exactement le même groupe
     * que celui généré sur la Diffusion source.
     */
        self::assertSame(
            $expectedGroupKey,
            $draft->getAssignmentGroupKey()
        );

        self::assertSame(
            $sourceDiffusion->getAssignmentGroupKey(),
            $draft->getAssignmentGroupKey()
        );

        /*
     * Vérification de l'émission propagée.
     */
        self::assertNotNull($draft->getEmission());

        self::assertSame(
            $emissionId,
            $draft->getEmission()->getId()
        );

        /*
     * Vérification du rang et du type.
     */
        self::assertSame(
            2,
            $draft->getNombreDiffusion()
        );

        self::assertSame(
            DiffusionDraft::TYPE_REGULAR,
            $draft->getDraftType()
        );

        self::assertSame(
            DiffusionDraft::STATUS_DRAFT,
            $draft->getPublicationStatus()
        );

        /*
     * Vérification de l'horaire créé.
     */
        self::assertSame(
            '2099-08-06 20:00:00',
            $draft->getHoraireDiffusion()
                ->format('Y-m-d H:i:s')
        );

        self::assertSame(
            60,
            $draft->getDurationMinutes()
        );

        self::assertNotNull($draft->getEndsAt());

        self::assertSame(
            '2099-08-06 21:00:00',
            $draft->getEndsAt()
                ->format('Y-m-d H:i:s')
        );

        /*
     * Une nouvelle preview doit maintenant considérer
     * la rediffusion comme normale.
     */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(1, $after['total']);
        self::assertSame(0, $after['fillableCount']);

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_NORMAL]
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_NORMAL,
            $after['items'][0]['status']
        );

        self::assertSame(
            $expectedGroupKey,
            $sourceDiffusion->getAssignmentGroupKey()
        );
    }

    public function testRebroadcastWithoutPublishedSourceInCurrentWeekIsPendingSource(): void
    {
        $context = $this->createPendingSourceContext();

        /*
     * Il n'existe volontairement aucune Diffusion
     * correspondant à la première diffusion.
     *
     * La règle, elle, prévoit bien :
     *
     * mardi 14:00 -> première diffusion
     * jeudi 20:00 -> rediffusion rang 2
     */

        $preview = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            '2099-08-04 00:00:00',
            $preview['weekStart']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-08-11 00:00:00',
            $preview['weekEnd']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            1,
            $preview['total']
        );

        self::assertCount(
            1,
            $preview['items']
        );

        /*
     * pending_source ne doit surtout pas être considéré
     * comme un trou automatiquement remplissable.
     */
        self::assertSame(
            0,
            $preview['fillableCount']
        );

        self::assertSame(
            1,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_PENDING_SOURCE]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND]
        );

        $item = $preview['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_PENDING_SOURCE,
            $item['status']
        );

        /*
     * Le service connaît bien l'horaire théorique
     * de la première diffusion.
     */
        self::assertSame(
            '2099-08-04 14:00:00',
            $item['firstBroadcastStartsAt']
        );

        self::assertSame(
            '2099-08-06 20:00:00',
            $item['targetStartsAt']
        );

        self::assertSame(
            '2099-08-06 20:00:00',
            $item['originalStartsAt']
        );

        /*
     * Mais aucune Diffusion source réelle n'existe.
     */
        self::assertNull(
            $item['sourceDiffusionId']
        );

        self::assertNull(
            $item['expectedEmissionId']
        );

        self::assertNull(
            $item['expectedEmissionTitle']
        );

        /*
     * Aucun Draft n'existe non plus sur la rediffusion.
     */
        self::assertNull(
            $item['draftId']
        );

        self::assertNull(
            $item['programmedEmissionId']
        );

        self::assertNull(
            $item['programmedEmissionTitle']
        );

        /*
     * ==========================================================
     * TENTATIVE DE RÉPARATION
     * ==========================================================
     *
     * pending_source ne doit jamais être rempli automatiquement.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        self::assertSame(
            0,
            $result['createdCount']
        );

        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        /*
     * On vérifie réellement en base qu'aucun Draft
     * n'a été créé sur le slot de rediffusion.
     */
        $rebroadcastSlotId =
            $context['rebroadcastSlot']->getId();

        $this->clearEntityManager();

        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rebroadcastSlotId);

        self::assertNotNull($rebroadcastSlot);

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertNull($draft);

        /*
     * Le diagnostic doit rester pending_source.
     */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_PENDING_SOURCE]
        );

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $after['fillableCount']
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_PENDING_SOURCE,
            $after['items'][0]['status']
        );
    }

    public function testRebroadcastWithMissingHistoricalSourceIsSourceNotFound(): void
    {
        /*
     * Semaine contrôlée :
     * mardi 11 août -> mardi 18 août 2099.
     *
     * La première diffusion théorique appartient à la
     * semaine précédente :
     * mardi 4 août à 14:00.
     *
     * La rediffusion, grâce à weekOffset = 1,
     * tombe mardi 11 août à 20:00.
     */
        $weekStart = new \DateTimeImmutable(
            '2099-08-11 00:00:00'
        );

        /*
     * ==========================================================
     * ÉDITEUR
     * ==========================================================
     */
        $editeur = (new Editeur())
            ->setName(
                'Éditeur PHPUnit source introuvable'
            )
            ->setMail(
                'phpunit-source-not-found@example.test'
            )
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
     * ==========================================================
     * CATÉGORIE
     * ==========================================================
     */
        $category = (new Categories())
            ->setTitre(
                'Catégorie PHPUnit source introuvable'
            )
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie PHPUnit pour tester une source historique absente.'
            )
            ->setSlug('PSF')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
     * ==========================================================
     * RÈGLE
     * ==========================================================
     *
     * La règle doit être valide sur les deux semaines :
     * celle de la première diffusion et celle de sa rediffusion.
     */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(6)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-17')
            )
            ->setIsActive(true);

        /*
     * Première diffusion :
     * mardi 4 août à 14:00.
     */
        $firstSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setWeekParity(null)
            ->setIsActive(true);

        /*
     * Rediffusion rang 2 :
     *
     * mardi à 20:00,
     * mais avec un décalage d'une semaine.
     *
     * Pour la première diffusion du 4 août,
     * cette rediffusion tombe donc le 11 août à 20:00.
     */
        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('20:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(1)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setWeekParity(null)
            ->setIsActive(true);

        $rule
            ->addSlot($firstSlot)
            ->addSlot($rebroadcastSlot);

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        self::assertNotNull($rule->getId());
        self::assertNotNull($firstSlot->getId());
        self::assertNotNull($rebroadcastSlot->getId());

        /*
     * IMPORTANT :
     *
     * Nous ne créons volontairement aucune Diffusion
     * pour le mardi 4 août à 14:00.
     */

        /*
     * ==========================================================
     * PREVIEW
     * ==========================================================
     */
        $preview = $this->coverageService->previewWeek(
            $weekStart
        );

        /*
     * Une seule rediffusion de cette règle doit concerner
     * cette semaine.
     */
        self::assertSame(
            1,
            $preview['total']
        );

        self::assertCount(
            1,
            $preview['items']
        );

        /*
     * Elle n'est pas remplissable automatiquement puisque
     * l'émission source est inconnue.
     */
        self::assertSame(
            0,
            $preview['fillableCount']
        );

        self::assertSame(
            1,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_PENDING_SOURCE]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        $item = $preview['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND,
            $item['status']
        );

        /*
     * Le service doit néanmoins avoir correctement reconstruit
     * la relation temporelle entre la première diffusion et
     * sa rediffusion.
     */
        self::assertSame(
            '2099-08-04 14:00:00',
            $item['firstBroadcastStartsAt']
        );

        self::assertSame(
            '2099-08-11 20:00:00',
            $item['targetStartsAt']
        );

        self::assertSame(
            '2099-08-11 20:00:00',
            $item['originalStartsAt']
        );

        /*
     * Puisque la Diffusion source n'existe pas,
     * aucune émission attendue ne peut être déterminée.
     */
        self::assertNull(
            $item['sourceDiffusionId']
        );

        self::assertNull(
            $item['expectedEmissionId']
        );

        self::assertNull(
            $item['expectedEmissionTitle']
        );

        self::assertNull(
            $item['draftId']
        );

        /*
     * ==========================================================
     * RÉPARATION AUTOMATIQUE
     * ==========================================================
     *
     * source_not_found ne doit jamais provoquer
     * la création d'un Draft.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $weekStart
            );

        self::assertSame(
            0,
            $result['createdCount']
        );

        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        /*
     * Vérification directe en base.
     */
        $rebroadcastSlotId = $rebroadcastSlot->getId();

        $this->clearEntityManager();

        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rebroadcastSlotId);

        self::assertNotNull($rebroadcastSlot);

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-11 20:00:00'
                )
            );

        self::assertNull($draft);

        /*
     * Le diagnostic doit rester identique après
     * la tentative de réparation.
     */
        $after = $this->coverageService->previewWeek(
            $weekStart
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND]
        );

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_PENDING_SOURCE]
        );

        self::assertSame(
            0,
            $after['fillableCount']
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_SOURCE_NOT_FOUND,
            $after['items'][0]['status']
        );
    }

    public function testRebroadcastWithSeveralSourceDiffusionsIsAmbiguousAndIsNotFilled(): void
    {
        $context = $this->createRebroadcastContext(
            ruleNumber: 7,
            categorySlug: 'PAM',
            categoryTitle: 'Catégorie PHPUnit source ambiguë',
            emissionTitle: 'Émission PHPUnit - première source ambiguë',
            emissionRef: 'PHPUNIT-AMBIGUOUS-SOURCE-1',
            assignmentGroupKey: 'phpunit_ambiguous_group_1'
        );

        /*
     * Le helper a déjà créé une première Diffusion source
     * mardi 4 août à 14:00.
     *
     * Nous allons maintenant créer une DEUXIÈME émission
     * et une DEUXIÈME Diffusion exactement au même horaire.
     */

        $secondEmission = (new Emission())
            ->setTitre(
                'Émission PHPUnit - deuxième source ambiguë'
            )
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 12:30:00')
            )
            ->setRef(
                'PHPUNIT-AMBIGUOUS-SOURCE-2'
            )
            ->setDuree(60)
            ->setDescriptif(
                'Deuxième émission volontairement diffusée au même horaire.'
            )
            ->setCategorie(
                $context['category']
            )
            ->setEditeur(
                $context['editeur']
            );

        $this->entityManager->persist(
            $secondEmission
        );

        $this->entityManager->flush();

        self::assertNotNull(
            $secondEmission->getId()
        );

        /*
     * Deuxième Diffusion exactement à l'horaire
     * de la première diffusion théorique.
     */
        $secondSourceDiffusion = (new Diffusion())
            ->setEmission(
                $secondEmission
            )
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey(
                'phpunit_ambiguous_group_2'
            )
            ->setSchedule(
                new \DateTime(
                    '2099-08-04 14:00:00'
                ),
                60
            )
            ->markAsPublished();

        $this->entityManager->persist(
            $secondSourceDiffusion
        );

        $this->entityManager->flush();

        self::assertNotNull(
            $secondSourceDiffusion->getId()
        );

        /*
     * ==========================================================
     * PREVIEW
     * ==========================================================
     *
     * Le service trouve maintenant deux Diffusions
     * susceptibles d'être la source.
     */
        $preview = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $preview['total']
        );

        self::assertCount(
            1,
            $preview['items']
        );

        /*
     * Une ambiguïté ne doit jamais être considérée
     * comme remplissable automatiquement.
     */
        self::assertSame(
            0,
            $preview['fillableCount']
        );

        self::assertSame(
            1,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_AMBIGUOUS]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_NORMAL]
        );

        $item = $preview['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_AMBIGUOUS,
            $item['status']
        );

        /*
     * L'horaire théorique reste parfaitement connu.
     */
        self::assertSame(
            '2099-08-04 14:00:00',
            $item['firstBroadcastStartsAt']
        );

        self::assertSame(
            '2099-08-06 20:00:00',
            $item['targetStartsAt']
        );

        /*
     * Mais aucune Diffusion ne doit être choisie
     * arbitrairement comme source.
     */
        self::assertNull(
            $item['sourceDiffusionId']
        );

        self::assertNull(
            $item['expectedEmissionId']
        );

        self::assertNull(
            $item['expectedEmissionTitle']
        );

        /*
     * Les deux Diffusions trouvées doivent en revanche
     * être signalées comme candidates ambiguës.
     */
        $ambiguousIds =
            $item['ambiguousSourceDiffusionIds'];

        self::assertCount(
            2,
            $ambiguousIds
        );

        self::assertContains(
            $context['sourceDiffusion']->getId(),
            $ambiguousIds
        );

        self::assertContains(
            $secondSourceDiffusion->getId(),
            $ambiguousIds
        );

        /*
     * Aucun Draft n'existe encore sur la rediffusion.
     */
        self::assertNull(
            $item['draftId']
        );

        self::assertNull(
            $item['programmedEmissionId']
        );

        self::assertNull(
            $item['programmedEmissionTitle']
        );

        /*
     * ==========================================================
     * TENTATIVE DE RÉPARATION
     * ==========================================================
     *
     * fillMissingRebroadcastsForWeek() ne doit rien faire :
     * AMBIGUOUS n'est pas MISSING.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        self::assertSame(
            0,
            $result['createdCount']
        );

        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        /*
     * Vérification réelle en base :
     * aucun Draft ne doit avoir été créé.
     */
        $rebroadcastSlotId =
            $context['rebroadcastSlot']->getId();

        $this->clearEntityManager();

        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find(
                $rebroadcastSlotId
            );

        self::assertNotNull(
            $rebroadcastSlot
        );

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertNull(
            $draft
        );

        /*
     * ==========================================================
     * NOUVELLE PREVIEW
     * ==========================================================
     *
     * La tentative de réparation ne doit avoir modifié
     * ni les données ni le diagnostic.
     */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_AMBIGUOUS]
        );

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $after['fillableCount']
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_AMBIGUOUS,
            $after['items'][0]['status']
        );

        self::assertCount(
            2,
            $after['items'][0]['ambiguousSourceDiffusionIds']
        );
    }

    public function testCancelledRebroadcastIsArbitratedAndIsNotFilled(): void
    {
        $context = $this->createRebroadcastContext(
            ruleNumber: 8,
            categorySlug: 'PAR',
            categoryTitle: 'Catégorie PHPUnit arbitrage',
            emissionTitle: 'Émission PHPUnit - arbitrage',
            emissionRef: 'PHPUNIT-ARBITRATED',
            assignmentGroupKey: 'phpunit_arbitrated_group'
        );

        /*
     * ==========================================================
     * ARBITRAGE
     * ==========================================================
     *
     * La rediffusion régulière prévue jeudi 6 août
     * de 20:00 à 21:00 est volontairement annulée.
     */
        $arbitration = (new GridSlotArbitration())
            ->setSlot(
                $context['rebroadcastSlot']
            )
            ->setOriginalStartsAt(
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable(
                    '2099-08-06 21:00:00'
                )
            )
            ->setType(
                GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT
            )
            ->setAction(
                GridSlotArbitration::ACTION_CANCEL
            )
            ->setArbitrationGroupKey(
                'phpunit_arbitration_cancel'
            );

        /*
     * La décision est effective.
     */
        $arbitration->markResolved();

        $this->entityManager->persist(
            $arbitration
        );

        $this->entityManager->flush();

        self::assertNotNull(
            $arbitration->getId()
        );

        self::assertSame(
            GridSlotArbitration::STATUS_RESOLVED,
            $arbitration->getStatus()
        );

        self::assertSame(
            GridSlotArbitration::ACTION_CANCEL,
            $arbitration->getAction()
        );

        /*
     * ==========================================================
     * PREVIEW
     * ==========================================================
     */
        $preview = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $preview['total']
        );

        self::assertCount(
            1,
            $preview['items']
        );

        /*
     * Une rediffusion arbitrée n'est pas une rediffusion
     * manquante à réparer.
     */
        self::assertSame(
            0,
            $preview['fillableCount']
        );

        self::assertSame(
            1,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_ARBITRATED]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_NORMAL]
        );

        $item = $preview['items'][0];

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_ARBITRATED,
            $item['status']
        );

        /*
     * L'occurrence originale reste identifiable.
     */
        self::assertSame(
            '2099-08-06 20:00:00',
            $item['originalStartsAt']
        );

        self::assertSame(
            '2099-08-04 14:00:00',
            $item['firstBroadcastStartsAt']
        );

        /*
     * L'annulation issue de l'arbitrage doit être
     * visible dans la projection.
     */
        self::assertTrue(
            $item['isCancelled']
        );

        /*
     * Il n'existe volontairement aucun Draft sur
     * cette rediffusion annulée.
     */
        self::assertNull(
            $item['draftId']
        );

        self::assertNull(
            $item['programmedEmissionId']
        );

        /*
     * ==========================================================
     * TENTATIVE DE RÉPARATION
     * ==========================================================
     *
     * Le remplissage automatique ne doit PAS recréer
     * une occurrence que l'utilisateur a annulée.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        self::assertSame(
            0,
            $result['createdCount']
        );

        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        /*
     * Vérification directe en base.
     */
        $rebroadcastSlotId =
            $context['rebroadcastSlot']->getId();

        $this->clearEntityManager();

        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find(
                $rebroadcastSlotId
            );

        self::assertNotNull(
            $rebroadcastSlot
        );

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        /*
     * Aucun Draft ne doit avoir été recréé.
     */
        self::assertNull(
            $draft
        );

        /*
     * ==========================================================
     * NOUVELLE PREVIEW
     * ==========================================================
     *
     * Le diagnostic doit rester arbitrated.
     */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_ARBITRATED]
        );

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $after['fillableCount']
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_ARBITRATED,
            $after['items'][0]['status']
        );

        self::assertTrue(
            $after['items'][0]['isCancelled']
        );
    }

    public function testRescheduledRebroadcastIsArbitratedAndOriginalSlotIsNotFilled(): void
    {
        $context = $this->createRebroadcastContext(
            ruleNumber: 9,
            categorySlug: 'PRS',
            categoryTitle: 'Catégorie PHPUnit rediffusion déplacée',
            emissionTitle: 'Émission PHPUnit - rediffusion déplacée',
            emissionRef: 'PHPUNIT-RESCHEDULED',
            assignmentGroupKey: 'phpunit_rescheduled_group'
        );

        /*
     * ==========================================================
     * ARBITRAGE
     * ==========================================================
     *
     * La rediffusion régulière prévue :
     *
     * jeudi 6 août 2099 de 20:00 à 21:00
     *
     * est déplacée vers :
     *
     * vendredi 7 août 2099 de 18:00 à 19:00.
     */
        $arbitration = (new GridSlotArbitration())
            ->setSlot(
                $context['rebroadcastSlot']
            )
            ->setOriginalStartsAt(
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            )
            ->setOriginalEndsAt(
                new \DateTimeImmutable(
                    '2099-08-06 21:00:00'
                )
            )
            ->setType(
                GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT
            )
            ->setAction(
                GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM
            )
            ->setRescheduledStartsAt(
                new \DateTimeImmutable(
                    '2099-08-07 18:00:00'
                )
            )
            ->setRescheduledEndsAt(
                new \DateTimeImmutable(
                    '2099-08-07 19:00:00'
                )
            )
            ->setArbitrationGroupKey(
                'phpunit_arbitration_reschedule'
            );

        $arbitration->markResolved();

        $this->entityManager->persist(
            $arbitration
        );

        $this->entityManager->flush();

        self::assertNotNull(
            $arbitration->getId()
        );

        self::assertSame(
            GridSlotArbitration::STATUS_RESOLVED,
            $arbitration->getStatus()
        );

        self::assertSame(
            GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM,
            $arbitration->getAction()
        );

        self::assertTrue(
            $arbitration->isRescheduleAction()
        );

        /*
     * ==========================================================
     * PREVIEW
     * ==========================================================
     */
        $preview = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        /*
     * Le déplacement peut produire plusieurs segments projetés.
     *
     * On ne suppose donc pas que l'item qui nous intéresse
     * se trouve obligatoirement à l'index 0.
     */
        $arbitratedItems = array_values(
            array_filter(
                $preview['items'],
                static fn(array $item): bool =>
                GridRebroadcastCoverageService::STATUS_ARBITRATED
                    === $item['status']
            )
        );

        self::assertNotEmpty(
            $arbitratedItems
        );

        /*
     * Aucun élément issu de cet arbitrage ne doit être
     * considéré comme une rediffusion manquante remplissable.
     */
        self::assertSame(
            0,
            $preview['fillableCount']
        );

        self::assertSame(
            0,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertGreaterThanOrEqual(
            1,
            $preview['counts'][GridRebroadcastCoverageService::STATUS_ARBITRATED]
        );

        /*
     * ==========================================================
     * OCCURRENCE D'ORIGINE
     * ==========================================================
     *
     * On recherche explicitement l'item correspondant
     * à l'ancien horaire.
     */
        $originalItem = null;

        foreach ($arbitratedItems as $item) {
            if (
                '2099-08-06 20:00:00'
                === $item['originalStartsAt']
            ) {
                $originalItem = $item;
                break;
            }
        }

        self::assertNotNull(
            $originalItem
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_ARBITRATED,
            $originalItem['status']
        );

        self::assertSame(
            '2099-08-04 14:00:00',
            $originalItem['firstBroadcastStartsAt']
        );

        /*
     * L'occurrence d'origine doit être identifiée comme
     * provenant d'un déplacement.
     */
        self::assertTrue(
            $originalItem['isRescheduledOrigin']
        );

        /*
     * Une rediffusion déplacée n'est pas une annulation.
     */
        self::assertFalse(
            $originalItem['isCancelled']
        );

        /*
     * ==========================================================
     * OCCURRENCE PROJETÉE
     * ==========================================================
     *
     * On vérifie également que le nouvel horaire apparaît
     * bien dans les éléments arbitrés.
     */
        $projectedItem = null;

        foreach ($arbitratedItems as $item) {
            if (
                '2099-08-07 18:00:00'
                === $item['targetStartsAt']
            ) {
                $projectedItem = $item;
                break;
            }
        }

        self::assertNotNull(
            $projectedItem
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_ARBITRATED,
            $projectedItem['status']
        );

        self::assertTrue(
            $projectedItem['isProjectedOverride']
        );

        /*
     * ==========================================================
     * TENTATIVE DE RÉPARATION
     * ==========================================================
     *
     * Le service de couverture ne doit créer aucun Draft
     * pour une occurrence explicitement déplacée.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        self::assertSame(
            0,
            $result['createdCount']
        );

        self::assertSame(
            0,
            $result['updatedSourceDiffusionCount']
        );

        /*
     * ==========================================================
     * VÉRIFICATION EN BASE
     * ==========================================================
     *
     * Aucun Draft ne doit avoir été recréé à l'ancien horaire.
     */
        $rebroadcastSlotId =
            $context['rebroadcastSlot']->getId();

        $this->clearEntityManager();

        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find(
                $rebroadcastSlotId
            );

        self::assertNotNull(
            $rebroadcastSlot
        );

        $draftAtOriginalTime = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertNull(
            $draftAtOriginalTime
        );

        /*
     * Le service de couverture ne doit pas non plus créer
     * lui-même un Draft au nouvel horaire.
     *
     * Le déplacement appartient au mécanisme d'arbitrage /
     * projection, pas au mécanisme de réparation des missing.
     */
        $draftAtRescheduledTime = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-07 18:00:00'
                )
            );

        self::assertNull(
            $draftAtRescheduledTime
        );

        /*
     * ==========================================================
     * NOUVELLE PREVIEW
     * ==========================================================
     */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $after['fillableCount']
        );

        self::assertGreaterThanOrEqual(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_ARBITRATED]
        );
    }

    public function testExistingLegacyRebroadcastDraftWithoutGroupKeyIsAttachedToSourceGroupWithoutDuplicate(): void
    {
        /*
     * ==========================================================
     * CONTEXTE
     * ==========================================================
     *
     * On crée :
     *
     * - une règle régulière ;
     * - une première diffusion mardi 4 août à 14:00 ;
     * - une rediffusion prévue jeudi 6 août à 20:00 ;
     * - une Diffusion source existante SANS assignmentGroupKey.
     */
        $context = $this->createRebroadcastContext(
            ruleNumber: 10,
            categorySlug: 'PLG',
            categoryTitle: 'Catégorie PHPUnit legacy group',
            emissionTitle: 'Émission PHPUnit - legacy group',
            emissionRef: 'PHPUNIT-LEGACY-GROUP',
            assignmentGroupKey: ''
        );

        self::assertNull(
            $context['sourceDiffusion']->getAssignmentGroupKey()
        );

        /*
     * ==========================================================
     * DRAFT DE REDIFFUSION LEGACY DÉJÀ EXISTANT
     * ==========================================================
     *
     * Contrairement aux tests précédents, la rediffusion
     * n'est PAS manquante.
     *
     * Elle existe déjà en base, avec :
     *
     * - le bon slot ;
     * - la bonne émission ;
     * - le bon horaire ;
     * - le bon rang ;
     * - mais AUCUNE assignmentGroupKey.
     */
        $legacyDraft = (new DiffusionDraft())
            ->setEmission(
                $context['emission']
            )
            ->setSlot(
                $context['rebroadcastSlot']
            )
            ->setSchedule(
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                ),
                60
            )
            ->setNombreDiffusion(2)
            ->setDraftType(
                DiffusionDraft::TYPE_REGULAR
            )
            ->setPublicationStatus(
                DiffusionDraft::STATUS_DRAFT
            );

        $this->entityManager->persist(
            $legacyDraft
        );

        $this->entityManager->flush();

        self::assertNotNull(
            $legacyDraft->getId()
        );

        self::assertNull(
            $legacyDraft->getAssignmentGroupKey()
        );

        $legacyDraftId = $legacyDraft->getId();
        $sourceDiffusionId = $context['sourceDiffusion']->getId();

        /*
     * ==========================================================
     * PREVIEW AVANT RÉPARATION
     * ==========================================================
     *
     * Le Draft contient bien l'émission attendue.
     *
     * Le créneau n'est donc pas "missing" et ne doit surtout
     * pas être considéré comme un override.
     */
        $before = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $before['total']
        );

        self::assertSame(
            0,
            $before['fillableCount']
        );

        self::assertSame(
            1,
            $before['counts'][GridRebroadcastCoverageService::STATUS_NORMAL]
        );

        self::assertSame(
            0,
            $before['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $before['counts'][GridRebroadcastCoverageService::STATUS_OVERRIDE]
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_NORMAL,
            $before['items'][0]['status']
        );

        self::assertSame(
            $legacyDraftId,
            $before['items'][0]['draftId']
        );

        /*
     * ==========================================================
     * RÉPARATION
     * ==========================================================
     *
     * Comportement souhaité :
     *
     * même si la rediffusion n'est pas "missing", le service
     * doit reconnaître que la source et le Draft appartiennent
     * au même groupe logique et reconstruire leur groupKey.
     *
     * Aucun nouveau Draft ne doit être créé.
     */
        $result = $this->coverageService
            ->fillMissingRebroadcastsForWeek(
                $context['weekStart']
            );

        /*
     * Aucun doublon.
     */
        self::assertSame(
            0,
            $result['createdCount']
        );

        /*
     * ==========================================================
     * CLÉ ATTENDUE
     * ==========================================================
     */
        $expectedGroupKey = sprintf(
            'rule_%d_origin_20990804_1400',
            $context['rule']->getId()
        );

        /*
     * ==========================================================
     * RELECTURE DE LA BASE
     * ==========================================================
     */
        $this->clearEntityManager();

        $sourceDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find(
                $sourceDiffusionId
            );

        $legacyDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find(
                $legacyDraftId
            );

        self::assertNotNull(
            $sourceDiffusion
        );

        self::assertNotNull(
            $legacyDraft
        );

        /*
     * La Diffusion source doit maintenant avoir une clé.
     */
        self::assertSame(
            $expectedGroupKey,
            $sourceDiffusion->getAssignmentGroupKey()
        );

        /*
     * Le Draft legacy doit recevoir exactement la même clé.
     */
        self::assertSame(
            $expectedGroupKey,
            $legacyDraft->getAssignmentGroupKey()
        );

        self::assertSame(
            $sourceDiffusion->getAssignmentGroupKey(),
            $legacyDraft->getAssignmentGroupKey()
        );

        /*
     * ==========================================================
     * AUCUN DOUBLON NE DOIT AVOIR ÉTÉ CRÉÉ
     * ==========================================================
     */
        $rebroadcastSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find(
                $context['rebroadcastSlot']->getId()
            );

        self::assertNotNull(
            $rebroadcastSlot
        );

        $draftAtRebroadcast = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findOneActiveDraftBySlotAndHoraire(
                $rebroadcastSlot,
                new \DateTimeImmutable(
                    '2099-08-06 20:00:00'
                )
            );

        self::assertNotNull(
            $draftAtRebroadcast
        );

        self::assertSame(
            $legacyDraftId,
            $draftAtRebroadcast->getId()
        );

        /*
     * ==========================================================
     * PREVIEW APRÈS RÉPARATION
     * ==========================================================
     *
     * Le créneau reste normal.
     */
        $after = $this->coverageService->previewWeek(
            $context['weekStart']
        );

        self::assertSame(
            1,
            $after['counts'][GridRebroadcastCoverageService::STATUS_NORMAL]
        );

        self::assertSame(
            0,
            $after['counts'][GridRebroadcastCoverageService::STATUS_MISSING]
        );

        self::assertSame(
            0,
            $after['fillableCount']
        );

        self::assertSame(
            GridRebroadcastCoverageService::STATUS_NORMAL,
            $after['items'][0]['status']
        );

        self::assertSame(
            $legacyDraftId,
            $after['items'][0]['draftId']
        );
    }

    /**
     * Crée une règle complète de rediffusion mais SANS
     * créer la Diffusion correspondant au rang 1.
     *
     * @return array{
     *     weekStart: \DateTimeImmutable,
     *     editeur: Editeur,
     *     category: Categories,
     *     rule: ProgrammationRule,
     *     firstSlot: ProgrammationRuleSlot,
     *     rebroadcastSlot: ProgrammationRuleSlot
     * }
     */
    private function createPendingSourceContext(): array
    {
        $weekStart = new \DateTimeImmutable(
            '2099-08-04 00:00:00'
        );

        /*
     * ==========================================================
     * ÉDITEUR
     * ==========================================================
     */
        $editeur = (new Editeur())
            ->setName(
                'Éditeur PHPUnit pending source'
            )
            ->setMail(
                'phpunit-pending-source@example.test'
            )
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        /*
     * ==========================================================
     * CATÉGORIE
     * ==========================================================
     */
        $category = (new Categories())
            ->setTitre(
                'Catégorie PHPUnit pending source'
            )
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie PHPUnit pour tester une source encore non publiée.'
            )
            ->setSlug('PCS')
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        /*
     * ==========================================================
     * RÈGLE
     * ==========================================================
     */
        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(5)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        /*
     * Première diffusion théorique :
     * mardi à 14:00.
     */
        $firstSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setWeekParity(null)
            ->setIsActive(true);

        /*
     * Première rediffusion :
     * jeudi à 20:00.
     */
        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(4)
            ->setStartTime(
                new \DateTimeImmutable('20:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setWeekParity(null)
            ->setIsActive(true);

        $rule
            ->addSlot($firstSlot)
            ->addSlot($rebroadcastSlot);

        $this->entityManager->persist($rule);

        /*
     * IMPORTANT :
     *
     * On ne crée :
     * - aucune Emission ;
     * - aucune Diffusion ;
     * - aucun DiffusionDraft.
     *
     * La grille théorique existe grâce à la règle,
     * mais la première diffusion n'est pas encore publiée.
     */
        $this->entityManager->flush();

        self::assertNotNull($editeur->getId());
        self::assertNotNull($category->getId());
        self::assertNotNull($rule->getId());
        self::assertNotNull($firstSlot->getId());
        self::assertNotNull($rebroadcastSlot->getId());

        self::assertSame(
            1,
            $firstSlot->getBroadcastRank()
        );

        self::assertSame(
            2,
            $rebroadcastSlot->getBroadcastRank()
        );

        return [
            'weekStart' => $weekStart,
            'editeur' => $editeur,
            'category' => $category,
            'rule' => $rule,
            'firstSlot' => $firstSlot,
            'rebroadcastSlot' => $rebroadcastSlot,
        ];
    }

    /**
     * Crée une vraie règle hebdomadaire avec :
     *
     * - première diffusion mardi à 14:00 ;
     * - première rediffusion jeudi à 20:00 ;
     * - une vraie Diffusion source ;
     * - aucun DiffusionDraft sur la rediffusion.
     *
     * @return array{
     *     weekStart: \DateTimeImmutable,
     *     editeur: Editeur,
     *     category: Categories,
     *     rule: ProgrammationRule,
     *     firstSlot: ProgrammationRuleSlot,
     *     rebroadcastSlot: ProgrammationRuleSlot,
     *     emission: Emission,
     *     sourceDiffusion: Diffusion
     * }
     */
    private function createRebroadcastContext(
        int $ruleNumber,
        string $categorySlug,
        string $categoryTitle,
        string $emissionTitle,
        string $emissionRef,
        string $assignmentGroupKey
    ): array {
        $weekStart = new \DateTimeImmutable(
            '2099-08-04 00:00:00'
        );

        $editeur = (new Editeur())
            ->setName(
                'Éditeur PHPUnit ' . $ruleNumber
            )
            ->setMail(
                sprintf(
                    'phpunit-coverage-%d@example.test',
                    $ruleNumber
                )
            )
            ->setPhone(null)
            ->setUpdateAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($editeur);

        $category = (new Categories())
            ->setTitre($categoryTitle)
            ->setEditeur($editeur)
            ->setDuree(60)
            ->setDescriptif(
                'Catégorie PHPUnit pour la couverture des rediffusions.'
            )
            ->setSlug($categorySlug)
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(
                new \DateTime('2099-08-04 10:00:00')
            );

        $this->entityManager->persist($category);

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber($ruleNumber)
            ->setValidFrom(
                new \DateTimeImmutable('2099-08-04')
            )
            ->setValidUntil(
                new \DateTimeImmutable('2099-08-10')
            )
            ->setIsActive(true);

        $firstSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(2)
            ->setStartTime(
                new \DateTimeImmutable('14:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setWeekParity(null)
            ->setIsActive(true);

        $rebroadcastSlot = (new ProgrammationRuleSlot())
            ->setDayOfWeek(4)
            ->setStartTime(
                new \DateTimeImmutable('20:00:00')
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank(2)
            ->setWeekOffset(0)
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setWeekParity(null)
            ->setIsActive(true);

        $rule
            ->addSlot($firstSlot)
            ->addSlot($rebroadcastSlot);

        $this->entityManager->persist($rule);

        $emission = (new Emission())
            ->setTitre($emissionTitle)
            ->setKeyword('')
            ->setDatepub(
                new \DateTime('2099-08-04 12:00:00')
            )
            ->setRef($emissionRef)
            ->setDuree(60)
            ->setDescriptif(
                'Émission PHPUnit utilisée comme source de rediffusion.'
            )
            ->setCategorie($category)
            ->setEditeur($editeur);

        $this->entityManager->persist($emission);

        $this->entityManager->flush();

        self::assertNotNull($editeur->getId());
        self::assertNotNull($category->getId());
        self::assertNotNull($rule->getId());
        self::assertNotNull($firstSlot->getId());
        self::assertNotNull($rebroadcastSlot->getId());
        self::assertNotNull($emission->getId());

        self::assertSame(
            1,
            $firstSlot->getBroadcastRank()
        );

        self::assertSame(
            2,
            $rebroadcastSlot->getBroadcastRank()
        );

        $sourceDiffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setSchedule(
                new \DateTime(
                    '2099-08-04 14:00:00'
                ),
                60
            )
            ->markAsPublished();

        if ($assignmentGroupKey !== '') {
            $sourceDiffusion->setAssignmentGroupKey(
                $assignmentGroupKey
            );
        }

        $this->entityManager->persist(
            $sourceDiffusion
        );

        $this->entityManager->flush();

        self::assertNotNull(
            $sourceDiffusion->getId()
        );

        self::assertTrue(
            $sourceDiffusion->isPublished()
        );

        return [
            'weekStart' => $weekStart,
            'editeur' => $editeur,
            'category' => $category,
            'rule' => $rule,
            'firstSlot' => $firstSlot,
            'rebroadcastSlot' => $rebroadcastSlot,
            'emission' => $emission,
            'sourceDiffusion' => $sourceDiffusion,
        ];
    }
}

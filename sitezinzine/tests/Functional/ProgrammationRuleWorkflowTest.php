<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\PendingRebroadcast;
use App\Tests\Support\FunctionalTestCase;

class ProgrammationRuleWorkflowTest extends FunctionalTestCase
{
    public function testConflictingSlotDisablesRuleThenConflictCanBeFixedAndRuleReactivated(): void
    {
        /*
         * ============================================================
         * ARRANGE
         * ============================================================
         *
         * Deux règles de catégories différentes.
         *
         * La première possède déjà :
         * mardi 20:00 → 21:00, toutes les semaines.
         *
         * La seconde est vide et active.
         * On va lui ajouter exactement le même créneau depuis
         * le vrai formulaire HTTP.
         */

        $admin = $this->createUser('ROLE_ADMIN');

        $referenceCategory = $this->createCategory();
        $candidateCategory = $this->createCategory();

        $referenceRule = (new ProgrammationRule())
            ->setCategory($referenceCategory)
            ->setRuleNumber(1)
            ->setIsActive(true);

        $referenceSlot = (new ProgrammationRuleSlot())
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('20:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setWeekOffset(0)
            ->setWeekParity(null)
            ->setIsActive(true);

        $referenceRule->addSlot($referenceSlot);

        $candidateRule = (new ProgrammationRule())
            ->setCategory($candidateCategory)
            ->setRuleNumber(1)
            ->setIsActive(true);

        $this->entityManager->persist($referenceRule);
        $this->entityManager->persist($candidateRule);
        $this->entityManager->flush();

        $referenceRuleId = $referenceRule->getId();
        $candidateRuleId = $candidateRule->getId();

        self::assertNotNull($referenceRuleId);
        self::assertNotNull($candidateRuleId);

        $this->client->loginUser($admin);

        /*
         * ============================================================
         * 1. CRÉATION D'UN CRÉNEAU EN CONFLIT
         * ============================================================
         */

        $crawler = $this->client->request(
            'GET',
            sprintf(
                '/admin/programmationRule/%d/slots/create',
                $candidateRuleId
            )
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'h1',
            'Créer un créneau'
        );

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form([
                'programmation_rule_slot[recurrenceType]' =>
                ProgrammationRuleSlot::RECURRENCE_WEEKLY,

                'programmation_rule_slot[monthlyOccurrence]' => '',
                'programmation_rule_slot[monthInterval]' => '1',
                'programmation_rule_slot[dayOfWeek]' => '2',

                'programmation_rule_slot[startTime][hour]' => '20',
                'programmation_rule_slot[startTime][minute]' => '0',

                'programmation_rule_slot[durationMinutes]' => '60',
                'programmation_rule_slot[broadcastRank]' => '1',
                'programmation_rule_slot[weekOffset]' => '0',
                'programmation_rule_slot[weekParity]' => '',

                'programmation_rule_slot[isActive]' => '1',
            ]);

        $this->client->submit($form);

        self::assertResponseRedirects(
            sprintf(
                '/admin/programmationRule/%d/slots/',
                $candidateRuleId
            )
        );

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains(
            'body',
            'Le créneau a bien été créé, mais la règle a été désactivée car elle présente un conflit de programmation.'
        );

        /*
         * ============================================================
         * 2. VÉRIFICATION BDD + AFFICHAGE DU CONFLIT
         * ============================================================
         */

        $this->entityManager->clear();

        $candidateRule = $this->entityManager
            ->getRepository(ProgrammationRule::class)
            ->find($candidateRuleId);

        self::assertNotNull($candidateRule);
        self::assertFalse(
            $candidateRule->isActive(),
            'La règle doit être désactivée lorsqu’un créneau conflictuel est sauvegardé.'
        );

        $candidateSlots = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->findBy([
                'rule' => $candidateRule,
                'deletedAt' => null,
            ]);

        self::assertCount(
            1,
            $candidateSlots,
            'Le créneau conflictuel doit quand même être sauvegardé.'
        );

        /** @var ProgrammationRuleSlot $candidateSlot */
        $candidateSlot = $candidateSlots[0];

        self::assertSame(2, $candidateSlot->getDayOfWeek());
        self::assertSame(
            '20:00',
            $candidateSlot->getStartTime()?->format('H:i')
        );
        self::assertSame(60, $candidateSlot->getDurationMinutes());

        /*
         * Le conflit doit également être réellement visible
         * dans la page des créneaux.
         */
        self::assertSelectorTextContains(
            'body',
            'Conflit'
        );

        /*
         * ============================================================
         * 3. CORRECTION DU CRÉNEAU
         * ============================================================
         *
         * On déplace le second créneau à 22:00.
         * Il ne chevauche donc plus 20:00 → 21:00.
         */

        $candidateSlotId = $candidateSlot->getId();

        self::assertNotNull($candidateSlotId);

        $crawler = $this->client->request(
            'GET',
            sprintf(
                '/admin/programmationRule/%d/slots/%d/edit',
                $candidateRuleId,
                $candidateSlotId
            )
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'h1',
            'Modifier un créneau'
        );

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form([
                'programmation_rule_slot[recurrenceType]' =>
                ProgrammationRuleSlot::RECURRENCE_WEEKLY,

                'programmation_rule_slot[monthlyOccurrence]' => '',
                'programmation_rule_slot[monthInterval]' => '1',
                'programmation_rule_slot[dayOfWeek]' => '2',

                'programmation_rule_slot[startTime][hour]' => '22',
                'programmation_rule_slot[startTime][minute]' => '0',

                'programmation_rule_slot[durationMinutes]' => '60',
                'programmation_rule_slot[broadcastRank]' => '1',
                'programmation_rule_slot[weekOffset]' => '0',
                'programmation_rule_slot[weekParity]' => '',

                'programmation_rule_slot[isActive]' => '1',
            ]);

        $this->client->submit($form);

        self::assertResponseRedirects(
            sprintf(
                '/admin/programmationRule/%d/slots/',
                $candidateRuleId
            )
        );

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains(
            'body',
            'Le créneau a bien été modifié. La règle ne présente plus de conflit et peut être réactivée.'
        );

        /*
         * ============================================================
         * 4. LA CORRECTION NE DOIT PAS RÉACTIVER AUTOMATIQUEMENT
         * ============================================================
         */

        $this->entityManager->clear();

        $candidateRule = $this->entityManager
            ->getRepository(ProgrammationRule::class)
            ->find($candidateRuleId);

        self::assertNotNull($candidateRule);

        self::assertFalse(
            $candidateRule->isActive(),
            'Corriger le conflit ne doit jamais réactiver automatiquement la règle.'
        );

        $candidateSlot = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($candidateSlotId);

        self::assertNotNull($candidateSlot);

        self::assertSame(
            '22:00',
            $candidateSlot->getStartTime()?->format('H:i'),
            'La correction du créneau doit être persistée.'
        );

        /*
         * ============================================================
         * 5. RÉACTIVATION MANUELLE DEPUIS L'INTERFACE
         * ============================================================
         */

        $crawler = $this->client->request(
            'GET',
            '/admin/programmationRule/'
        );

        self::assertResponseIsSuccessful();

        /*
         * On récupère précisément le formulaire POST correspondant
         * à cette règle afin de soumettre le vrai token CSRF rendu
         * par Twig.
         */
        $activateAction = sprintf(
            '/admin/programmationRule/%d/activate',
            $candidateRuleId
        );

        $activateFormNode = $crawler
            ->filter(sprintf(
                'form[action="%s"]',
                $activateAction
            ))
            ->first();

        self::assertSame(
            1,
            $activateFormNode->count(),
            'Une règle inactive sans conflit doit proposer sa réactivation.'
        );

        $activateForm = $activateFormNode->form();

        $this->client->submit($activateForm);

        self::assertResponseRedirects(
            '/admin/programmationRule/'
        );

        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains(
            'body',
            'Règle réactivée'
        );

        /*
         * ============================================================
         * 6. ÉTAT FINAL EN BDD
         * ============================================================
         */

        $this->entityManager->clear();

        $candidateRule = $this->entityManager
            ->getRepository(ProgrammationRule::class)
            ->find($candidateRuleId);

        self::assertNotNull($candidateRule);

        self::assertTrue(
            $candidateRule->isActive(),
            'Après correction du conflit et réactivation manuelle, la règle doit être active.'
        );
    }

    public function testDeactivatingRulePurgesFuturePublishedGroup(): void
    {
        /*
     * ============================================================
     * ARRANGE
     * ============================================================
     *
     * Groupe entièrement futur :
     *
     * - première diffusion publiée dans le futur ;
     * - rediffusion publiée dans le futur ;
     * - un PendingRebroadcast appartient au même groupe.
     *
     * La désactivation manuelle de la règle est une invalidation
     * structurelle explicite.
     */

        $admin = $this->createUser('ROLE_ADMIN');
        $category = $this->createCategory();

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setIsActive(true);

        $firstSlot = $this->createWeeklyRuleSlot(
            $rule,
            1,
            2,
            '10:00'
        );

        $rebroadcastSlot = $this->createWeeklyRuleSlot(
            $rule,
            2,
            4,
            '10:00'
        );

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $ruleId = $rule->getId();

        self::assertNotNull($ruleId);

        /*
     * On place le groupe suffisamment loin dans le futur pour qu'il
     * soit forcément considéré comme non commencé pendant le test.
     */
        $firstStartsAt = (new \DateTimeImmutable('today'))
            ->modify('+21 days')
            ->setTime(10, 0);

        $rebroadcastStartsAt = $firstStartsAt
            ->modify('+2 days');

        $emission = $this->createEmission(
            $this->marker . ' future purge',
            null,
            $category
        );

        $groupKey = sprintf(
            'rule_%d_origin_%s',
            $ruleId,
            $firstStartsAt->format('Ymd_Hi')
        );

        $firstDraft = $this->createPublishedRegularDraft(
            $emission,
            $firstSlot,
            $firstStartsAt,
            1,
            $groupKey
        );

        $rebroadcastDraft = $this->createPublishedRegularDraft(
            $emission,
            $rebroadcastSlot,
            $rebroadcastStartsAt,
            2,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $rebroadcastDraftId = $rebroadcastDraft->getId();
        $firstDiffusionId = $firstDraft
            ->getPublishedDiffusion()
            ?->getId();
        $rebroadcastDiffusionId = $rebroadcastDraft
            ->getPublishedDiffusion()
            ?->getId();
        $pendingId = $pending->getId();

        self::assertNotNull($firstDraftId);
        self::assertNotNull($rebroadcastDraftId);
        self::assertNotNull($firstDiffusionId);
        self::assertNotNull($rebroadcastDiffusionId);
        self::assertNotNull($pendingId);

        /*
     * ============================================================
     * ACT
     * ============================================================
     */

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/programmationRule/'
        );

        self::assertResponseIsSuccessful();

        $deactivateAction = sprintf(
            '/admin/programmationRule/%d/deactivate',
            $ruleId
        );

        $deactivateFormNode = $crawler
            ->filter(sprintf(
                'form[action="%s"]',
                $deactivateAction
            ))
            ->first();

        self::assertSame(
            1,
            $deactivateFormNode->count(),
            'Une règle active doit proposer sa désactivation.'
        );

        $this->client->submit(
            $deactivateFormNode->form()
        );

        self::assertResponseRedirects(
            '/admin/programmationRule/'
        );

        /*
     * ============================================================
     * ASSERT
     * ============================================================
     */

        $this->entityManager->clear();

        $ruleAfter = $this->entityManager
            ->getRepository(ProgrammationRule::class)
            ->find($ruleId);

        self::assertInstanceOf(
            ProgrammationRule::class,
            $ruleAfter
        );

        self::assertFalse(
            $ruleAfter->isActive(),
            'La désactivation manuelle doit bien désactiver la règle.'
        );

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        self::assertNull(
            $draftRepository->find($firstDraftId),
            'La première diffusion future doit disparaître des Drafts.'
        );

        self::assertNull(
            $draftRepository->find($rebroadcastDraftId),
            'La rediffusion future doit disparaître des Drafts.'
        );

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        self::assertNull(
            $pendingRepository->find($pendingId),
            'Le Pending du groupe invalidé doit disparaître.'
        );

        $diffusionRepository = $this->entityManager
            ->getRepository(Diffusion::class);

        $firstDiffusion = $diffusionRepository
            ->find($firstDiffusionId);

        $rebroadcastDiffusion = $diffusionRepository
            ->find($rebroadcastDiffusionId);

        self::assertInstanceOf(
            Diffusion::class,
            $firstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $rebroadcastDiffusion
        );

        self::assertTrue(
            $firstDiffusion->isUnpublished(),
            'La Diffusion future doit être dévalidée avant la purge du Draft.'
        );

        self::assertTrue(
            $rebroadcastDiffusion->isUnpublished(),
            'La rediffusion future publiée doit également être dévalidée.'
        );
    }

    public function testDeactivatingRuleKeepsStartedPublishedGroupFrozen(): void
    {
        /*
     * ============================================================
     * ARRANGE
     * ============================================================
     *
     * Le groupe a déjà commencé :
     *
     * - première Diffusion publiée dans le passé ;
     * - rediffusion publiée dans le futur ;
     * - Pending présent.
     *
     * Dès que la première Diffusion publiée est passée, l'ensemble
     * du groupe devient historique et doit être gelé.
     */

        $admin = $this->createUser('ROLE_ADMIN');
        $category = $this->createCategory();

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setIsActive(true);

        $firstSlot = $this->createWeeklyRuleSlot(
            $rule,
            1,
            2,
            '10:00'
        );

        $rebroadcastSlot = $this->createWeeklyRuleSlot(
            $rule,
            2,
            4,
            '10:00'
        );

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $ruleId = $rule->getId();

        self::assertNotNull($ruleId);

        $firstStartsAt = (new \DateTimeImmutable())
            ->modify('-2 days')
            ->setTime(10, 0);

        $rebroadcastStartsAt = (new \DateTimeImmutable())
            ->modify('+2 days')
            ->setTime(10, 0);

        $emission = $this->createEmission(
            $this->marker . ' historical freeze',
            null,
            $category
        );

        $groupKey = sprintf(
            'rule_%d_origin_%s',
            $ruleId,
            $firstStartsAt->format('Ymd_Hi')
        );

        $firstDraft = $this->createPublishedRegularDraft(
            $emission,
            $firstSlot,
            $firstStartsAt,
            1,
            $groupKey
        );

        $rebroadcastDraft = $this->createPublishedRegularDraft(
            $emission,
            $rebroadcastSlot,
            $rebroadcastStartsAt,
            2,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $rebroadcastDraftId = $rebroadcastDraft->getId();
        $firstDiffusionId = $firstDraft
            ->getPublishedDiffusion()
            ?->getId();
        $rebroadcastDiffusionId = $rebroadcastDraft
            ->getPublishedDiffusion()
            ?->getId();
        $pendingId = $pending->getId();

        self::assertNotNull($firstDraftId);
        self::assertNotNull($rebroadcastDraftId);
        self::assertNotNull($firstDiffusionId);
        self::assertNotNull($rebroadcastDiffusionId);
        self::assertNotNull($pendingId);

        /*
     * ============================================================
     * ACT
     * ============================================================
     */

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/programmationRule/'
        );

        self::assertResponseIsSuccessful();

        $deactivateAction = sprintf(
            '/admin/programmationRule/%d/deactivate',
            $ruleId
        );

        $deactivateFormNode = $crawler
            ->filter(sprintf(
                'form[action="%s"]',
                $deactivateAction
            ))
            ->first();

        self::assertSame(
            1,
            $deactivateFormNode->count(),
            'Une règle active doit proposer sa désactivation.'
        );

        $this->client->submit(
            $deactivateFormNode->form()
        );

        self::assertResponseRedirects(
            '/admin/programmationRule/'
        );

        /*
     * ============================================================
     * ASSERT
     * ============================================================
     */

        $this->entityManager->clear();

        $ruleAfter = $this->entityManager
            ->getRepository(ProgrammationRule::class)
            ->find($ruleId);

        self::assertInstanceOf(
            ProgrammationRule::class,
            $ruleAfter
        );

        self::assertFalse(
            $ruleAfter->isActive(),
            'La règle doit être désactivée même si son groupe historique est gelé.'
        );

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $firstDraftAfter = $draftRepository
            ->find($firstDraftId);

        $rebroadcastDraftAfter = $draftRepository
            ->find($rebroadcastDraftId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $firstDraftAfter,
            'Le Draft de la première diffusion historique doit être conservé.'
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rebroadcastDraftAfter,
            'La rediffusion future du groupe historique doit être conservée.'
        );

        self::assertTrue(
            $firstDraftAfter->isPublished()
        );

        self::assertTrue(
            $rebroadcastDraftAfter->isPublished()
        );

        $diffusionRepository = $this->entityManager
            ->getRepository(Diffusion::class);

        $firstDiffusion = $diffusionRepository
            ->find($firstDiffusionId);

        $rebroadcastDiffusion = $diffusionRepository
            ->find($rebroadcastDiffusionId);

        self::assertInstanceOf(
            Diffusion::class,
            $firstDiffusion
        );

        self::assertInstanceOf(
            Diffusion::class,
            $rebroadcastDiffusion
        );

        self::assertTrue(
            $firstDiffusion->isPublished(),
            'La Diffusion historique doit rester publiée.'
        );

        self::assertTrue(
            $rebroadcastDiffusion->isPublished(),
            'Toute rediffusion future d’un groupe déjà commencé doit rester publiée.'
        );

        $pendingAfter = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->find($pendingId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $pendingAfter,
            'Le Pending d’un groupe historique doit rester intact.'
        );
    }

    public function testMovingRebroadcastOfFuturePublishedGroupUnpublishesWeekAndMovesDraft(): void
    {
        /*
     * ============================================================
     * ARRANGE
     * ============================================================
     *
     * Groupe entièrement futur et publié :
     *
     * - rang 1 publié ;
     * - rang 2 publié ;
     * - Pending présent.
     *
     * On déplace uniquement le créneau du rang 2.
     *
     * Le groupe n'ayant pas encore commencé :
     * - la semaine publiée concernée doit être dévalidée ;
     * - le Draft du rang 2 doit suivre le nouveau créneau ;
     * - le groupe doit rester existant ;
     * - le Pending doit rester existant.
     */

        $admin = $this->createUser('ROLE_ADMIN');
        $category = $this->createCategory();

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setIsActive(true)
            ->setHasAdditionalRebroadcast(true);

        $firstSlot = $this->createWeeklyRuleSlot(
            $rule,
            1,
            2,
            '10:00'
        );

        $rebroadcastSlot = $this->createWeeklyRuleSlot(
            $rule,
            2,
            4,
            '10:00'
        );

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $ruleId = $rule->getId();
        $rebroadcastSlotId = $rebroadcastSlot->getId();

        self::assertNotNull($ruleId);
        self::assertNotNull($rebroadcastSlotId);

        /*
     * On choisit un mardi suffisamment éloigné dans le futur.
     *
     * Le rang 1 est mardi 10h.
     * Le rang 2 est jeudi 10h.
     */
        $firstStartsAt = (new \DateTimeImmutable('next tuesday'))
            ->modify('+21 days')
            ->setTime(10, 0);

        $rebroadcastStartsAt = $firstStartsAt
            ->modify('+2 days');

        $emission = $this->createEmission(
            $this->marker . ' future structural move',
            null,
            $category
        );

        $groupKey = sprintf(
            'rule_%d_origin_%s',
            $ruleId,
            $firstStartsAt->format('Ymd_Hi')
        );

        $firstDraft = $this->createPublishedRegularDraft(
            $emission,
            $firstSlot,
            $firstStartsAt,
            1,
            $groupKey
        );

        $rebroadcastDraft = $this->createPublishedRegularDraft(
            $emission,
            $rebroadcastSlot,
            $rebroadcastStartsAt,
            2,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $rebroadcastDraftId = $rebroadcastDraft->getId();

        $firstDiffusionId = $firstDraft
            ->getPublishedDiffusion()
            ?->getId();

        $rebroadcastDiffusionId = $rebroadcastDraft
            ->getPublishedDiffusion()
            ?->getId();

        $pendingId = $pending->getId();

        self::assertNotNull($firstDraftId);
        self::assertNotNull($rebroadcastDraftId);
        self::assertNotNull($firstDiffusionId);
        self::assertNotNull($rebroadcastDiffusionId);
        self::assertNotNull($pendingId);

        /*
     * ============================================================
     * ACT
     * ============================================================
     *
     * On déplace le rang 2 de jeudi 10h vers jeudi 12h.
     */

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            sprintf(
                '/admin/programmationRule/%d/slots/%d/edit',
                $ruleId,
                $rebroadcastSlotId
            )
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form([
                'programmation_rule_slot[recurrenceType]' =>
                ProgrammationRuleSlot::RECURRENCE_WEEKLY,

                'programmation_rule_slot[monthlyOccurrence]' => '',
                'programmation_rule_slot[monthInterval]' => '1',
                'programmation_rule_slot[dayOfWeek]' => '4',

                'programmation_rule_slot[startTime][hour]' => '12',
                'programmation_rule_slot[startTime][minute]' => '0',

                'programmation_rule_slot[durationMinutes]' => '60',
                'programmation_rule_slot[broadcastRank]' => '2',
                'programmation_rule_slot[weekOffset]' => '0',
                'programmation_rule_slot[weekParity]' => '',

                'programmation_rule_slot[isActive]' => '1',
            ]);

        $this->client->submit($form);

        self::assertResponseRedirects(
            sprintf(
                '/admin/programmationRule/%d/slots/',
                $ruleId
            )
        );

        /*
     * ============================================================
     * ASSERT
     * ============================================================
     */

        $this->entityManager->clear();

        $ruleAfter = $this->entityManager
            ->getRepository(ProgrammationRule::class)
            ->find($ruleId);

        self::assertInstanceOf(
            ProgrammationRule::class,
            $ruleAfter
        );

        self::assertTrue(
            $ruleAfter->isActive(),
            'Un déplacement structurel sans conflit ne doit pas désactiver la règle.'
        );

        $slotAfter = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rebroadcastSlotId);

        self::assertInstanceOf(
            ProgrammationRuleSlot::class,
            $slotAfter
        );

        self::assertSame(
            '12:00',
            $slotAfter->getStartTime()?->format('H:i'),
            'Le créneau de rediffusion doit être déplacé.'
        );

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $firstDraftAfter = $draftRepository
            ->find($firstDraftId);

        $rebroadcastDraftAfter = $draftRepository
            ->find($rebroadcastDraftId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $firstDraftAfter,
            'La première diffusion du groupe doit être conservée.'
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rebroadcastDraftAfter,
            'La rediffusion doit rester dans le groupe.'
        );

        self::assertSame(
            $groupKey,
            $firstDraftAfter->getAssignmentGroupKey()
        );

        self::assertSame(
            $groupKey,
            $rebroadcastDraftAfter->getAssignmentGroupKey()
        );

        self::assertSame(
            $firstStartsAt->format('Y-m-d H:i:s'),
            $firstDraftAfter
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s'),
            'Le rang 1 ne doit pas être déplacé.'
        );

        self::assertSame(
            $rebroadcastStartsAt
                ->setTime(12, 0)
                ->format('Y-m-d H:i:s'),
            $rebroadcastDraftAfter
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s'),
            'Le Draft du rang 2 doit suivre le nouveau créneau.'
        );

        /*
     * La semaine publiée a été dévalidée.
     *
     * Les Drafts doivent donc être revenus dans un état modifiable.
     */
        self::assertTrue(
            $firstDraftAfter->isDraft(),
            'Le rang 1 doit redevenir modifiable après dévalidation de la semaine.'
        );

        self::assertTrue(
            $rebroadcastDraftAfter->isDraft(),
            'Le rang 2 doit redevenir modifiable après dévalidation de la semaine.'
        );

        /*
     * Les Diffusion ne sont pas supprimées ni déplacées silencieusement.
     * Elles sont conservées comme anciennes publications mais passent
     * au statut unpublished.
     */

        $diffusionRepository = $this->entityManager
            ->getRepository(Diffusion::class);

        $firstDiffusionAfter = $diffusionRepository
            ->find($firstDiffusionId);

        $rebroadcastDiffusionAfter = $diffusionRepository
            ->find($rebroadcastDiffusionId);

        self::assertInstanceOf(
            Diffusion::class,
            $firstDiffusionAfter
        );

        self::assertInstanceOf(
            Diffusion::class,
            $rebroadcastDiffusionAfter
        );

        self::assertTrue(
            $firstDiffusionAfter->isUnpublished(),
            'La première Diffusion de la semaine doit être dévalidée.'
        );

        self::assertTrue(
            $rebroadcastDiffusionAfter->isUnpublished(),
            'La rediffusion publiée doit être dévalidée.'
        );

        self::assertSame(
            $rebroadcastStartsAt->format('Y-m-d H:i:s'),
            $rebroadcastDiffusionAfter
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s'),
            'La Diffusion publiée ne doit jamais être déplacée silencieusement.'
        );

        /*
     * Le changement d'un créneau de rediffusion ne détruit pas
     * les Pending du groupe.
     */

        $pendingAfter = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->find($pendingId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $pendingAfter,
            'Le Pending doit être conservé lorsque la première diffusion existe toujours.'
        );

        self::assertSame(
            $groupKey,
            $pendingAfter->getAssignmentGroupKey()
        );
    }

    public function testMovingRebroadcastOfStartedPublishedGroupKeepsProgrammingFrozen(): void
    {
        /*
     * ============================================================
     * ARRANGE
     * ============================================================
     *
     * Groupe historique :
     *
     * - rang 1 publié dans le passé ;
     * - rang 2 publié dans le futur ;
     * - Pending présent.
     *
     * L'administrateur modifie bien la définition de la règle,
     * mais la programmation existante du groupe doit rester gelée.
     */

        $admin = $this->createUser('ROLE_ADMIN');
        $category = $this->createCategory();

        $rule = (new ProgrammationRule())
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setIsActive(true);

        $firstSlot = $this->createWeeklyRuleSlot(
            $rule,
            1,
            2,
            '10:00'
        );

        $rebroadcastSlot = $this->createWeeklyRuleSlot(
            $rule,
            2,
            4,
            '10:00'
        );

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        $ruleId = $rule->getId();
        $rebroadcastSlotId = $rebroadcastSlot->getId();

        self::assertNotNull($ruleId);
        self::assertNotNull($rebroadcastSlotId);

        /*
     * La première diffusion est déjà passée.
     * La rediffusion, elle, est encore à venir.
     */
        $firstStartsAt = (new \DateTimeImmutable())
            ->modify('-2 days')
            ->setTime(10, 0);

        $rebroadcastStartsAt = (new \DateTimeImmutable())
            ->modify('+2 days')
            ->setTime(10, 0);

        $emission = $this->createEmission(
            $this->marker . ' historical structural freeze',
            null,
            $category
        );

        $groupKey = sprintf(
            'rule_%d_origin_%s',
            $ruleId,
            $firstStartsAt->format('Ymd_Hi')
        );

        $firstDraft = $this->createPublishedRegularDraft(
            $emission,
            $firstSlot,
            $firstStartsAt,
            1,
            $groupKey
        );

        $rebroadcastDraft = $this->createPublishedRegularDraft(
            $emission,
            $rebroadcastSlot,
            $rebroadcastStartsAt,
            2,
            $groupKey
        );

        $pending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $firstDraftId = $firstDraft->getId();
        $rebroadcastDraftId = $rebroadcastDraft->getId();

        $firstDiffusionId = $firstDraft
            ->getPublishedDiffusion()
            ?->getId();

        $rebroadcastDiffusionId = $rebroadcastDraft
            ->getPublishedDiffusion()
            ?->getId();

        $pendingId = $pending->getId();

        self::assertNotNull($firstDraftId);
        self::assertNotNull($rebroadcastDraftId);
        self::assertNotNull($firstDiffusionId);
        self::assertNotNull($rebroadcastDiffusionId);
        self::assertNotNull($pendingId);

        /*
     * ============================================================
     * ACT
     * ============================================================
     *
     * La règle passe bien de jeudi 10h à jeudi 12h.
     */

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            sprintf(
                '/admin/programmationRule/%d/slots/%d/edit',
                $ruleId,
                $rebroadcastSlotId
            )
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form([
                'programmation_rule_slot[recurrenceType]' =>
                ProgrammationRuleSlot::RECURRENCE_WEEKLY,

                'programmation_rule_slot[monthlyOccurrence]' => '',
                'programmation_rule_slot[monthInterval]' => '1',
                'programmation_rule_slot[dayOfWeek]' => '4',

                'programmation_rule_slot[startTime][hour]' => '12',
                'programmation_rule_slot[startTime][minute]' => '0',

                'programmation_rule_slot[durationMinutes]' => '60',
                'programmation_rule_slot[broadcastRank]' => '2',
                'programmation_rule_slot[weekOffset]' => '0',
                'programmation_rule_slot[weekParity]' => '',

                'programmation_rule_slot[isActive]' => '1',
            ]);

        $this->client->submit($form);

        self::assertResponseRedirects(
            sprintf(
                '/admin/programmationRule/%d/slots/',
                $ruleId
            )
        );

        /*
     * ============================================================
     * ASSERT
     * ============================================================
     */

        $this->entityManager->clear();

        /*
     * La définition structurelle de la règle, elle, est bien modifiée.
     * Le gel concerne le groupe déjà commencé, pas la règle elle-même.
     */

        $slotAfter = $this->entityManager
            ->getRepository(ProgrammationRuleSlot::class)
            ->find($rebroadcastSlotId);

        self::assertInstanceOf(
            ProgrammationRuleSlot::class,
            $slotAfter
        );

        self::assertSame(
            '12:00',
            $slotAfter->getStartTime()?->format('H:i'),
            'La nouvelle définition de la règle doit être enregistrée.'
        );

        /*
     * En revanche, le groupe déjà commencé reste exactement dans
     * son état publié antérieur.
     */

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $firstDraftAfter = $draftRepository
            ->find($firstDraftId);

        $rebroadcastDraftAfter = $draftRepository
            ->find($rebroadcastDraftId);

        self::assertInstanceOf(
            DiffusionDraft::class,
            $firstDraftAfter
        );

        self::assertInstanceOf(
            DiffusionDraft::class,
            $rebroadcastDraftAfter
        );

        self::assertTrue(
            $firstDraftAfter->isPublished(),
            'Le rang 1 historique doit rester publié.'
        );

        self::assertTrue(
            $rebroadcastDraftAfter->isPublished(),
            'La rediffusion future du groupe historique doit rester publiée.'
        );

        self::assertSame(
            $firstStartsAt->format('Y-m-d H:i:s'),
            $firstDraftAfter
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            $rebroadcastStartsAt->format('Y-m-d H:i:s'),
            $rebroadcastDraftAfter
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s'),
            'Le Draft historique ne doit pas suivre la nouvelle définition de la règle.'
        );

        /*
     * Les Diffusion publiées restent elles aussi totalement intactes.
     */

        $diffusionRepository = $this->entityManager
            ->getRepository(Diffusion::class);

        $firstDiffusionAfter = $diffusionRepository
            ->find($firstDiffusionId);

        $rebroadcastDiffusionAfter = $diffusionRepository
            ->find($rebroadcastDiffusionId);

        self::assertInstanceOf(
            Diffusion::class,
            $firstDiffusionAfter
        );

        self::assertInstanceOf(
            Diffusion::class,
            $rebroadcastDiffusionAfter
        );

        self::assertTrue(
            $firstDiffusionAfter->isPublished(),
            'La première Diffusion historique doit rester publiée.'
        );

        self::assertTrue(
            $rebroadcastDiffusionAfter->isPublished(),
            'La rediffusion future du groupe historique doit rester publiée.'
        );

        self::assertSame(
            $firstStartsAt->format('Y-m-d H:i:s'),
            $firstDiffusionAfter
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            $rebroadcastStartsAt->format('Y-m-d H:i:s'),
            $rebroadcastDiffusionAfter
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s'),
            'La Diffusion historique ne doit pas être déplacée.'
        );

        /*
     * Enfin, le Pending appartient toujours à un groupe valide :
     * il doit rester dans le Parc.
     */

        $pendingAfter = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->find($pendingId);

        self::assertInstanceOf(
            PendingRebroadcast::class,
            $pendingAfter,
            'Le Pending du groupe historique doit rester intact.'
        );

        self::assertSame(
            $groupKey,
            $pendingAfter->getAssignmentGroupKey()
        );
    }

    /*
 * ============================================================
 * HELPERS — PROGRAMMATION PUBLIÉE
 * ============================================================
 */

    private function createWeeklyRuleSlot(
        ProgrammationRule $rule,
        int $broadcastRank,
        int $dayOfWeek,
        string $startTime
    ): ProgrammationRuleSlot {
        $slot = (new ProgrammationRuleSlot())
            ->setRecurrenceType(
                ProgrammationRuleSlot::RECURRENCE_WEEKLY
            )
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(
                new \DateTimeImmutable($startTime)
            )
            ->setDurationMinutes(60)
            ->setBroadcastRank($broadcastRank)
            ->setWeekOffset(0)
            ->setWeekParity(null)
            ->setIsActive(true);

        $rule->addSlot($slot);

        return $slot;
    }

    private function createPublishedRegularDraft(
        \App\Entity\Emission $emission,
        ProgrammationRuleSlot $slot,
        \DateTimeImmutable $startsAt,
        int $broadcastRank,
        string $groupKey
    ): DiffusionDraft {
        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setNombreDiffusion($broadcastRank)
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                $startsAt,
                60
            )
            ->markAsPublished();

        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setSlot($slot)
            ->setNombreDiffusion($broadcastRank)
            ->setDraftType(
                DiffusionDraft::TYPE_REGULAR
            )
            ->setAssignmentGroupKey($groupKey)
            ->setSchedule(
                $startsAt,
                60
            );

        $this->entityManager->persist($diffusion);

        /*
     * Il faut que la Diffusion possède son ID avant de créer
     * explicitement le lien de publication du Draft.
     */
        $this->entityManager->flush();

        $draft->markAsPublished(
            $diffusion,
            new \DateTimeImmutable()
        );

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }
}

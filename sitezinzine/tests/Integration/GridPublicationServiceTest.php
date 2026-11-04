<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Diffusion;
use App\Entity\DiffusionDraft;
use App\Entity\Emission;
use App\Service\GridPublicationService;
use App\Tests\Support\DoctrineTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GridPublicationServiceTest extends KernelTestCase
{
    use DoctrineTestTrait;

    private GridPublicationService $publicationService;

    protected function setUp(): void
    {
        $this->bootDoctrine();

        $this->publicationService = static::getContainer()
            ->get(GridPublicationService::class);
    }

    protected function tearDown(): void
    {
        $this->closeEntityManager();

        parent::tearDown();
    }

    public function testPublishWeekCreatesDiffusionFromNewDraft(): void
    {
        /*
         * On utilise une date volontairement très éloignée des données
         * réelles afin que le test soit indépendant du contenu existant
         * de la base de test.
         *
         * La semaine radio correspond ici à :
         * mardi 06/01/2099 -> lundi 12/01/2099.
         */
        $startsAt = new \DateTimeImmutable('2099-01-08 14:30:00');

        $emission = (new Emission())
            ->setTitre('Émission PHPUnit - publication')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-01-08 12:00:00'))
            ->setRef('PHPUNIT')
            ->setDuree(60)
            ->setDescriptif('Émission créée pour le test de GridPublicationService.');

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        /*
         * GridPublicationService exige une émission déjà persistée,
         * donc possédant un ID.
         */
        self::assertNotNull($emission->getId());

        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(1)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setSchedule($startsAt, 60)
            ->setAssignmentGroupKey('phpunit_grid_publication_create');

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        self::assertNotNull($draft->getId());
        self::assertTrue($draft->isDraft());
        self::assertNull($draft->getPublishedDiffusion());

        /*
         * On passe volontairement une date située au milieu de la
         * semaine. Le service doit lui-même retrouver le mardi de début
         * de semaine radio.
         */
        $result = $this->publicationService->publishWeek(
            new \DateTimeImmutable('2099-01-08 00:00:00')
        );

        self::assertTrue($result['published']);

        self::assertSame(
            '2099-01-06 00:00:00',
            $result['weekStart']->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-01-13 00:00:00',
            $result['weekEnd']->format('Y-m-d H:i:s')
        );

        self::assertSame(1, $result['createdCount']);
        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['publishedDraftCount']);

        /*
         * On vide l'EntityManager pour forcer Doctrine à relire
         * réellement les données depuis MySQL.
         */
        $emissionId = $emission->getId();
        $draftId = $draft->getId();

        $this->clearEntityManager();

        /** @var DiffusionDraft|null $reloadedDraft */
        $reloadedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        self::assertNotNull($reloadedDraft);
        self::assertTrue($reloadedDraft->isPublished());
        self::assertNotNull($reloadedDraft->getPublishedAt());
        self::assertNotNull($reloadedDraft->getPublishedDiffusion());

        $diffusion = $reloadedDraft->getPublishedDiffusion();

        self::assertInstanceOf(Diffusion::class, $diffusion);
        self::assertNotNull($diffusion->getId());

        /*
         * La Diffusion publiée doit être une copie fidèle du Draft.
         */
        self::assertNotNull($diffusion->getEmission());
        self::assertSame(
            $emissionId,
            $diffusion->getEmission()->getId()
        );

        self::assertSame(
            '2099-01-08 14:30:00',
            $diffusion->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        self::assertSame(1, $diffusion->getNombreDiffusion());
        self::assertSame(60, $diffusion->getDurationMinutes());

        self::assertSame(
            '2099-01-08 15:30:00',
            $diffusion->getEndsAt()?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            'phpunit_grid_publication_create',
            $diffusion->getAssignmentGroupKey()
        );

        /*
         * Nettoyage des données créées par CE test uniquement.
         *
         * On supprime d'abord le Draft car il référence la Diffusion.
         */
        $this->entityManager->remove($reloadedDraft);
        $this->entityManager->flush();

        $this->entityManager->remove($diffusion);
        $this->entityManager->flush();

        $reloadedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        self::assertNotNull($reloadedEmission);

        $this->entityManager->remove($reloadedEmission);
        $this->entityManager->flush();
    }

    public function testRepublishWeekUpdatesExistingLinkedDiffusionInsteadOfCreatingNewOne(): void
    {
        $startsAt = new \DateTimeImmutable('2099-02-05 18:00:00');

        $emission = (new Emission())
            ->setTitre('Émission PHPUnit - revalidation')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-02-05 12:00:00'))
            ->setRef('PHPUNIT')
            ->setDuree(90)
            ->setDescriptif('Émission créée pour tester une revalidation.');

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        self::assertNotNull($emission->getId());

        $diffusion = (new Diffusion())
            ->setEmission($emission)
            ->setHoraireDiffusion(
                new \DateTime('2099-02-05 17:00:00')
            )
            ->setNombreDiffusion(1)
            ->setDurationMinutes(60)
            ->setEndsAt(
                new \DateTime('2099-02-05 18:00:00')
            )
            ->setAssignmentGroupKey('ancien_groupe')
            ->markAsUnpublished();

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        self::assertNotNull($diffusion->getId());

        $originalDiffusionId = $diffusion->getId();

        $draft = (new DiffusionDraft())
            ->setEmission($emission)
            ->setNombreDiffusion(2)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setSchedule($startsAt, 90)
            ->setAssignmentGroupKey('phpunit_grid_republication')
            ->setPublishedDiffusion($diffusion)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
            ->setPublishedAt(null);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        self::assertNotNull($draft->getId());
        self::assertTrue($draft->isDraft());
        self::assertSame(
            $originalDiffusionId,
            $draft->getPublishedDiffusion()?->getId()
        );

        $result = $this->publicationService->publishWeek(
            new \DateTimeImmutable('2099-02-05 00:00:00')
        );

        self::assertTrue($result['published']);
        self::assertSame(0, $result['createdCount']);
        self::assertSame(1, $result['updatedCount']);
        self::assertSame(1, $result['publishedDraftCount']);

        $emissionId = $emission->getId();
        $draftId = $draft->getId();

        $this->clearEntityManager();

        /** @var DiffusionDraft|null $reloadedDraft */
        $reloadedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        self::assertNotNull($reloadedDraft);
        self::assertTrue($reloadedDraft->isPublished());
        self::assertNotNull($reloadedDraft->getPublishedDiffusion());

        $reloadedDiffusion = $reloadedDraft->getPublishedDiffusion();

        self::assertInstanceOf(Diffusion::class, $reloadedDiffusion);

        /*
     * Point principal du test :
     * la même Diffusion doit être réutilisée.
     */
        self::assertSame(
            $originalDiffusionId,
            $reloadedDiffusion->getId()
        );

        self::assertNotNull($reloadedDiffusion->getEmission());
        self::assertSame(
            $emissionId,
            $reloadedDiffusion->getEmission()->getId()
        );

        /*
     * Les nouvelles valeurs du Draft doivent remplacer
     * l'ancien contenu de la Diffusion.
     */
        self::assertSame(
            '2099-02-05 18:00:00',
            $reloadedDiffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            2,
            $reloadedDiffusion->getNombreDiffusion()
        );

        self::assertSame(
            90,
            $reloadedDiffusion->getDurationMinutes()
        );

        self::assertSame(
            '2099-02-05 19:30:00',
            $reloadedDiffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            'phpunit_grid_republication',
            $reloadedDiffusion->getAssignmentGroupKey()
        );

        /*
     * Vérification supplémentaire :
     * une seule Diffusion doit exister pour cet ID logique,
     * aucune nouvelle ligne ne doit avoir été créée.
     */
        $diffusions = $this->entityManager
            ->getRepository(Diffusion::class)
            ->findBy([
                'emission' => $emissionId,
            ]);

        $matchingDiffusions = array_filter(
            $diffusions,
            static fn(Diffusion $candidate): bool =>
            $candidate->getHoraireDiffusion()?->format('Y-m-d H:i:s')
                === '2099-02-05 18:00:00'
        );

        self::assertCount(1, $matchingDiffusions);

        /*
     * Nettoyage.
     */
        $this->entityManager->remove($reloadedDraft);
        $this->entityManager->flush();

        $this->entityManager->remove($reloadedDiffusion);
        $this->entityManager->flush();

        $reloadedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        self::assertNotNull($reloadedEmission);

        $this->entityManager->remove($reloadedEmission);
        $this->entityManager->flush();
    }

    public function testPublishWeekReusesUnreservedDiffusionAtSameTime(): void
    {
        $startsAt = new \DateTimeImmutable('2099-03-12 19:00:00');

        /*
     * Ancienne émission correspondant à l'ancienne grille validée.
     */
        $oldEmission = (new Emission())
            ->setTitre('Ancienne émission PHPUnit')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-03-12 10:00:00'))
            ->setRef('PHPUNIT-OLD')
            ->setDuree(60)
            ->setDescriptif('Ancienne émission du test.');

        /*
     * Nouvelle émission placée dans le Draft après dévalidation.
     */
        $newEmission = (new Emission())
            ->setTitre('Nouvelle émission PHPUnit')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-03-12 11:00:00'))
            ->setRef('PHPUNIT-NEW')
            ->setDuree(60)
            ->setDescriptif('Nouvelle émission du test.');

        $this->entityManager->persist($oldEmission);
        $this->entityManager->persist($newEmission);
        $this->entityManager->flush();

        self::assertNotNull($oldEmission->getId());
        self::assertNotNull($newEmission->getId());

        /*
     * Une ancienne Diffusion existe déjà à 19 h,
     * mais elle n'est revendiquée par aucun Draft.
     */
        $oldDiffusion = (new Diffusion())
            ->setEmission($oldEmission)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('ancien_groupe_phpunit')
            ->markAsUnpublished()
            ->setSchedule(
                new \DateTime('2099-03-12 19:00:00'),
                60
            );

        $this->entityManager->persist($oldDiffusion);
        $this->entityManager->flush();

        self::assertNotNull($oldDiffusion->getId());

        $oldDiffusionId = $oldDiffusion->getId();

        /*
     * Le nouveau Draft occupe exactement le même horaire,
     * mais ne possède PAS de publishedDiffusion.
     */
        $draft = (new DiffusionDraft())
            ->setEmission($newEmission)
            ->setNombreDiffusion(1)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setSchedule($startsAt, 60)
            ->setAssignmentGroupKey('nouveau_groupe_phpunit');

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        self::assertNotNull($draft->getId());
        self::assertTrue($draft->isDraft());
        self::assertNull($draft->getPublishedDiffusion());

        $draftId = $draft->getId();
        $newEmissionId = $newEmission->getId();

        /*
     * Publication de la semaine.
     */
        $result = $this->publicationService->publishWeek(
            new \DateTimeImmutable('2099-03-12 00:00:00')
        );

        self::assertTrue($result['published']);

        /*
     * L'ancienne Diffusion doit être réutilisée :
     * aucune création.
     */
        self::assertSame(0, $result['createdCount']);
        self::assertSame(1, $result['updatedCount']);
        self::assertSame(1, $result['publishedDraftCount']);

        $this->clearEntityManager();

        /** @var DiffusionDraft|null $reloadedDraft */
        $reloadedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        self::assertNotNull($reloadedDraft);
        self::assertTrue($reloadedDraft->isPublished());
        self::assertNotNull($reloadedDraft->getPublishedDiffusion());

        $reusedDiffusion = $reloadedDraft->getPublishedDiffusion();

        /*
     * C'est bien exactement l'ancienne ligne Diffusion.
     */
        self::assertSame(
            $oldDiffusionId,
            $reusedDiffusion->getId()
        );

        /*
     * Mais son contenu correspond maintenant au Draft,
     * puisque DiffusionDraft est la source de vérité.
     */
        self::assertNotNull($reusedDiffusion->getEmission());

        self::assertSame(
            $newEmissionId,
            $reusedDiffusion->getEmission()->getId()
        );

        self::assertSame(
            '2099-03-12 19:00:00',
            $reusedDiffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-03-12 20:00:00',
            $reusedDiffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            60,
            $reusedDiffusion->getDurationMinutes()
        );

        self::assertSame(
            1,
            $reusedDiffusion->getNombreDiffusion()
        );

        self::assertSame(
            'nouveau_groupe_phpunit',
            $reusedDiffusion->getAssignmentGroupKey()
        );

        self::assertTrue($reusedDiffusion->isPublished());
    }

    public function testPublishWeekIsBlockedWhenDiffusionIsReservedByAnotherDraft(): void
    {
        $startsAt = new \DateTimeImmutable('2099-04-09 19:00:00');

        $emissionA = (new Emission())
            ->setTitre('Émission PHPUnit - réservation A')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-04-09 10:00:00'))
            ->setRef('PHPUNIT-RES-A')
            ->setDuree(60)
            ->setDescriptif('Émission A du test de réservation.');

        $emissionB = (new Emission())
            ->setTitre('Émission PHPUnit - réservation B')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-04-09 11:00:00'))
            ->setRef('PHPUNIT-RES-B')
            ->setDuree(60)
            ->setDescriptif('Émission B du test de réservation.');

        $this->entityManager->persist($emissionA);
        $this->entityManager->persist($emissionB);
        $this->entityManager->flush();

        self::assertNotNull($emissionA->getId());
        self::assertNotNull($emissionB->getId());

        /*
     * Diffusion historique conservée après dévalidation.
     */
        $diffusion = (new Diffusion())
            ->setEmission($emissionA)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('phpunit_reserved_diffusion')
            ->markAsUnpublished()
            ->setSchedule(
                new \DateTime('2099-04-09 19:00:00'),
                60
            );

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        self::assertNotNull($diffusion->getId());

        $diffusionId = $diffusion->getId();

        /*
     * Draft A possède officiellement cette Diffusion historique.
     *
     * Il est revenu en statut draft après dévalidation,
     * mais publishedDiffusion est volontairement conservée.
     */
        $draftA = (new DiffusionDraft())
            ->setEmission($emissionA)
            ->setNombreDiffusion(1)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setSchedule($startsAt, 60)
            ->setAssignmentGroupKey('phpunit_reserved_diffusion')
            ->setPublishedDiffusion($diffusion)
            ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
            ->setPublishedAt(null);

        /*
     * Draft B tente d'occuper exactement le même horaire,
     * mais ne possède aucune publishedDiffusion.
     */
        $draftB = (new DiffusionDraft())
            ->setEmission($emissionB)
            ->setNombreDiffusion(1)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
            ->setSchedule($startsAt, 60)
            ->setAssignmentGroupKey('phpunit_competing_draft');

        $this->entityManager->persist($draftA);
        $this->entityManager->persist($draftB);
        $this->entityManager->flush();

        self::assertNotNull($draftA->getId());
        self::assertNotNull($draftB->getId());

        self::assertSame(
            $diffusionId,
            $draftA->getPublishedDiffusion()?->getId()
        );

        self::assertNull($draftB->getPublishedDiffusion());

        /*
     * La preview doit détecter le conflit avant toute écriture.
     */
        $preview = $this->publicationService->previewWeekPublication(
            new \DateTimeImmutable('2099-04-09 00:00:00')
        );

        self::assertSame(2, $preview['publishableDraftCount']);
        self::assertTrue($preview['hasBlockingConflicts']);
        self::assertFalse($preview['canPublish']);

        self::assertSame(1, $preview['conflictCount']);

        self::assertNotEmpty($preview['conflicts']);

        self::assertSame(
            'diffusion_reserved_by_other_draft',
            $preview['conflicts'][0]['type']
        );

        /*
     * La publication réelle doit être refusée.
     */
        try {
            $this->publicationService->publishWeek(
                new \DateTimeImmutable('2099-04-09 00:00:00')
            );

            self::fail(
                'La publication aurait dû être bloquée par le conflit.'
            );
        } catch (\DomainException $exception) {
            self::assertSame(
                'La validation est bloquée par un ou plusieurs conflits.',
                $exception->getMessage()
            );
        }

        /*
     * On vide uniquement l'EntityManager pour relire la BDD.
     * La transaction du test reste active.
     */
        $draftAId = $draftA->getId();
        $draftBId = $draftB->getId();

        $this->clearEntityManager();

        /** @var Diffusion|null $reloadedDiffusion */
        $reloadedDiffusion = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        /** @var DiffusionDraft|null $reloadedDraftA */
        $reloadedDraftA = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftAId);

        /** @var DiffusionDraft|null $reloadedDraftB */
        $reloadedDraftB = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftBId);

        self::assertNotNull($reloadedDiffusion);
        self::assertNotNull($reloadedDraftA);
        self::assertNotNull($reloadedDraftB);

        /*
     * Rien ne doit avoir été publié ou modifié.
     */
        self::assertTrue($reloadedDiffusion->isUnpublished());

        self::assertSame(
            $emissionA->getId(),
            $reloadedDiffusion->getEmission()?->getId()
        );

        self::assertSame(
            '2099-04-09 19:00:00',
            $reloadedDiffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertTrue($reloadedDraftA->isDraft());
        self::assertTrue($reloadedDraftB->isDraft());

        self::assertSame(
            $diffusionId,
            $reloadedDraftA->getPublishedDiffusion()?->getId()
        );

        self::assertNull(
            $reloadedDraftB->getPublishedDiffusion()
        );
    }

    public function testPublishWeekCreatesNewDiffusionWhenSeveralReusableDiffusionsExistAtSameTime(): void
    {
        $startsAt = new \DateTimeImmutable('2099-05-14 20:00:00');

        $oldEmissionA = (new Emission())
            ->setTitre('Ancienne émission PHPUnit A')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-05-14 10:00:00'))
            ->setRef('PHPUNIT-MULTI-OLD-A')
            ->setDuree(60)
            ->setDescriptif('Ancienne émission A.');

        $oldEmissionB = (new Emission())
            ->setTitre('Ancienne émission PHPUnit B')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-05-14 11:00:00'))
            ->setRef('PHPUNIT-MULTI-OLD-B')
            ->setDuree(60)
            ->setDescriptif('Ancienne émission B.');

        $newEmission = (new Emission())
            ->setTitre('Nouvelle émission PHPUnit')
            ->setKeyword('')
            ->setDatepub(new \DateTime('2099-05-14 12:00:00'))
            ->setRef('PHPUNIT-MULTI-NEW')
            ->setDuree(90)
            ->setDescriptif('Nouvelle émission du Draft.');

        $this->entityManager->persist($oldEmissionA);
        $this->entityManager->persist($oldEmissionB);
        $this->entityManager->persist($newEmission);
        $this->entityManager->flush();

        self::assertNotNull($oldEmissionA->getId());
        self::assertNotNull($oldEmissionB->getId());
        self::assertNotNull($newEmission->getId());

        /*
     * Deux anciennes Diffusion existent au même horaire.
     * Aucune n'est liée à un Draft publiable.
     */
        $oldDiffusionA = (new Diffusion())
            ->setEmission($oldEmissionA)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('phpunit_multi_old_a')
            ->markAsUnpublished()
            ->setSchedule(
                new \DateTime('2099-05-14 20:00:00'),
                60
            );

        $oldDiffusionB = (new Diffusion())
            ->setEmission($oldEmissionB)
            ->setNombreDiffusion(1)
            ->setAssignmentGroupKey('phpunit_multi_old_b')
            ->markAsUnpublished()
            ->setSchedule(
                new \DateTime('2099-05-14 20:00:00'),
                60
            );

        $this->entityManager->persist($oldDiffusionA);
        $this->entityManager->persist($oldDiffusionB);
        $this->entityManager->flush();

        self::assertNotNull($oldDiffusionA->getId());
        self::assertNotNull($oldDiffusionB->getId());

        $oldDiffusionAId = $oldDiffusionA->getId();
        $oldDiffusionBId = $oldDiffusionB->getId();

        /*
     * Nouveau Draft au même horaire, sans publishedDiffusion.
     */
        $draft = (new DiffusionDraft())
            ->setEmission($newEmission)
            ->setNombreDiffusion(2)
            ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
            ->setSchedule($startsAt, 90)
            ->setAssignmentGroupKey('phpunit_multi_new');

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        self::assertNotNull($draft->getId());
        self::assertTrue($draft->isDraft());
        self::assertNull($draft->getPublishedDiffusion());

        $draftId = $draft->getId();
        $newEmissionId = $newEmission->getId();

        /*
     * La preview doit décider de créer une nouvelle Diffusion.
     */
        $preview = $this->publicationService->previewWeekPublication(
            new \DateTimeImmutable('2099-05-14 00:00:00')
        );

        self::assertTrue($preview['canPublish']);
        self::assertFalse($preview['hasBlockingConflicts']);

        self::assertSame(1, $preview['publishableDraftCount']);
        self::assertSame(1, $preview['createCount']);
        self::assertSame(0, $preview['updateCount']);
        self::assertSame(0, $preview['conflictCount']);

        /*
     * Publication réelle.
     */
        $result = $this->publicationService->publishWeek(
            new \DateTimeImmutable('2099-05-14 00:00:00')
        );

        self::assertTrue($result['published']);
        self::assertSame(1, $result['createdCount']);
        self::assertSame(0, $result['updatedCount']);
        self::assertSame(1, $result['publishedDraftCount']);

        $this->clearEntityManager();

        /** @var DiffusionDraft|null $reloadedDraft */
        $reloadedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        self::assertNotNull($reloadedDraft);
        self::assertTrue($reloadedDraft->isPublished());
        self::assertNotNull($reloadedDraft->getPublishedDiffusion());

        $newDiffusion = $reloadedDraft->getPublishedDiffusion();

        self::assertInstanceOf(Diffusion::class, $newDiffusion);
        self::assertNotNull($newDiffusion->getId());

        /*
     * Point essentiel :
     * aucune des deux anciennes lignes ne doit avoir été choisie.
     */
        self::assertNotSame(
            $oldDiffusionAId,
            $newDiffusion->getId()
        );

        self::assertNotSame(
            $oldDiffusionBId,
            $newDiffusion->getId()
        );

        self::assertNotNull($newDiffusion->getEmission());

        self::assertSame(
            $newEmissionId,
            $newDiffusion->getEmission()->getId()
        );

        self::assertSame(
            '2099-05-14 20:00:00',
            $newDiffusion
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2099-05-14 21:30:00',
            $newDiffusion
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        self::assertSame(
            90,
            $newDiffusion->getDurationMinutes()
        );

        self::assertSame(
            2,
            $newDiffusion->getNombreDiffusion()
        );

        self::assertSame(
            'phpunit_multi_new',
            $newDiffusion->getAssignmentGroupKey()
        );

        self::assertTrue($newDiffusion->isPublished());

        /*
        * Les deux anciennes Diffusion doivent être restées intactes
        * et non publiées.
        */
        /** @var Diffusion|null $reloadedOldDiffusionA */
        $reloadedOldDiffusionA = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($oldDiffusionAId);

        /** @var Diffusion|null $reloadedOldDiffusionB */
        $reloadedOldDiffusionB = $this->entityManager
            ->getRepository(Diffusion::class)
            ->find($oldDiffusionBId);

        self::assertNotNull($reloadedOldDiffusionA);
        self::assertNotNull($reloadedOldDiffusionB);

        self::assertTrue($reloadedOldDiffusionA->isUnpublished());
        self::assertTrue($reloadedOldDiffusionB->isUnpublished());

        self::assertSame(
            $oldEmissionA->getId(),
            $reloadedOldDiffusionA->getEmission()?->getId()
        );

        self::assertSame(
            $oldEmissionB->getId(),
            $reloadedOldDiffusionB->getEmission()?->getId()
        );
    }

    public function testRepublishWeekMovesLinkedDiffusionAndPublishesNewDraftAtFreedTime(): void
{
    /*
     * Semaine radio : mardi 20/10/2099 au lundi 26/10/2099.
     * Scénario : ancienne diffusion à 17 h, déplacée à 14 h
     * après dévalidation, puis nouvelle émission à 17 h.
     */
    $weekStart = new \DateTimeImmutable('2099-10-20 00:00:00');

    $movedEmission = (new Emission())
        ->setTitre('PHPUnit - émission déplacée')
        ->setKeyword('')
        ->setDatepub(new \DateTime('2099-10-20 10:00:00'))
        ->setRef('PHPUNIT-MOVED')
        ->setDuree(60)
        ->setDescriptif('Émission déplacée après dévalidation.');

    $newEmission = (new Emission())
        ->setTitre('PHPUnit - nouvelle émission')
        ->setKeyword('')
        ->setDatepub(new \DateTime('2099-10-20 11:00:00'))
        ->setRef('PHPUNIT-NEW-SLOT')
        ->setDuree(60)
        ->setDescriptif('Émission placée sur le créneau libéré.');

    $this->entityManager->persist($movedEmission);
    $this->entityManager->persist($newEmission);
    $this->entityManager->flush();

    $movedEmissionId = $movedEmission->getId();
    $newEmissionId = $newEmission->getId();

    self::assertNotNull($movedEmissionId);
    self::assertNotNull($newEmissionId);

    // Diffusion historique, conservée après dévalidation.
    $historicalDiffusion = (new Diffusion())
        ->setEmission($movedEmission)
        ->setNombreDiffusion(2)
        ->setAssignmentGroupKey('phpunit_moved_group')
        ->markAsUnpublished()
        ->setSchedule(
            new \DateTime('2099-10-26 17:00:00'),
            60
        );

    $this->entityManager->persist($historicalDiffusion);
    $this->entityManager->flush();

    $historicalDiffusionId = $historicalDiffusion->getId();

    self::assertNotNull($historicalDiffusionId);

    // Draft lié à la diffusion historique, déplacé à 14 h.
    $movedDraft = (new DiffusionDraft())
        ->setEmission($movedEmission)
        ->setNombreDiffusion(2)
        ->setDraftType(DiffusionDraft::TYPE_MANUAL_REBROADCAST)
        ->setSchedule(
            new \DateTimeImmutable('2099-10-26 14:00:00'),
            60
        )
        ->setAssignmentGroupKey('phpunit_moved_group')
        ->setPublishedDiffusion($historicalDiffusion)
        ->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)
        ->setPublishedAt(null);

    // Nouveau draft sur l'ancien horaire, sans diffusion liée.
    $newDraft = (new DiffusionDraft())
        ->setEmission($newEmission)
        ->setNombreDiffusion(1)
        ->setDraftType(DiffusionDraft::TYPE_MANUAL_SPECIAL)
        ->setSchedule(
            new \DateTimeImmutable('2099-10-26 17:00:00'),
            60
        )
        ->setAssignmentGroupKey('phpunit_new_slot_group');

    $this->entityManager->persist($movedDraft);
    $this->entityManager->persist($newDraft);
    $this->entityManager->flush();

    $movedDraftId = $movedDraft->getId();
    $newDraftId = $newDraft->getId();

    self::assertNotNull($movedDraftId);
    self::assertNotNull($newDraftId);

    // Prévisualisation : aucun conflit.
    $preview = $this->publicationService->previewWeekPublication(
        $weekStart
    );

    self::assertTrue($preview['canPublish']);
    self::assertSame(0, $preview['conflictCount']);
    self::assertSame(1, $preview['createCount']);
    self::assertSame(1, $preview['updateCount']);

    // Publication réelle dans la transaction de test.
    $result = $this->publicationService->publishWeek($weekStart);

    self::assertTrue($result['published']);
    self::assertSame(1, $result['createdCount']);
    self::assertSame(1, $result['updatedCount']);
    self::assertSame(2, $result['publishedDraftCount']);

    // Relecture depuis la base, sans cache d'entités Doctrine.
    $this->clearEntityManager();

    $reloadedMovedDraft = $this->entityManager
        ->getRepository(DiffusionDraft::class)
        ->find($movedDraftId);

    $reloadedNewDraft = $this->entityManager
        ->getRepository(DiffusionDraft::class)
        ->find($newDraftId);

    $reloadedHistoricalDiffusion = $this->entityManager
        ->getRepository(Diffusion::class)
        ->find($historicalDiffusionId);

    self::assertNotNull($reloadedMovedDraft);
    self::assertNotNull($reloadedNewDraft);
    self::assertNotNull($reloadedHistoricalDiffusion);

    // Les deux drafts sont publiés.
    self::assertTrue($reloadedMovedDraft->isPublished());
    self::assertTrue($reloadedNewDraft->isPublished());

    // L'ancienne diffusion conserve son ID et passe à 14 h.
    self::assertSame(
        $historicalDiffusionId,
        $reloadedMovedDraft->getPublishedDiffusion()?->getId()
    );

    self::assertSame(
        '2099-10-26 14:00:00',
        $reloadedHistoricalDiffusion
            ->getHoraireDiffusion()
            ?->format('Y-m-d H:i:s')
    );

    self::assertSame(
        $movedEmissionId,
        $reloadedHistoricalDiffusion->getEmission()?->getId()
    );

    self::assertTrue($reloadedHistoricalDiffusion->isPublished());

    // Une nouvelle diffusion a été créée à 17 h.
    $createdDiffusion = $reloadedNewDraft->getPublishedDiffusion();

    self::assertInstanceOf(Diffusion::class, $createdDiffusion);
    self::assertNotNull($createdDiffusion->getId());

    self::assertNotSame(
        $historicalDiffusionId,
        $createdDiffusion->getId()
    );

    self::assertSame(
        '2099-10-26 17:00:00',
        $createdDiffusion->getHoraireDiffusion()?->format('Y-m-d H:i:s')
    );

    self::assertSame(
        $newEmissionId,
        $createdDiffusion->getEmission()?->getId()
    );

    self::assertTrue($createdDiffusion->isPublished());

    // Aucun lien croisé entre les deux drafts.
    self::assertNotSame(
        $reloadedMovedDraft->getPublishedDiffusion()?->getId(),
        $reloadedNewDraft->getPublishedDiffusion()?->getId()
    );
}

}

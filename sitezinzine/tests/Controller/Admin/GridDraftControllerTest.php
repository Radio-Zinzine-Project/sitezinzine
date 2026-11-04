<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Categories;
use App\Entity\DiffusionDraft;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\Theme;
use App\Entity\User;
use App\Entity\PendingRebroadcast;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\GridSlotArbitration;
use App\repository\DiffusionDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GridDraftControllerTest extends WebTestCase
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

    public function testAnonymousUserCannotCreateManualDraft(): void
    {
        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual'
        );

        $this->assertResponseRedirects();
    }

    public function testRoleUserCannotCreateManualDraft(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual'
        );

        $this->assertResponseRedirects('/access-denied');
    }

    public function testCreateManualReturnsBadRequestWhenParametersAreMissing(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual'
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Paramètres manquants',
            $data['error']
        );
    }

    public function testCreateManualReturnsNotFoundWhenEmissionDoesNotExist(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => 999999999,
                'startsAt' => '2026-10-06 10:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Émission introuvable',
            $data['error']
        );
    }

    public function testCreateManualReturnsBadRequestForInvalidDate(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => 'date-totalement-invalide',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Date invalide',
            $data['error']
        );
    }

    public function testCreateManualReturnsBadRequestForInvalidDraftType(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-06 10:00:00',
                'draftType' => 'invalid_type',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Type de draft manuel invalide',
            $data['error']
        );
    }

    public function testCreateManualReturnsBadRequestForInvalidDuration(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-06 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 0,
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Durée invalide',
            $data['error']
        );
    }

    public function testCreateManualReturnsBadRequestWhenTimeIsNotAlignedOnQuarterHour(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-06 10:07:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'L’heure doit être alignée sur un quart d’heure.',
            $data['error']
        );
    }

    public function testDeleteReturnsBadRequestForInvalidJson(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/delete',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            'json-invalide'
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Payload JSON invalide',
            $data['error']
        );
    }

    public function testDeleteReturnsBadRequestWhenDraftIdIsMissing(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            []
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Paramètre draftId manquant',
            $data['error']
        );
    }

    public function testDeleteReturnsBadRequestForInvalidDeleteMode(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => 123,
                'deleteMode' => 'invalid',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Mode de suppression invalide',
            $data['error']
        );
    }

    public function testDeleteReturnsNotFoundWhenDraftDoesNotExist(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => 999999999,
                'deleteMode' => 'single',
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Draft introuvable',
            $data['error']
        );
    }

    public function testDeleteRefusesRegularDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $draftId = $draft->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $draftId,
                'deleteMode' => 'single',
            ]
        );

        $this->assertResponseStatusCodeSame(403);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Ce draft ne peut pas être supprimé via cette action',
            $data['error']
        );

        $this->entityManager->clear();

        $this->assertNotNull(
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->find($draftId)
        );
    }

    public function testDeleteCanDeleteSingleManualDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $draftId = $draft->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $draftId,
                'deleteMode' => 'single',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame($draftId, $data['draftId']);
        $this->assertSame('single', $data['deleteMode']);
        $this->assertSame(1, $data['deletedCount']);

        $this->entityManager->clear();

        $this->assertNull(
            $this->entityManager
                ->getRepository(DiffusionDraft::class)
                ->find($draftId)
        );
    }

    public function testMoveReturnsBadRequestWhenParametersAreMissing(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move'
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Paramètres manquants',
            $data['error']
        );
    }

    public function testMoveReturnsNotFoundWhenDraftDoesNotExist(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => 999999999,
                'startsAt' => '2026-10-06 10:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Draft introuvable.',
            $data['error']
        );
    }

    public function testMoveRefusesRegularDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draft->getId(),
                'startsAt' => '2026-10-06 12:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Seules les programmations ponctuelles peuvent être déplacées ici.',
            $data['error']
        );
    }

    public function testMoveReturnsBadRequestForInvalidDate(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draft->getId(),
                'startsAt' => 'date-invalide',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Date invalide.',
            $data['error']
        );
    }

    public function testCreateRebroadcastsReturnsBadRequestForInvalidJson(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/rebroadcasts',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            'json-invalide'
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Payload JSON invalide.',
            $data['error']
        );
    }

    public function testCreateRebroadcastsReturnsBadRequestWhenParametersAreMissing(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            []
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Paramètres manquants.',
            $data['error']
        );
    }

    public function testCreateRebroadcastsReturnsNotFoundWhenParentDoesNotExist(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => 999999999,
                'rebroadcasts' => [
                    '2026-10-07 10:00:00',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Programmation ponctuelle introuvable.',
            $data['error']
        );
    }

    public function testCreateRebroadcastsRefusesRegularParent(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $draft->getId(),
                'rebroadcasts' => [
                    '2026-10-07 10:00:00',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Programmation ponctuelle introuvable.',
            $data['error']
        );
    }

    public function testCreateRebroadcastsRefusesRebroadcastAsParent(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $draft->getId(),
                'rebroadcasts' => [
                    '2026-10-07 10:00:00',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Impossible d’ajouter des rediffs depuis une rediffusion.',
            $data['error']
        );
    }

    public function testListRebroadcastsReturnsNotFoundForUnknownDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/grid-drafts/999999999/rebroadcasts'
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertSame([], $data['items']);
    }

    public function testListRebroadcastsReturnsEmptyItemsWithoutGroup(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/grid-drafts/' . $draft->getId() . '/rebroadcasts'
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertSame([], $data['items']);
    }

    /** @dataProvider sidebarGroupCases */
    public function testSidebarGroupReturnsDistinctOccurrencesWithoutMutation(array $specifications, array $expectedStarts): void
    {
        $emission = $this->createEmission();
        $groupKey = 'sidebar_' . uniqid();
        $drafts = [];
        foreach ($specifications as $spec) {
            $startsAt = $spec['draftTime'] ?? '2026-10-11 22:00:00';
            $diffusion = null;
            if ($spec['diffusion'] ?? false) {
                $diffusion = (new \App\Entity\Diffusion())->setEmission($emission)
                    ->setAssignmentGroupKey($groupKey)->setNombreDiffusion(2)
                    ->setSchedule(new \DateTime($spec['diffusionTime'] ?? $startsAt), 60);
                if ($spec['unpublished'] ?? false) {
                    $diffusion->markAsUnpublished();
                }
                $this->entityManager->persist($diffusion);
            }
            if ($spec['draft'] ?? false) {
                $draft = $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_REBROADCAST,
                    new \DateTimeImmutable($startsAt), 60, 2, $groupKey);
                if ($diffusion !== null) {
                    $draft->markAsPublished($diffusion, new \DateTimeImmutable('2026-10-01'));
                    if ($spec['unpublished'] ?? false) {
                        // Dévalidation : lien conservé, le Draft redevient la donnée de travail.
                        $draft->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)->setPublishedAt(null);
                    }
                }
                if ($spec['deleted'] ?? false) {
                    $draft->softDelete();
                } else {
                    $drafts[] = $draft;
                }
            }
        }
        // Une autre affectation de la même émission ne doit jamais être agrégée.
        $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-12-01 10:00:00'), 60, 1, 'other_' . uniqid());
        $this->entityManager->flush();
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $before = $this->sidebarGroupSnapshot($groupKey);
        $reference = null;
        foreach ($drafts as $draft) {
            $this->client->request('GET', '/admin/grid-drafts/' . $draft->getId() . '/group');
            $this->assertResponseIsSuccessful();
            $data = $this->getJsonResponse();
            self::assertTrue($data['success']);
            self::assertSame($groupKey, $data['assignmentGroupKey']);
            self::assertSame($before, $this->sidebarGroupSnapshot($groupKey), 'GET ne doit modifier aucune ligne du groupe.');
            $actual = array_column($data['items'], 'startsAt');
            sort($actual);
            sort($expectedStarts);
            self::assertCount(count($expectedStarts), $data['items'], 'Une ligne par occurrence distincte.');
            self::assertSame($expectedStarts, $actual);
            $items = $data['items'];
            sort($items);
            if ($reference !== null) {
                self::assertSame($reference, $items, 'Même ensemble depuis chaque Draft.');
            }
            $reference = $items;
        }
    }

    public static function sidebarGroupCases(): iterable
    {
        $first = '2026-10-11 22:00:00';
        $second = '2026-11-04 14:00:00';
        yield 'deux Drafts sans Diffusion' => [[['draft' => true], ['draft' => true, 'draftTime' => $second]], [$first, $second]];
        yield 'paire publiée liée = une occurrence' => [[['draft' => true, 'diffusion' => true]], [$first]];
        yield 'paire dépubliée liée = une occurrence' => [[['draft' => true, 'diffusion' => true, 'unpublished' => true]], [$first]];
        yield 'exemple deux paires liées = deux occurrences et non quatre' => [[
            ['draft' => true, 'diffusion' => true],
            ['draft' => true, 'diffusion' => true, 'unpublished' => true, 'draftTime' => $second],
        ], [$first, $second]];
        yield 'mixte six enregistrements = quatre occurrences' => [[
            ['draft' => true, 'diffusion' => true],
            ['draft' => true, 'diffusion' => true, 'unpublished' => true, 'draftTime' => $second],
            ['draft' => true, 'draftTime' => '2026-11-05 14:00:00'],
            ['diffusion' => true, 'diffusionTime' => '2026-11-06 14:00:00'],
        ], [$first, $second, '2026-11-05 14:00:00', '2026-11-06 14:00:00']];
        yield 'non liés même émission horaire rang et groupe = deux occurrences' => [[
            ['draft' => true], ['diffusion' => true],
        ], [$first, $first]];
        yield 'Diffusion seule dépubliée conservée' => [[
            ['draft' => true], ['diffusion' => true, 'unpublished' => true, 'diffusionTime' => $second],
        ], [$first, $second]];
        yield 'Draft supprimé exclu' => [[
            ['draft' => true], ['draft' => true, 'deleted' => true, 'draftTime' => $second],
        ], [$first]];
        yield 'publication utilise horaire effectif et non horaire Draft' => [[
            ['draft' => true, 'diffusion' => true, 'diffusionTime' => $second],
        ], [$second]];
        yield 'dépublication utilise horaire de travail du Draft' => [[
            ['draft' => true, 'diffusion' => true, 'unpublished' => true, 'diffusionTime' => $second],
        ], [$first]];
    }

    private function sidebarGroupSnapshot(string $groupKey): array
    {
        $rows = [];
        foreach (['diffusion_draft', 'diffusion', 'pending_rebroadcast'] as $table) {
            $rows[$table] = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT * FROM ' . $table . ' WHERE assignment_group_key = ? ORDER BY id', [$groupKey]
            );
        }
        return $rows;
    }

    public function testSidebarGroupDoesNotMergeUnrelatedRowsWithSameNumericId(): void
    {
        $emission = $this->createEmission();
        $template = $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-11 22:00:00'));
        $diffusion = (new \App\Entity\Diffusion())->setEmission($emission)
            ->setSchedule(new \DateTime('2026-10-11 22:00:00'), 60)->setNombreDiffusion(1);
        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();
        $id = 1 + max(
            (int) $connection->fetchOne('SELECT MAX(id) FROM diffusion_draft'),
            (int) $connection->fetchOne('SELECT MAX(id) FROM diffusion')
        );
        $groupKey = 'sidebar_collision_' . uniqid();
        // Deux fixtures SQL réellement persistées, sans published_diffusion_id.
        // Aucun générateur Doctrine ni compteur SQL n'est reconfiguré.
        foreach (['diffusion_draft' => $template->getId(), 'diffusion' => $diffusion->getId()] as $table => $templateId) {
            $row = $connection->fetchAssociative('SELECT * FROM ' . $table . ' WHERE id = ?', [$templateId]);
            $row['id'] = $id;
            $row['assignment_group_key'] = $groupKey;
            $connection->insert($table, $row);
        }
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $before = $this->sidebarGroupSnapshot($groupKey);
        $this->client->request('GET', '/admin/grid-drafts/' . $id . '/group');
        $this->assertResponseIsSuccessful();
        self::assertSame($before, $this->sidebarGroupSnapshot($groupKey));
        self::assertCount(2, $this->getJsonResponse()['items'], 'Même ID numérique sans relation : deux occurrences.');
    }

    /** @dataProvider sidebarDiffusionSourceCases */
    public function testSidebarGroupCanBeConsultedFromEveryDiffusionSource(bool $mixed, int $sourceIndex): void
    {
        $emission = $this->createEmission();
        $groupKey = 'sidebar_sources_' . uniqid();
        $sources = [];
        $draftSources = [];
        foreach (['2026-10-11 22:00:00', '2026-11-04 14:00:00'] as $index => $startsAt) {
            $diffusion = (new \App\Entity\Diffusion())->setEmission($emission)
                ->setAssignmentGroupKey($groupKey)->setNombreDiffusion($index + 1)
                ->setSchedule(new \DateTime($startsAt), 60);
            if ($index === 1) {
                $diffusion->markAsUnpublished();
            }
            $this->entityManager->persist($diffusion);
            $this->entityManager->flush();
            $sources[] = '/admin/diffusions/' . $diffusion->getId() . '/group';
            if ($mixed) {
                $draft = $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_SPECIAL,
                    new \DateTimeImmutable($startsAt), 60, $index + 1, $groupKey);
                $draft->markAsPublished($diffusion, new \DateTimeImmutable('2026-10-01'));
                if ($index === 1) {
                    $draft->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)->setPublishedAt(null);
                }
                $draftSources[] = '/admin/grid-drafts/' . $draft->getId() . '/group';
            }
        }
        if ($mixed) {
            $draftOnly = $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_REBROADCAST,
                new \DateTimeImmutable('2026-11-05 14:00:00'), 60, 3, $groupKey);
            $draftSources[] = '/admin/grid-drafts/' . $draftOnly->getId() . '/group';
            $diffusionOnly = (new \App\Entity\Diffusion())->setEmission($emission)
                ->setAssignmentGroupKey($groupKey)->setNombreDiffusion(4)
                ->setSchedule(new \DateTime('2026-11-06 14:00:00'), 60);
            $this->entityManager->persist($diffusionOnly);
            $this->entityManager->flush();
            $sources[] = '/admin/diffusions/' . $diffusionOnly->getId() . '/group';
        }
        $this->entityManager->flush();
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $before = $this->sidebarGroupSnapshot($groupKey);
        $reference = null;
        foreach (array_unique(array_merge([$sources[$sourceIndex]], $sources, $draftSources)) as $url) {
            $this->client->request('GET', $url);
            // Ce RED identifie l'absence du point d'entrée Diffusion, pas un échec du calcul de cardinalité.
            self::assertSame(200, $this->client->getResponse()->getStatusCode(),
                'La consultation doit accepter une identité Diffusion explicitement typée.');
            $data = $this->getJsonResponse();
            self::assertTrue($data['success']);
            self::assertSame($groupKey, $data['assignmentGroupKey']);
            self::assertCount($mixed ? 4 : 2, $data['items']);
            $items = $data['items'];
            sort($items);
            if ($reference !== null) {
                self::assertSame($reference, $items, 'Même ensemble depuis toutes les sources.');
            }
            self::assertSame($before, $this->sidebarGroupSnapshot($groupKey));
            $reference = $items;
        }
    }

    public static function sidebarDiffusionSourceCases(): iterable
    {
        yield 'Diffusions seules depuis publiée' => [false, 0];
        yield 'Diffusions seules depuis dépubliée' => [false, 1];
        yield 'mixte quatre occurrences depuis publiée' => [true, 0];
        yield 'mixte quatre occurrences depuis dépubliée' => [true, 1];
    }

    public function testSidebarDiffusionWithoutGroupReturnsOnlyItself(): void
    {
        $emission = $this->createEmission();
        $diffusion = (new \App\Entity\Diffusion())->setEmission($emission)
            ->setSchedule(new \DateTime('2026-10-11 22:00:00'), 60)->setNombreDiffusion(0);
        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/diffusions/' . $diffusion->getId() . '/group');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = $this->getJsonResponse();
        self::assertNull($data['assignmentGroupKey']);
        self::assertCount(1, $data['items']);
        self::assertSame('2026-10-11 22:00:00', $data['items'][0]['startsAt']);
        // Aucun libellé de rang n'est déduit du nombre_diffusion historique (ici 0).
    }

    public function testSidebarUnknownDiffusionReturnsJsonNotFound(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/diffusions/999999999/group');
        $this->assertResponseStatusCodeSame(404);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $data = $this->getJsonResponse();
        self::assertFalse($data['success']);
        self::assertSame([], $data['items']);
    }

    /** @dataProvider sidebarDeletedDraftCases */
    public function testSidebarKeepsDiffusionLinkedToDeletedDraftWithoutRestoringDraft(
        bool $unpublished,
        string $source
    ): void {
        $emission = $this->createEmission();
        $groupKey = 'sidebar_deleted_link_' . uniqid();
        $anchor = $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-11 22:00:00'), 60, 1, $groupKey);
        $diffusion = (new \App\Entity\Diffusion())->setEmission($emission)
            ->setAssignmentGroupKey($groupKey)->setNombreDiffusion(2)
            ->setSchedule(new \DateTime('2026-11-04 14:00:00'), 60);
        if ($unpublished) {
            $diffusion->markAsUnpublished();
        }
        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();
        // Horaires différents pour détecter le retour du Draft supprimé à sa place.
        $deleted = $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-11-03 14:00:00'), 60, 2, $groupKey);
        $deleted->markAsPublished($diffusion, new \DateTimeImmutable('2026-10-01'));
        if ($unpublished) {
            $deleted->setPublicationStatus(DiffusionDraft::STATUS_DRAFT)->setPublishedAt(null);
        }
        $deleted->softDelete();
        $this->entityManager->flush();
        $deletedId = $deleted->getId();
        $diffusionId = $diffusion->getId();
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $before = $this->sidebarGroupSnapshot($groupKey);
        $url = $source === 'draft'
            ? '/admin/grid-drafts/' . $anchor->getId() . '/group'
            : '/admin/diffusions/' . $diffusionId . '/group';

        $this->client->request('GET', $url);
        self::assertSame($before, $this->sidebarGroupSnapshot($groupKey), 'La consultation ne modifie ni suppression ni publication ni liaison.');
        $this->entityManager->clear();
        self::assertTrue($this->entityManager->find(DiffusionDraft::class, $deletedId)->isDeleted());
        $afterDiffusion = $this->entityManager->find(\App\Entity\Diffusion::class, $diffusionId);
        self::assertNotNull($afterDiffusion);
        self::assertSame($unpublished ? 'unpublished' : 'published', $afterDiffusion->getPublicationStatus());
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'Les deux parcours consultent le même groupe.');
        $data = $this->getJsonResponse();
        self::assertTrue($data['success']);
        self::assertSame($groupKey, $data['assignmentGroupKey']);
        self::assertCount(2, $data['items'], 'Un Draft actif et une Diffusion conservée : exactement deux occurrences.');
        $starts = array_column($data['items'], 'startsAt');
        sort($starts);
        self::assertSame(['2026-10-11 22:00:00', '2026-11-04 14:00:00'], $starts,
            'La Diffusion conserve son propre horaire ; le Draft supprimé ne doit pas réapparaître.');
    }

    public static function sidebarDeletedDraftCases(): iterable
    {
        yield 'publiée depuis Draft actif' => [false, 'draft'];
        yield 'dépubliée depuis Draft actif' => [true, 'draft'];
        yield 'publiée depuis Diffusion' => [false, 'diffusion'];
        yield 'dépubliée depuis Diffusion' => [true, 'diffusion'];
    }

    /** @dataProvider sidebarGroupAccessCases */
    public function testSidebarGroupPreservesAccessRestrictions(?array $roles, string $redirect, string $source): void
    {
        if ($roles !== null) {
            $this->client->loginUser($this->createUser($roles));
        }
        $this->client->request('GET', '/admin/' . $source . '/999999999/group');
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame($redirect, parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    public static function sidebarGroupAccessCases(): iterable
    {
        yield 'Draft anonymous' => [null, '/login', 'grid-drafts'];
        yield 'Draft user without admin role' => [['ROLE_USER'], '/access-denied', 'grid-drafts'];
        yield 'Diffusion anonymous' => [null, '/login', 'diffusions'];
        yield 'Diffusion user without admin role' => [['ROLE_USER'], '/access-denied', 'diffusions'];
    }

    /** @dataProvider sidebarRenderedGridCases */
    public function testSidebarRenderedGridUsesTypedRouteAndReturnsTheWholeGroup(bool $manual, bool $published): void
    {
        $emission = $this->createEmission();
        $groupKey = 'sidebar_rendered_' . uniqid();
        $slots = [];
        if (!$manual) {
            $firstSlot = $this->createRegularSlotForArbitration();
            $rule = $firstSlot->getRule();
            $rule->setValidFrom(new \DateTimeImmutable('2099-01-01'))
                ->setValidUntil(new \DateTimeImmutable('2099-12-31'));
            $secondSlot = (new ProgrammationRuleSlot())->setDayOfWeek(3)
                ->setStartTime(new \DateTimeImmutable('10:00:00'))->setDurationMinutes(60)
                ->setBroadcastRank(2)->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
                ->setIsActive(true);
            $rule->addSlot($secondSlot);
            $this->entityManager->persist($secondSlot);
            $slots = [$firstSlot, $secondSlot];
        }
        $expectedStarts = $manual
            ? ['2099-01-06 02:15:00', '2099-01-07 02:15:00']
            : ['2099-01-06 10:00:00', '2099-01-07 10:00:00'];
        foreach ($expectedStarts as $index => $startsAt) {
            $type = $manual
                ? ($index === 0 ? DiffusionDraft::TYPE_MANUAL_SPECIAL : DiffusionDraft::TYPE_MANUAL_REBROADCAST)
                : DiffusionDraft::TYPE_REGULAR;
            $draft = $this->createDraft($emission, $type, new \DateTimeImmutable($startsAt), 60, $index + 1, $groupKey);
            if (!$manual) {
                $draft->setSlot($slots[$index]);
            }
            if ($published) {
                $diffusion = (new \App\Entity\Diffusion())->setEmission($emission)
                    ->setAssignmentGroupKey($groupKey)->setNombreDiffusion($index + 1)
                    ->setSchedule(new \DateTime($startsAt), 60);
                $this->entityManager->persist($diffusion);
                $draft->markAsPublished($diffusion, new \DateTimeImmutable('2099-01-01'));
            }
        }
        $this->entityManager->flush();
        $this->client->loginUser($this->createUser(['ROLE_ADMIN']));
        $before = $this->sidebarGroupSnapshot($groupKey);
        $crawler = $this->client->request('GET', '/admin/grille/2099-01-06');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $postits = $crawler->filter('.postit[data-assigned-emission-id="' . $emission->getId() . '"]');
        self::assertCount(2, $postits);
        $urls = [];
        foreach ($postits as $node) {
            self::assertSame('click->grid#selectSlot', $node->getAttribute('data-action'));
            $draftId = $node->getAttribute('data-draft-id');
            $diffusionId = $node->getAttribute('data-diffusion-id');
            self::assertSame($published, $diffusionId !== '');
            self::assertSame(!$published, $draftId !== '');
            self::assertSame($manual, $node->getAttribute('data-is-manual-draft') === 'true');
            $urls[] = $published ? '/admin/diffusions/' . $diffusionId . '/group' : '/admin/grid-drafts/' . $draftId . '/group';
        }
        $reference = null;
        foreach ($urls as $url) {
            $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $data = $this->getJsonResponse();
            self::assertCount(2, $data['items']);
            self::assertSame($expectedStarts, array_column($data['items'], 'startsAt'));
            if ($reference !== null) {
                self::assertSame($reference, $data);
            }
            self::assertSame($before, $this->sidebarGroupSnapshot($groupKey));
            $reference = $data;
        }
    }

    public static function sidebarRenderedGridCases(): iterable
    {
        yield 'regular draft grid' => [false, false];
        yield 'regular published grid' => [false, true];
        yield 'manual draft grid' => [true, false];
        yield 'manual published grid' => [true, true];
    }

    public function testGroupReturnsNotFoundForUnknownDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/grid-drafts/999999999/group'
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Draft introuvable.',
            $data['error']
        );
        $this->assertSame([], $data['items']);
    }

    public function testGroupReturnsSingleDraftWithoutGroupKey(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00')
        );

        $draftId = $draft->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/grid-drafts/' . $draftId . '/group'
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertNull($data['assignmentGroupKey']);
        $this->assertCount(1, $data['items']);
        $this->assertSame(
            $draftId,
            $data['items'][0]['id']
        );
        $this->assertSame(
            '1re diffusion',
            $data['items'][0]['label']
        );
    }

    public function testCreateManualCanCreateManualSpecialDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emissionId,
                'startsAt' => '2026-10-06 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->assertArrayHasKey(
            'draftId',
            $data
        );

        $draftId = (int) $data['draftId'];

        $this->entityManager->clear();

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertNotNull($draft);

        $this->assertSame(
            $emissionId,
            $draft->getEmission()?->getId()
        );

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $draft->getDraftType()
        );

        $this->assertSame(
            60,
            $draft->getDurationMinutes()
        );

        $this->assertSame(
            '2026-10-06 10:00:00',
            $draft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-06 11:00:00',
            $draft->getEndsAt()?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            1,
            $draft->getNombreDiffusion()
        );

        $this->assertTrue(
            $draft->isManualSpecial()
        );
    }

    public function testCreateManualRejectsValidatedWeekWithoutCreatingDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $publishedEmission = $this->createEmission();

        $publishedDiffusion = new \App\Entity\Diffusion();
        $publishedDiffusion
            ->setEmission($publishedEmission)
            ->setHoraireDiffusion(
                new \DateTime('2026-10-07 10:00:00')
            )
            ->setNombreDiffusion(1)
            ->setDurationMinutes(60)
            ->setEndsAt(
                new \DateTime('2026-10-07 11:00:00')
            );

        $publishedDiffusion->markAsPublished();

        $this->entityManager->persist($publishedDiffusion);
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emissionId,
                'startsAt' => '2026-10-10 14:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Impossible de modifier une semaine validée.',
            $data['error']
        );

        $this->entityManager->clear();

        $drafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findBy([
                'emission' => $emissionId,
                'horaireDiffusion' => new \DateTimeImmutable(
                    '2026-10-10 14:00:00'
                ),
            ]);

        $this->assertCount(0, $drafts);
    }

    public function testCreateManualAllowsUnpublishedHistoricalDiffusion(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $historicalEmission = $this->createEmission();

        $diffusion = new \App\Entity\Diffusion();

        $diffusion
            ->setEmission($historicalEmission)
            ->setHoraireDiffusion(
                new \DateTime('2026-10-07 10:00:00')
            )
            ->setNombreDiffusion(1)
            ->setDurationMinutes(60)
            ->setEndsAt(
                new \DateTime('2026-10-07 11:00:00')
            );

        // Une diffusion historique, mais non publiée.
        $diffusion->markAsUnpublished();

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-10 14:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);
    }

    public function testCreateManualAllowsPlacementWhenAnotherWeekIsValidated(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $publishedEmission = $this->createEmission();

        $diffusion = new \App\Entity\Diffusion();

        $diffusion
            ->setEmission($publishedEmission)
            ->setHoraireDiffusion(
                new \DateTime('2026-10-07 10:00:00')
            )
            ->setNombreDiffusion(1)
            ->setDurationMinutes(60)
            ->setEndsAt(
                new \DateTime('2026-10-07 11:00:00')
            );

        $diffusion->markAsPublished();

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        // Mardi 13 octobre : début de la semaine suivante.
        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-13 14:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);
    }

    public function testListRebroadcastsReturnsOnlyRebroadcastsFromGroup(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            3,
            $groupKey
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/grid-drafts/' . $parent->getId() . '/rebroadcasts'
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertArrayHasKey('items', $data);
        $this->assertCount(2, $data['items']);

        $this->assertSame(
            $rebroadcast1->getId(),
            $data['items'][0]['id']
        );

        $this->assertSame(
            '2026-10-07 10:00:00',
            $data['items'][0]['startsAt']
        );

        $this->assertSame(
            '2026-10-07 11:00:00',
            $data['items'][0]['endsAt']
        );

        $this->assertSame(
            1,
            $data['items'][0]['number']
        );

        $this->assertSame(
            $rebroadcast2->getId(),
            $data['items'][1]['id']
        );

        $this->assertSame(
            2,
            $data['items'][1]['number']
        );
    }

    public function testGroupReturnsAllDraftsFromSameGroup(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            3,
            $groupKey
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/grid-drafts/' . $parent->getId() . '/group'
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->assertSame(
            $groupKey,
            $data['assignmentGroupKey']
        );

        $this->assertCount(3, $data['items']);

        $this->assertSame(
            $parent->getId(),
            $data['items'][0]['id']
        );

        $this->assertSame(
            '1re diffusion',
            $data['items'][0]['label']
        );

        $this->assertSame(
            $rebroadcast1->getId(),
            $data['items'][1]['id']
        );

        $this->assertSame(
            'Rediffusion 1',
            $data['items'][1]['label']
        );

        $this->assertSame(
            $rebroadcast2->getId(),
            $data['items'][2]['id']
        );

        $this->assertSame(
            'Rediffusion 2',
            $data['items'][2]['label']
        );
    }

    public function testDeleteFirstManualDiffusionDeletesEntireGroup(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            3,
            $groupKey
        );

        $parentId = $parent->getId();
        $rebroadcast1Id = $rebroadcast1->getId();
        $rebroadcast2Id = $rebroadcast2->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $parentId,
                'deleteMode' => 'single',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame('single', $data['deleteMode']);
        $this->assertSame(3, $data['deletedCount']);

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        // La première diffusion doit être supprimée.
        $this->assertNull(
            $repository->find($parentId)
        );

        // Toutes ses rediffusions doivent également être supprimées.
        $this->assertNull(
            $repository->find($rebroadcast1Id)
        );

        $this->assertNull(
            $repository->find($rebroadcast2Id)
        );
    }

    public function testDeleteCanDeleteWholeManualGroup(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            3,
            $groupKey
        );

        $parentId = $parent->getId();
        $rebroadcast1Id = $rebroadcast1->getId();
        $rebroadcast2Id = $rebroadcast2->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $parentId,
                'deleteMode' => 'group',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(
            'group',
            $data['deleteMode']
        );
        $this->assertSame(
            3,
            $data['deletedCount']
        );

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $this->assertNull(
            $repository->find($parentId)
        );

        $this->assertNull(
            $repository->find($rebroadcast1Id)
        );

        $this->assertNull(
            $repository->find($rebroadcast2Id)
        );
    }

    public function testDeleteSingleDraftRenumbersRemainingGroupChronologically(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            3,
            $groupKey
        );

        $parentId = $parent->getId();
        $rebroadcast1Id = $rebroadcast1->getId();
        $rebroadcast2Id = $rebroadcast2->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $rebroadcast1Id,
                'deleteMode' => 'single',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(
            1,
            $data['deletedCount']
        );

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $parentAfterRequest = $repository->find($parentId);
        $deletedDraft = $repository->find($rebroadcast1Id);
        $rebroadcast2AfterRequest = $repository->find($rebroadcast2Id);

        $this->assertNotNull($parentAfterRequest);
        $this->assertNull($deletedDraft);
        $this->assertNotNull($rebroadcast2AfterRequest);

        $this->assertSame(
            1,
            $parentAfterRequest->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $rebroadcast2AfterRequest->getNombreDiffusion()
        );
    }

    public function testMoveCanMoveManualDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60
        );

        $draftId = $draft->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draftId,
                'startsAt' => '2026-10-06 14:15:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $draftAfterRequest = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertNotNull($draftAfterRequest);

        $this->assertSame(
            '2026-10-06 14:15:00',
            $draftAfterRequest
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-06 15:15:00',
            $draftAfterRequest
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            60,
            $draftAfterRequest->getDurationMinutes()
        );
    }

    public function testMoveRenumbersGroupChronologically(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            3,
            $groupKey
        );

        $parentId = $parent->getId();
        $rebroadcast1Id = $rebroadcast1->getId();
        $rebroadcast2Id = $rebroadcast2->getId();

        $this->client->loginUser($admin);

        /*
     * On déplace la deuxième rediffusion entre la diffusion initiale
     * et la première rediffusion.
     *
     * Avant :
     * 06/10 parent       => n°1
     * 07/10 rediffusion1 => n°2
     * 08/10 rediffusion2 => n°3
     *
     * Après :
     * 06/10 parent       => n°1
     * 06/10 15h rediff2  => n°2
     * 07/10 rediff1      => n°3
     */
        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $rebroadcast2Id,
                'startsAt' => '2026-10-06 15:00:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $parentAfterRequest = $repository->find($parentId);
        $rebroadcast1AfterRequest = $repository->find($rebroadcast1Id);
        $rebroadcast2AfterRequest = $repository->find($rebroadcast2Id);

        $this->assertNotNull($parentAfterRequest);
        $this->assertNotNull($rebroadcast1AfterRequest);
        $this->assertNotNull($rebroadcast2AfterRequest);

        $this->assertSame(
            1,
            $parentAfterRequest->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $rebroadcast2AfterRequest->getNombreDiffusion()
        );

        $this->assertSame(
            3,
            $rebroadcast1AfterRequest->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-06 15:00:00',
            $rebroadcast2AfterRequest
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testMoveManualRebroadcastRenumbersAlreadyPublishedDiffusion(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_published_move_' . uniqid();

        // Première diffusion : 6 octobre, rang 1.
        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        // Première rediffusion : 14 octobre, rang 2.
        $publishedDraft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-14 10:00:00'),
            60,
            2,
            $groupKey
        );

        $publishedDiffusion = new \App\Entity\Diffusion();

        $publishedDiffusion
            ->setEmission($emission)
            ->setHoraireDiffusion(new \DateTime('2026-10-14 10:00:00'))
            ->setDurationMinutes(60)
            ->setEndsAt(new \DateTime('2026-10-14 11:00:00'))
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->markAsPublished();

        $this->entityManager->persist($publishedDiffusion);

        $publishedDraft->setPublishedDiffusion($publishedDiffusion);

        $this->entityManager->flush();

        // Deuxième rediffusion : 20 octobre, rang 3.
        $draftToMove = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-20 10:00:00'),
            60,
            3,
            $groupKey
        );

        $parentId = $parent->getId();
        $publishedDraftId = $publishedDraft->getId();
        $publishedDiffusionId = $publishedDiffusion->getId();
        $draftToMoveId = $draftToMove->getId();

        $this->client->loginUser($admin);

        // Déplacement au 13 octobre, avant la rediffusion publiée.
        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draftToMoveId,
                'startsAt' => '2026-10-13 10:00:00',
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->getJsonResponse()['success']);

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $parentAfter = $draftRepository->find($parentId);
        $movedAfter = $draftRepository->find($draftToMoveId);
        $publishedDraftAfter = $draftRepository->find($publishedDraftId);

        $diffusionAfter = $this->entityManager
            ->getRepository(\App\Entity\Diffusion::class)
            ->find($publishedDiffusionId);

        $this->assertNotNull($parentAfter);
        $this->assertNotNull($movedAfter);
        $this->assertNotNull($publishedDraftAfter);
        $this->assertNotNull($diffusionAfter);

        // Numérotation chronologique des drafts.
        $this->assertSame(1, $parentAfter->getNombreDiffusion());
        $this->assertSame(2, $movedAfter->getNombreDiffusion());
        $this->assertSame(3, $publishedDraftAfter->getNombreDiffusion());

        // La diffusion publiée doit suivre la nouvelle numérotation.
        $this->assertSame(3, $diffusionAfter->getNombreDiffusion());

        // L'occurrence publiée conserve son lien et son horaire.
        $this->assertSame(
            $publishedDiffusionId,
            $publishedDraftAfter->getPublishedDiffusion()?->getId()
        );

        $this->assertSame(
            '2026-10-14 10:00:00',
            $diffusionAfter->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            \App\Entity\Diffusion::STATUS_PUBLISHED,
            $diffusionAfter->getPublicationStatus()
        );
    }

    public function testDeleteManualRebroadcastRenumbersAlreadyPublishedDiffusion(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_delete_published_' . uniqid();

        // Première diffusion : rang 1.
        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        // Rediffusion en brouillon : rang 2, à supprimer.
        $draftToDelete = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-13 10:00:00'),
            60,
            2,
            $groupKey
        );

        // Rediffusion déjà publiée : rang 3.
        $publishedDraft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-20 10:00:00'),
            60,
            3,
            $groupKey
        );

        $publishedDiffusion = new \App\Entity\Diffusion();

        $publishedDiffusion
            ->setEmission($emission)
            ->setHoraireDiffusion(new \DateTime('2026-10-20 10:00:00'))
            ->setDurationMinutes(60)
            ->setEndsAt(new \DateTime('2026-10-20 11:00:00'))
            ->setNombreDiffusion(3)
            ->setAssignmentGroupKey($groupKey)
            ->markAsPublished();

        $this->entityManager->persist($publishedDiffusion);

        $publishedDraft->setPublishedDiffusion($publishedDiffusion);

        $this->entityManager->flush();

        $parentId = $parent->getId();
        $deletedId = $draftToDelete->getId();
        $publishedDraftId = $publishedDraft->getId();
        $publishedDiffusionId = $publishedDiffusion->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'draftId' => $deletedId,
                'deleteMode' => 'single',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertResponseIsSuccessful();

        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($response['success']);

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $parentAfter = $draftRepository->find($parentId);
        $deletedAfter = $draftRepository->find($deletedId);
        $publishedDraftAfter = $draftRepository->find($publishedDraftId);

        $diffusionAfter = $this->entityManager
            ->getRepository(\App\Entity\Diffusion::class)
            ->find($publishedDiffusionId);

        $this->assertNotNull($parentAfter);
        $this->assertNull($deletedAfter);
        $this->assertNotNull($publishedDraftAfter);
        $this->assertNotNull($diffusionAfter);

        // Les drafts restants sont renumérotés.
        $this->assertSame(1, $parentAfter->getNombreDiffusion());
        $this->assertSame(2, $publishedDraftAfter->getNombreDiffusion());

        // La diffusion publiée doit également passer de 3 à 2.
        $this->assertSame(2, $diffusionAfter->getNombreDiffusion());

        // Son lien, son horaire et son statut sont conservés.
        $this->assertSame(
            $publishedDiffusionId,
            $publishedDraftAfter->getPublishedDiffusion()?->getId()
        );

        $this->assertSame(
            '2026-10-20 10:00:00',
            $diffusionAfter->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            \App\Entity\Diffusion::STATUS_PUBLISHED,
            $diffusionAfter->getPublicationStatus()
        );
    }

    public function testMoveRegularGroupRebroadcastRenumbersPublishedDiffusion(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $groupKey = 'rule_test_published_move_' . uniqid();

        // Diffusion régulière : son rang doit rester inchangé.
        $regular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        // Rediffusion publiée : rang 2.
        $publishedDraft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-15 10:00:00'),
            60,
            2,
            $groupKey
        );

        $publishedDiffusion = new \App\Entity\Diffusion();

        $publishedDiffusion
            ->setEmission($emission)
            ->setHoraireDiffusion(new \DateTime('2026-10-15 10:00:00'))
            ->setDurationMinutes(60)
            ->setEndsAt(new \DateTime('2026-10-15 11:00:00'))
            ->setNombreDiffusion(2)
            ->setAssignmentGroupKey($groupKey)
            ->markAsPublished();

        $this->entityManager->persist($publishedDiffusion);
        $publishedDraft->setPublishedDiffusion($publishedDiffusion);
        $this->entityManager->flush();

        // Autre rediffusion en brouillon : rang 3.
        $draftToMove = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-20 10:00:00'),
            60,
            3,
            $groupKey
        );

        $regularId = $regular->getId();
        $publishedDraftId = $publishedDraft->getId();
        $publishedDiffusionId = $publishedDiffusion->getId();
        $draftToMoveId = $draftToMove->getId();

        $this->client->loginUser($admin);

        // Le brouillon passe avant la rediffusion publiée.
        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draftToMoveId,
                'startsAt' => '2026-10-13 10:00:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $regularAfter = $draftRepository->find($regularId);
        $movedAfter = $draftRepository->find($draftToMoveId);
        $publishedDraftAfter = $draftRepository->find($publishedDraftId);

        $diffusionAfter = $this->entityManager
            ->getRepository(\App\Entity\Diffusion::class)
            ->find($publishedDiffusionId);

        $this->assertNotNull($regularAfter);
        $this->assertNotNull($movedAfter);
        $this->assertNotNull($publishedDraftAfter);
        $this->assertNotNull($diffusionAfter);

        // Le rang régulier reste inchangé.
        $this->assertSame(1, $regularAfter->getNombreDiffusion());

        // Les rediffusions manuelles suivent l'ordre chronologique.
        $this->assertSame(2, $movedAfter->getNombreDiffusion());
        $this->assertSame(3, $publishedDraftAfter->getNombreDiffusion());

        // La diffusion publiée doit être synchronisée.
        $this->assertSame(3, $diffusionAfter->getNombreDiffusion());

        // Son lien, son horaire et son statut sont conservés.
        $this->assertSame(
            $publishedDiffusionId,
            $publishedDraftAfter->getPublishedDiffusion()?->getId()
        );

        $this->assertSame(
            '2026-10-15 10:00:00',
            $diffusionAfter->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            \App\Entity\Diffusion::STATUS_PUBLISHED,
            $diffusionAfter->getPublicationStatus()
        );
    }

    public function testCreateRebroadcastsCreatesAndRenumbersGroup(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1
        );

        $parentId = $parent->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parentId,
                'rebroadcasts' => [
                    '2026-10-07 10:00:00',
                    '2026-10-08 10:00:00',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['createdCount']);
        $this->assertArrayHasKey('assignmentGroupKey', $data);
        $this->assertNotEmpty($data['assignmentGroupKey']);

        $groupKey = $data['assignmentGroupKey'];

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $parentAfterRequest = $repository->find($parentId);

        $this->assertNotNull($parentAfterRequest);
        $this->assertSame(
            $groupKey,
            $parentAfterRequest->getAssignmentGroupKey()
        );

        $groupDrafts = $repository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $this->assertCount(3, $groupDrafts);

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $groupDrafts[0]->getDraftType()
        );

        $this->assertSame(
            1,
            $groupDrafts[0]->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-06 10:00:00',
            $groupDrafts[0]
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $groupDrafts[1]->getDraftType()
        );

        $this->assertSame(
            2,
            $groupDrafts[1]->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-07 10:00:00',
            $groupDrafts[1]
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $groupDrafts[2]->getDraftType()
        );

        $this->assertSame(
            3,
            $groupDrafts[2]->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-08 10:00:00',
            $groupDrafts[2]
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testCreateRebroadcastsRejectsInvalidDate(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1
        );

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parent->getId(),
                'rebroadcasts' => [
                    'date-totalement-invalide',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRebroadcastsRejectsStartOutsideQuarterHour(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1
        );

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parent->getId(),
                'rebroadcasts' => [
                    '2026-10-07 10:07:00',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRebroadcastsIgnoresEmptyAndNonStringValues(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1
        );

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parent->getId(),
                'rebroadcasts' => [
                    '',
                    '   ',
                    null,
                    123,
                    false,
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);

        $this->entityManager->clear();

        $parentAfterRequest = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($parent->getId());

        $this->assertNotNull($parentAfterRequest);
    }

    public function testCreateRebroadcastsUsesExistingGroupAndContinuesNumbering(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $existingRebroadcast = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $parentId = $parent->getId();
        $existingRebroadcastId = $existingRebroadcast->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parentId,
                'rebroadcasts' => [
                    '2026-10-08 10:00:00',
                    '2026-10-09 10:00:00',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(2, $data['createdCount']);
        $this->assertSame(
            $groupKey,
            $data['assignmentGroupKey']
        );

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $groupDrafts = $repository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $this->assertCount(4, $groupDrafts);

        $this->assertSame(
            $parentId,
            $groupDrafts[0]->getId()
        );
        $this->assertSame(
            1,
            $groupDrafts[0]->getNombreDiffusion()
        );

        $this->assertSame(
            $existingRebroadcastId,
            $groupDrafts[1]->getId()
        );
        $this->assertSame(
            2,
            $groupDrafts[1]->getNombreDiffusion()
        );

        $this->assertSame(
            3,
            $groupDrafts[2]->getNombreDiffusion()
        );
        $this->assertSame(
            '2026-10-08 10:00:00',
            $groupDrafts[2]
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            4,
            $groupDrafts[3]->getNombreDiffusion()
        );
        $this->assertSame(
            '2026-10-09 10:00:00',
            $groupDrafts[3]
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testCreateRebroadcastsRenumbersWholeGroupChronologically(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $existingRebroadcast = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-09 10:00:00'),
            60,
            2,
            $groupKey
        );

        $parentId = $parent->getId();
        $existingRebroadcastId = $existingRebroadcast->getId();

        $this->client->loginUser($admin);

        /*
     * La nouvelle rediffusion est insérée chronologiquement
     * AVANT la rediffusion qui existait déjà.
     *
     * La renumérotation finale doit donc donner :
     *
     * 06/10 parent        => 1
     * 07/10 nouvelle      => 2
     * 09/10 ancienne      => 3
     */
        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parentId,
                'rebroadcasts' => [
                    '2026-10-07 10:00:00',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['createdCount']);
        $this->assertSame(
            $groupKey,
            $data['assignmentGroupKey']
        );

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $groupDrafts = $repository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $this->assertCount(3, $groupDrafts);

        $this->assertSame(
            $parentId,
            $groupDrafts[0]->getId()
        );
        $this->assertSame(
            1,
            $groupDrafts[0]->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-07 10:00:00',
            $groupDrafts[1]
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            2,
            $groupDrafts[1]->getNombreDiffusion()
        );

        $this->assertSame(
            $existingRebroadcastId,
            $groupDrafts[2]->getId()
        );
        $this->assertSame(
            3,
            $groupDrafts[2]->getNombreDiffusion()
        );
    }

    public function testCreateManualLiveRejectsMissingParameters(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            []
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualLiveRejectsUnknownCategory(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => 999999999,
                'startsAt' => '2026-10-06 10:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualLiveRejectsInactiveCategory(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();

        $categorie->setActive(false);

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-06 10:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualLiveRejectsSoftDeletedCategory(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();

        $categorie->setSoftDelete(true);

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-06 10:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(404);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualLiveRejectsInvalidDate(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => 'date-invalide',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualLiveRejectsStartOutsideQuarterHour(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-06 10:07:00',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualLiveReturnsErrorWhenLiveEmissionCreationFails(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();

        $liveEmissionCreator = $this->createMock(
            \App\Service\LiveEmissionCreator::class
        );

        $liveEmissionCreator
            ->method('createManualForCategory')
            ->willThrowException(
                new \RuntimeException('Erreur de création test')
            );

        static::getContainer()->set(
            \App\Service\LiveEmissionCreator::class,
            $liveEmissionCreator
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-06 10:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString(
            'Erreur de création test',
            $data['error']
        );
    }

    public function testCreateManualLiveCreatesDraftSuccessfully(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();

        $emission = new \App\Entity\Emission();

        $emission
            ->setTitre('Direct test ' . uniqid())
            ->setDescriptif('Émission live de test')
            ->setDuree(60)
            ->setUrl('https://test.com/live')
            ->setDatepub(new \DateTime())
            ->setUpdatedat(new \DateTime())
            ->setKeyword('live-test')
            ->setRef('LIVE-' . uniqid())
            ->setCategorie($categorie)
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $liveEmissionCreator = $this->createMock(
            \App\Service\LiveEmissionCreator::class
        );

        $liveEmissionCreator
            ->method('createManualForCategory')
            ->willReturn($emission);

        static::getContainer()->set(
            \App\Service\LiveEmissionCreator::class,
            $liveEmissionCreator
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-06 12:00:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);

        $draftId = (int) $data['draftId'];

        $this->entityManager->clear();

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertNotNull($draft);

        $this->assertSame(
            $emissionId,
            $draft->getEmission()?->getId()
        );

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_LIVE,
            $draft->getDraftType()
        );

        $this->assertSame(
            1,
            $draft->getNombreDiffusion()
        );

        $this->assertSame(
            60,
            $draft->getDurationMinutes()
        );

        $this->assertSame(
            '2026-10-06 12:00:00',
            $draft
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-06 13:00:00',
            $draft
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            'manual_' . $draftId,
            $draft->getAssignmentGroupKey()
        );
    }

    public function testCreateManualRejectsOverlapWithExistingManualDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-06 10:30:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('conflicts', $data);
        $this->assertNotEmpty($data['conflicts']);
    }

    public function testCreateManualUsesEmissionDurationWhenDurationIsMissing(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $emission->setDuree(45);
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emissionId,
                'startsAt' => '2026-10-13 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $draftId = (int) $data['draftId'];

        $this->entityManager->clear();

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertNotNull($draft);

        $this->assertSame(
            45,
            $draft->getDurationMinutes()
        );

        $this->assertSame(
            '2026-10-13 10:45:00',
            $draft->getEndsAt()?->format('Y-m-d H:i:s')
        );
    }

    public function testCreateManualRebroadcastDoesNotCreateAutomaticGroupKey(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-13 14:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_REBROADCAST,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $draftId = (int) $data['draftId'];

        $this->entityManager->clear();

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertNotNull($draft);

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $draft->getDraftType()
        );

        $this->assertNull(
            $draft->getAssignmentGroupKey()
        );
    }

    public function testMoveRejectsOverlapWithAnotherManualDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draftToMove = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-13 10:00:00'),
            60
        );

        $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-13 15:00:00'),
            60
        );

        $draftToMoveId = $draftToMove->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draftToMoveId,
                'startsAt' => '2026-10-13 15:30:00',
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);

        /*
     * Le déplacement doit être refusé :
     * on vérifie surtout que le draft n'a pas été modifié.
     */
        $this->entityManager->clear();

        $draftAfterRequest = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftToMoveId);

        $this->assertNotNull($draftAfterRequest);

        $this->assertSame(
            '2026-10-13 10:00:00',
            $draftAfterRequest
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-13 11:00:00',
            $draftAfterRequest
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testMoveDoesNotConflictWithItself(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-13 10:00:00'),
            60
        );

        $draftId = $draft->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draftId,
                'startsAt' => '2026-10-13 10:15:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $draftAfterRequest = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertNotNull($draftAfterRequest);

        $this->assertSame(
            '2026-10-13 10:15:00',
            $draftAfterRequest
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testCreateRebroadcastsRejectsOverlapWithExistingManualDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-13 10:00:00'),
            60,
            1
        );

        $blockingDraft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-15 10:00:00'),
            60
        );

        $parentId = $parent->getId();
        $blockingDraftId = $blockingDraft->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parentId,
                'rebroadcasts' => [
                    '2026-10-15 10:30:00',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);

        /*
     * La création de la rediffusion doit avoir été refusée.
     * Aucun nouveau draft ne doit donc avoir été ajouté.
     */
        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $parentAfterRequest = $repository->find($parentId);
        $blockingDraftAfterRequest = $repository->find($blockingDraftId);

        $this->assertNotNull($parentAfterRequest);
        $this->assertNotNull($blockingDraftAfterRequest);

        $this->assertSame(
            '2026-10-13 10:00:00',
            $parentAfterRequest
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-15 10:00:00',
            $blockingDraftAfterRequest
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        /*
     * Le parent n'avait pas de groupe avant la tentative.
     * Le contrôleur peut lui attribuer sa clé de groupe avant
     * de découvrir le conflit, mais aucune rediffusion ne doit
     * avoir été persistée.
     */
        $groupKey = $parentAfterRequest->getAssignmentGroupKey();

        if ($groupKey !== null) {
            $groupDrafts = $repository->findBy([
                'assignmentGroupKey' => $groupKey,
            ]);

            $this->assertCount(1, $groupDrafts);
            $this->assertSame(
                $parentId,
                $groupDrafts[0]->getId()
            );
        }
    }

    public function testCreateManualLiveRejectsOverlapWithExistingManualDraft(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();

        $existingEmission = $this->createEmission();

        $this->createDraft(
            $existingEmission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-20 14:00:00'),
            60
        );

        $liveEmission = new \App\Entity\Emission();

        $liveEmission
            ->setTitre('Direct conflit ' . uniqid())
            ->setDescriptif('Direct utilisé pour le test de conflit')
            ->setDuree(60)
            ->setUrl('https://test.com/live-conflict')
            ->setDatepub(new \DateTime())
            ->setUpdatedat(new \DateTime())
            ->setKeyword('live-conflict')
            ->setRef('LIVE-CONFLICT-' . uniqid())
            ->setCategorie($categorie)
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->entityManager->persist($liveEmission);
        $this->entityManager->flush();

        $liveEmissionCreator = $this->createMock(
            \App\Service\LiveEmissionCreator::class
        );

        $liveEmissionCreator
            ->method('createManualForCategory')
            ->willReturn($liveEmission);

        static::getContainer()->set(
            \App\Service\LiveEmissionCreator::class,
            $liveEmissionCreator
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-20 14:30:00',
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertTrue($data['conflict']);
        $this->assertSame(
            'Ce créneau chevauche déjà une programmation existante.',
            $data['error']
        );

        $this->assertArrayHasKey('conflicts', $data);
        $this->assertNotEmpty($data['conflicts']);
    }

    public function testCreateManualRejectsOverlapWithRegularProgramming(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $this->mockRegularOverlap();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-20 10:15:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualAllowsPlacementOnCancelledRegularOccurrence(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $slot = $this->createRegularSlotForArbitration();

        $arbitration = new GridSlotArbitration();
        $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt(new \DateTimeImmutable('2026-10-20 10:00:00'))
            ->setOriginalEndsAt(new \DateTimeImmutable('2026-10-20 11:00:00'))
            ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
            ->setAction(GridSlotArbitration::ACTION_CANCEL);

        $arbitration->markResolved();

        $this->entityManager->persist($arbitration);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-20 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);
    }

    public function testCreateManualAllowsPlacementOnRescheduledRegularOrigin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $slot = $this->createRegularSlotForArbitration();

        $arbitration = new GridSlotArbitration();
        $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt(new \DateTimeImmutable('2026-10-20 10:00:00'))
            ->setOriginalEndsAt(new \DateTimeImmutable('2026-10-20 11:00:00'))
            ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt(new \DateTimeImmutable('2026-10-20 14:00:00'))
            ->setRescheduledEndsAt(new \DateTimeImmutable('2026-10-20 15:00:00'));

        $arbitration->markResolved();

        $this->entityManager->persist($arbitration);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-20 10:00:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('draftId', $data);
    }

    public function testCreateManualRejectsPlacementOnRescheduledRegularDestination(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $slot = $this->createRegularSlotForArbitration();

        $arbitration = new GridSlotArbitration();
        $arbitration
            ->setSlot($slot)
            ->setOriginalStartsAt(new \DateTimeImmutable('2026-10-20 10:00:00'))
            ->setOriginalEndsAt(new \DateTimeImmutable('2026-10-20 11:00:00'))
            ->setType(GridSlotArbitration::TYPE_CALENDAR_ADJUSTMENT)
            ->setAction(GridSlotArbitration::ACTION_RESCHEDULE_CUSTOM)
            ->setRescheduledStartsAt(new \DateTimeImmutable('2026-10-20 14:00:00'))
            ->setRescheduledEndsAt(new \DateTimeImmutable('2026-10-20 15:00:00'));

        $arbitration->markResolved();

        $this->entityManager->persist($arbitration);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-20 14:15:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertTrue($data['conflict']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateManualLiveRejectsOverlapWithRegularProgramming(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();
        $emission = $this->createEmission();

        $liveEmissionCreator = $this->createMock(
            \App\Service\LiveEmissionCreator::class
        );

        $liveEmissionCreator
            ->method('createManualForCategory')
            ->willReturn($emission);

        static::getContainer()->set(
            \App\Service\LiveEmissionCreator::class,
            $liveEmissionCreator
        );

        $this->mockRegularOverlap();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-20 10:15:00',
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertTrue($data['conflict']);
        $this->assertSame(
            'Ce créneau chevauche déjà une programmation régulière.',
            $data['error']
        );
    }

    public function testMoveRejectsOverlapWithRegularProgramming(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-20 14:00:00'),
            60
        );

        $draftId = $draft->getId();

        $this->mockRegularOverlap();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draftId,
                'startsAt' => '2026-10-20 10:15:00',
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);

        $this->entityManager->clear();

        $draftAfterRequest = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draftId);

        $this->assertNotNull($draftAfterRequest);

        $this->assertSame(
            '2026-10-20 14:00:00',
            $draftAfterRequest
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-20 15:00:00',
            $draftAfterRequest
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testCreateRebroadcastsRejectsOverlapWithRegularProgramming(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-20 08:00:00'),
            60,
            1
        );

        $this->mockRegularOverlap();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parent->getId(),
                'rebroadcasts' => [
                    '2026-10-20 10:15:00',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertTrue($data['conflict']);

        $this->assertSame(
            'Une rediffusion chevauche déjà une programmation régulière.',
            $data['error']
        );

        $this->assertArrayHasKey('debug', $data);
        $this->assertNotEmpty($data['debug']);
    }

    public function testCreateManualTreatsRegularDraftWithoutSlotAsBlocking(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $regularDraft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-20 14:00:00'),
            60
        );

        $this->assertNull($regularDraft->getSlot());

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual',
            [
                'emissionId' => $emission->getId(),
                'startsAt' => '2026-10-20 14:15:00',
                'draftType' => DiffusionDraft::TYPE_MANUAL_SPECIAL,
                'durationMinutes' => 60,
            ]
        );

        $this->assertResponseStatusCodeSame(409);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertTrue($data['conflict']);
        $this->assertSame(
            'Ce créneau chevauche déjà une programmation existante.',
            $data['error']
        );
    }

    public function testCreateManualLiveUsesSixtyMinutesWhenEmissionDurationIsZero(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $categorie = $this->createCategorie();
        $emission = $this->createEmission();

        $emission->setDuree(0);

        $this->entityManager->flush();

        $liveEmissionCreator = $this->createMock(
            \App\Service\LiveEmissionCreator::class
        );

        $liveEmissionCreator
            ->method('createManualForCategory')
            ->willReturn($emission);

        static::getContainer()->set(
            \App\Service\LiveEmissionCreator::class,
            $liveEmissionCreator
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/manual-live',
            [
                'categoryId' => $categorie->getId(),
                'startsAt' => '2026-10-20 16:00:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(60, $data['durationMinutes']);

        $draft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($data['draftId']);

        $this->assertNotNull($draft);
        $this->assertSame(60, $draft->getDurationMinutes());
        $this->assertSame(
            '2026-10-20 17:00:00',
            $draft->getEndsAt()?->format('Y-m-d H:i:s')
        );
    }

    public function testMoveUsesFifteenMinutesWhenDraftDurationIsNullAndEmissionDurationIsZero(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $emission->setDuree(0);

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-20 14:00:00'),
            60
        );

        $draft->setDurationMinutes(null);

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $draft->getId(),
                'startsAt' => '2026-10-20 18:00:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $movedDraft = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($draft->getId());

        $this->assertNotNull($movedDraft);
        $this->assertSame(15, $movedDraft->getDurationMinutes());
        $this->assertSame(
            '2026-10-20 18:00:00',
            $movedDraft->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-10-20 18:15:00',
            $movedDraft->getEndsAt()?->format('Y-m-d H:i:s')
        );
    }

    public function testCreateRebroadcastsUsesFifteenMinutesWhenParentDurationIsNullAndEmissionDurationIsZero(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $emission->setDuree(0);

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-20 08:00:00'),
            60,
            1
        );

        $parent->setDurationMinutes(null);

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/rebroadcasts',
            [
                'draftId' => $parent->getId(),
                'rebroadcasts' => [
                    '2026-10-20 20:00:00',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $rebroadcasts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findBy([
                'assignmentGroupKey' => $parent->getAssignmentGroupKey(),
                'draftType' => DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            ]);

        $this->assertCount(1, $rebroadcasts);

        $rebroadcast = $rebroadcasts[0];

        $this->assertSame(15, $rebroadcast->getDurationMinutes());
        $this->assertSame(
            '2026-10-20 20:15:00',
            $rebroadcast->getEndsAt()?->format('Y-m-d H:i:s')
        );
    }

    public function testDeleteRebroadcastsReturns400WhenGroupContainsNoRebroadcast(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_test_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-20 10:00:00'),
            60,
            1,
            $groupKey
        );

        $parentId = $parent->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $parentId,
                'deleteMode' => 'rebroadcasts',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Aucun draft à supprimer.',
            $data['error']
        );

        $this->entityManager->clear();

        $parentAfterRequest = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->find($parentId);

        $this->assertNotNull($parentAfterRequest);
        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            $parentAfterRequest->getDraftType()
        );
        $this->assertSame(
            $groupKey,
            $parentAfterRequest->getAssignmentGroupKey()
        );
    }

    private function requestJson(
        string $url,
        array $data
    ): void {
        $this->client->request(
            'POST',
            $url,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(
                $data,
                JSON_THROW_ON_ERROR
            )
        );
    }

    private function getJsonResponse(): array
    {
        $content = $this->client
            ->getResponse()
            ->getContent();

        $this->assertIsString($content);

        $data = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertIsArray($data);

        return $data;
    }

    private function createUser(array $roles): User
    {
        $uniqueId = uniqid();

        $user = new User();
        $user->setUsername('grid_user_' . $uniqueId);
        $user->setEmail(
            'grid_user_' . $uniqueId . '@test.com'
        );
        $user->setPassword('fakehashedpassword');
        $user->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createEditeur(): Editeur
    {
        $editeur = new Editeur();
        $editeur->setName(
            'Éditeur grille ' . uniqid()
        );
        $editeur->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);
        $this->entityManager->flush();

        return $editeur;
    }

    private function createCategorie(): Categories
    {
        $categorie = new Categories();
        $categorie->setTitre(
            'Catégorie grille ' . uniqid()
        );
        $categorie->setDuree(60);
        $categorie->setActive(true);
        $categorie->setSoftDelete(false);
        $categorie->setDescriptif(
            'Catégorie utilisée par GridDraftControllerTest'
        );
        $categorie->setUpdatedAt(new \DateTime());
        $categorie->setEditeur(
            $this->createEditeur()
        );

        $this->entityManager->persist($categorie);
        $this->entityManager->flush();

        return $categorie;
    }

    private function createTheme(): Theme
    {
        $theme = new Theme();
        $theme->setName(
            'Thème grille ' . uniqid()
        );
        $theme->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($theme);
        $this->entityManager->flush();

        return $theme;
    }

    private function createEmission(): Emission
    {
        $emission = new Emission();

        $emission
            ->setTitre(
                'Émission grille ' . uniqid()
            )
            ->setDescriptif(
                'Émission utilisée par GridDraftControllerTest'
            )
            ->setDuree(60)
            ->setUrl(
                'https://test.com/grid-' . uniqid()
            )
            ->setDatepub(new \DateTime())
            ->setUpdatedAt(new \DateTime())
            ->setKeyword('grid-test')
            ->setRef('GRID-' . uniqid())
            ->setTheme(
                $this->createTheme()
            )
            ->setCategorie(
                $this->createCategorie()
            )
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        return $emission;
    }

    private function createDraft(
        Emission $emission,
        string $draftType,
        \DateTimeImmutable $startsAt,
        int $duration = 60,
        int $nombreDiffusion = 1,
        ?string $groupKey = null
    ): DiffusionDraft {
        $draft = new DiffusionDraft();

        $draft
            ->setEmission($emission)
            ->setDraftType($draftType)
            ->setNombreDiffusion($nombreDiffusion)
            ->setSchedule(
                $startsAt,
                $duration
            )
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    private function createRegularSlotForArbitration(): ProgrammationRuleSlot
    {
        $category = $this->createCategorie();

        $rule = new ProgrammationRule();
        $rule
            ->setCategory($category)
            ->setRuleNumber(1)
            ->setValidFrom(new \DateTimeImmutable('2026-10-01'))
            ->setValidUntil(new \DateTimeImmutable('2026-10-31'))
            ->setIsActive(true);

        $slot = new ProgrammationRuleSlot();
        $slot
            ->setDayOfWeek(2)
            ->setStartTime(new \DateTimeImmutable('10:00:00'))
            ->setDurationMinutes(60)
            ->setBroadcastRank(1)
            ->setRecurrenceType(ProgrammationRuleSlot::RECURRENCE_WEEKLY)
            ->setIsActive(true);

        $rule->addSlot($slot);

        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $slot;
    }

    private function mockRegularOverlap(): void
    {
        $regularOverlap = [
            'slotId' => 123,
            'startsAt' => '2026-10-20 10:00:00',
            'endsAt' => '2026-10-20 11:00:00',
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

    private function mockOverlappingDrafts(array $drafts): void
    {
        $repository = $this->createMock(DiffusionDraftRepository::class);

        $repository
            ->method('findOverlappingDrafts')
            ->willReturn($drafts);

        static::getContainer()->set(
            DiffusionDraftRepository::class,
            $repository
        );
    }

    public function testCreateRegularRebroadcastCreatesManualRebroadcastAfterRegularRanks(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $regularRebroadcast = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $firstRegularId = $firstRegular->getId();
        $regularRebroadcastId = $regularRebroadcast->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/regular-rebroadcasts',
            [
                'draftId' => $firstRegularId,
                'rebroadcasts' => [
                    '2026-10-08 10:00:00',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['createdCount']);
        $this->assertSame(
            $groupKey,
            $data['assignmentGroupKey']
        );

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $firstRegularAfterRequest = $repository->find($firstRegularId);
        $regularRebroadcastAfterRequest = $repository->find($regularRebroadcastId);

        $this->assertNotNull($firstRegularAfterRequest);
        $this->assertNotNull($regularRebroadcastAfterRequest);

        /*
     * Les rangs réguliers doivent rester intacts.
     */
        $this->assertSame(
            1,
            $firstRegularAfterRequest->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $regularRebroadcastAfterRequest->getNombreDiffusion()
        );

        $groupDrafts = $repository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['nombreDiffusion' => 'ASC']
        );

        $this->assertCount(3, $groupDrafts);

        $punctualRebroadcast = $groupDrafts[2];

        $this->assertSame(
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            $punctualRebroadcast->getDraftType()
        );

        $this->assertSame(
            3,
            $punctualRebroadcast->getNombreDiffusion()
        );

        $this->assertSame(
            $groupKey,
            $punctualRebroadcast->getAssignmentGroupKey()
        );

        $this->assertSame(
            $emission->getId(),
            $punctualRebroadcast->getEmission()?->getId()
        );

        $this->assertNull(
            $punctualRebroadcast->getSlot()
        );

        $this->assertSame(
            '2026-10-08 10:00:00',
            $punctualRebroadcast
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-08 11:00:00',
            $punctualRebroadcast
                ->getEndsAt()
                ?->format('Y-m-d H:i:s')
        );
    }

    public function testCreateRegularRebroadcastRefusesDateBeforeLastRegularEndsAt(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            2,
            $groupKey
        );

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/regular-rebroadcasts',
            [
                'draftId' => $firstRegular->getId(),
                'rebroadcasts' => [
                    '2026-10-07 10:00:00',
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.',
            $data['error']
        );

        $this->entityManager->clear();

        $groupDrafts = $this->entityManager
            ->getRepository(DiffusionDraft::class)
            ->findBy(
                ['assignmentGroupKey' => $groupKey],
                ['nombreDiffusion' => 'ASC']
            );

        $this->assertCount(2, $groupDrafts);
        $this->assertSame(1, $groupDrafts[0]->getNombreDiffusion());
        $this->assertSame(2, $groupDrafts[1]->getNombreDiffusion());
    }

    public function testCreateRegularRebroadcastRenumbersManualRebroadcastsChronologically(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            2,
            $groupKey
        );

        $firstPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
            60,
            3,
            $groupKey
        );

        $secondPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-12 10:00:00'),
            60,
            4,
            $groupKey
        );

        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();
        $firstPunctualId = $firstPunctual->getId();
        $secondPunctualId = $secondPunctual->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/regular-rebroadcasts',
            [
                'draftId' => $firstRegularId,
                'rebroadcasts' => [
                    '2026-10-11 10:00:00',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['createdCount']);

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * Les rangs réguliers ne doivent jamais bouger.
     */
        $this->assertSame(
            1,
            $repository->find($firstRegularId)?->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $repository->find($secondRegularId)?->getNombreDiffusion()
        );

        /*
     * La première ponctuelle reste rang 3.
     */
        $this->assertSame(
            3,
            $repository->find($firstPunctualId)?->getNombreDiffusion()
        );

        /*
     * L'ancienne ponctuelle du 12/10 doit passer de 4 à 5.
     */
        $this->assertSame(
            5,
            $repository->find($secondPunctualId)?->getNombreDiffusion()
        );

        $groupDrafts = $repository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $this->assertCount(5, $groupDrafts);

        $this->assertSame(
            [1, 2, 3, 4, 5],
            array_map(
                static fn(DiffusionDraft $draft): int =>
                (int) $draft->getNombreDiffusion(),
                $groupDrafts
            )
        );
    }

    public function testMoveRegularGroupManualRebroadcastRenumbersOnlyManualRebroadcastsChronologically(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            2,
            $groupKey
        );

        $firstPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
            60,
            3,
            $groupKey
        );

        $secondPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-12 10:00:00'),
            60,
            4,
            $groupKey
        );

        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();
        $firstPunctualId = $firstPunctual->getId();
        $secondPunctualId = $secondPunctual->getId();

        $this->client->loginUser($admin);

        /*
     * On déplace l'ancienne ponctuelle rang 4 avant
     * l'ancienne ponctuelle rang 3.
     *
     * Elle doit donc devenir rang 3.
     */
        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $secondPunctualId,
                'startsAt' => '2026-10-09 10:00:00',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * Les régulières doivent conserver leurs rangs d'origine.
     */
        $this->assertSame(
            1,
            $repository->find($firstRegularId)?->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $repository->find($secondRegularId)?->getNombreDiffusion()
        );

        /*
     * La ponctuelle déplacée passe devant l'autre :
     * ancienne 4 -> nouvelle 3.
     */
        $this->assertSame(
            3,
            $repository->find($secondPunctualId)?->getNombreDiffusion()
        );

        /*
     * L'ancienne ponctuelle rang 3 passe rang 4.
     */
        $this->assertSame(
            4,
            $repository->find($firstPunctualId)?->getNombreDiffusion()
        );

        $groupDrafts = $repository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $this->assertCount(4, $groupDrafts);

        $this->assertSame(
            [1, 2, 3, 4],
            array_map(
                static fn(DiffusionDraft $draft): int =>
                (int) $draft->getNombreDiffusion(),
                $groupDrafts
            )
        );
    }

    public function testMoveRegularGroupManualRebroadcastRefusesDateBeforeLastRegularEndsAt(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            2,
            $groupKey
        );

        $punctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
            60,
            3,
            $groupKey
        );

        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();
        $punctualId = $punctual->getId();

        $this->client->loginUser($admin);

        /*
     * Tentative de déplacement entre les deux régulières :
     *
     * 06/10 : régulière rang 1
     * 07/10 : déplacement demandé -> interdit
     * 08/10 : régulière rang 2
     * 10/10 : position actuelle de la ponctuelle
     */
        $this->client->request(
            'POST',
            '/admin/grid-drafts/move',
            [
                'draftId' => $punctualId,
                'startsAt' => '2026-10-07 10:00:00',
            ]
        );

        $this->assertResponseStatusCodeSame(400);

        $data = $this->getJsonResponse();

        $this->assertFalse($data['success']);
        $this->assertSame(
            'Une rediffusion ponctuelle doit être placée après toutes les diffusions régulières du groupe.',
            $data['error']
        );

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $reloadedFirstRegular = $repository->find($firstRegularId);
        $reloadedSecondRegular = $repository->find($secondRegularId);
        $reloadedPunctual = $repository->find($punctualId);

        /*
     * Rien ne doit avoir été modifié après le refus.
     */
        $this->assertSame(
            1,
            $reloadedFirstRegular?->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $reloadedSecondRegular?->getNombreDiffusion()
        );

        $this->assertSame(
            3,
            $reloadedPunctual?->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-10-10 10:00:00',
            $reloadedPunctual?->getHoraireDiffusion()?->format('Y-m-d H:i:s')
        );
    }

    public function testDeleteRegularGroupManualRebroadcastRenumbersOnlyRemainingManualRebroadcasts(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            2,
            $groupKey
        );

        $firstPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
            60,
            3,
            $groupKey
        );

        $secondPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-11 10:00:00'),
            60,
            4,
            $groupKey
        );

        $thirdPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-12 10:00:00'),
            60,
            5,
            $groupKey
        );

        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();
        $firstPunctualId = $firstPunctual->getId();
        $secondPunctualId = $secondPunctual->getId();
        $thirdPunctualId = $thirdPunctual->getId();

        $this->client->loginUser($admin);

        /*
     * On supprime la ponctuelle du milieu :
     *
     * régulière   rang 1
     * régulière   rang 2
     * ponctuelle  rang 3
     * ponctuelle  rang 4 <- supprimée
     * ponctuelle  rang 5
     *
     * Après suppression :
     *
     * régulière   rang 1
     * régulière   rang 2
     * ponctuelle  rang 3
     * ponctuelle  rang 4
     */
        $this->client->jsonRequest(
            'POST',
            '/admin/grid-drafts/delete',
            [
                'draftId' => $secondPunctualId,
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * La ponctuelle demandée doit réellement avoir disparu.
     */
        $this->assertNull(
            $repository->find($secondPunctualId)
        );

        /*
     * Les rangs réguliers doivent rester totalement intacts.
     */
        $this->assertSame(
            1,
            $repository->find($firstRegularId)?->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $repository->find($secondRegularId)?->getNombreDiffusion()
        );

        /*
     * La première ponctuelle conserve son rang.
     */
        $this->assertSame(
            3,
            $repository->find($firstPunctualId)?->getNombreDiffusion()
        );

        /*
     * L'ancienne ponctuelle rang 5 devient rang 4.
     */
        $this->assertSame(
            4,
            $repository->find($thirdPunctualId)?->getNombreDiffusion()
        );

        $groupDrafts = $repository->findBy(
            ['assignmentGroupKey' => $groupKey],
            ['horaireDiffusion' => 'ASC']
        );

        $this->assertCount(4, $groupDrafts);

        $this->assertSame(
            [1, 2, 3, 4],
            array_map(
                static fn(DiffusionDraft $draft): int =>
                (int) $draft->getNombreDiffusion(),
                $groupDrafts
            )
        );
    }

    public function testDeleteRegularGroupFromManualRebroadcastDeletesOnlyManualRebroadcasts(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'rule_123_origin_20261006_1000';

        $firstRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $secondRegular = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            2,
            $groupKey
        );

        $firstPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
            60,
            3,
            $groupKey
        );

        $secondPunctual = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-12 10:00:00'),
            60,
            4,
            $groupKey
        );

        $firstRegularId = $firstRegular->getId();
        $secondRegularId = $secondRegular->getId();
        $firstPunctualId = $firstPunctual->getId();
        $secondPunctualId = $secondPunctual->getId();

        $this->client->loginUser($admin);

        /*
     * Depuis une rediffusion ponctuelle appartenant à un groupe
     * d'origine régulière, on demande la suppression du groupe.
     *
     * Dans ce cas, "groupe" signifie :
     * toutes les rediffusions ponctuelles ajoutées manuellement.
     *
     * Les diffusions régulières doivent impérativement être conservées.
     */
        $this->client->jsonRequest(
            'POST',
            '/admin/grid-drafts/delete',
            [
                'draftId' => $firstPunctualId,
                'deleteMode' => 'group',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->entityManager->clear();

        $repository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        /*
     * Les régulières existent toujours et leurs rangs
     * n'ont pas été modifiés.
     */
        $reloadedFirstRegular = $repository->find($firstRegularId);
        $reloadedSecondRegular = $repository->find($secondRegularId);

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $reloadedFirstRegular
        );

        $this->assertInstanceOf(
            DiffusionDraft::class,
            $reloadedSecondRegular
        );

        $this->assertSame(
            1,
            $reloadedFirstRegular->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $reloadedSecondRegular->getNombreDiffusion()
        );

        /*
     * Toutes les ponctuelles du groupe ont disparu.
     */
        $this->assertNull(
            $repository->find($firstPunctualId)
        );

        $this->assertNull(
            $repository->find($secondPunctualId)
        );

        /*
     * Le groupe existe toujours puisqu'il contient
     * ses diffusions régulières.
     */
        $groupDrafts = $repository->findByAssignmentGroupKey(
            $groupKey
        );

        $this->assertCount(2, $groupDrafts);

        $this->assertSame(
            [$firstRegularId, $secondRegularId],
            array_map(
                static fn(DiffusionDraft $draft): ?int => $draft->getId(),
                $groupDrafts
            )
        );
    }

    public function testDeleteSingleRegularGroupRebroadcastKeepsOtherExtraWithoutNewPendingAndRenumbers(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $slot = $this->createRegularSlotForArbitration();
        $slot->getRule()->setHasAdditionalRebroadcast(true);
        $groupKey = sprintf('rule_%d_origin_20261006_1000', $slot->getRule()->getId());

        $regular1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $regular2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_REGULAR,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-10 10:00:00'),
            60,
            3,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-12 10:00:00'),
            60,
            4,
            $groupKey
        );

        $regular1Id = $regular1->getId();
        $regular2Id = $regular2->getId();
        $rebroadcast1Id = $rebroadcast1->getId();
        $rebroadcast2Id = $rebroadcast2->getId();
        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $rebroadcast1Id,
                'deleteMode' => 'single',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['deletedCount']);

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        $regular1AfterRequest = $draftRepository->find($regular1Id);
        $regular2AfterRequest = $draftRepository->find($regular2Id);
        $deletedRebroadcast = $draftRepository->find($rebroadcast1Id);
        $rebroadcast2AfterRequest = $draftRepository->find($rebroadcast2Id);

        $this->assertNotNull($regular1AfterRequest);
        $this->assertNotNull($regular2AfterRequest);
        $this->assertNull($deletedRebroadcast);
        $this->assertNotNull($rebroadcast2AfterRequest);

        /*
     * Les diffusions régulières conservent leur numéro.
     */
        $this->assertSame(
            1,
            $regular1AfterRequest->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $regular2AfterRequest->getNombreDiffusion()
        );

        /*
     * La rediffusion restante est renumérotée
     * immédiatement après les régulières.
     */
        $this->assertSame(
            3,
            $rebroadcast2AfterRequest->getNombreDiffusion()
        );

        /*
     * The remaining extra prevents another proposal.
     */
        $pendingRebroadcasts = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        $this->assertCount(0, $pendingRebroadcasts, 'Another extra is still placed: no second proposal is allowed.');
    }


    /** @dataProvider regularReturnOptions */
    public function testDeleteLastManualExtraFromRegularGroupHonorsAdditionalOption(bool $enabled): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $slot = $this->createRegularSlotForArbitration();
        $slot->getRule()->setHasAdditionalRebroadcast($enabled);
        $key = sprintf('rule_%d_origin_20261006_1000', $slot->getRule()->getId());
        $regular = $this->createDraft($emission, DiffusionDraft::TYPE_REGULAR, new \DateTimeImmutable('2026-10-06 10:00:00'), 60, 1, $key);
        $regular->setSlot($slot);
        $manual = $this->createDraft($emission, DiffusionDraft::TYPE_MANUAL_REBROADCAST, new \DateTimeImmutable('2026-10-08 10:00:00'), 60, 2, $key);
        $this->entityManager->flush();
        $regularId = $regular->getId();
        $manualId = $manual->getId();
        $this->client->loginUser($admin);
        $this->requestJson('/admin/grid-drafts/delete', ['draftId' => $manualId, 'deleteMode' => 'single']);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->getJsonResponse()['success']);
        $this->entityManager->clear();
        $repository = $this->entityManager->getRepository(DiffusionDraft::class);
        self::assertNull($repository->find($manualId));
        self::assertNotNull($repository->find($regularId));
        $pending = $this->entityManager->getRepository(PendingRebroadcast::class)->findBy(['assignmentGroupKey' => $key, 'status' => PendingRebroadcast::STATUS_PENDING]);
        self::assertCount($enabled ? 1 : 0, $pending, 'Only an explicitly authorized manual extra may return to the regular pool.');
    }

    public static function regularReturnOptions(): array
    {
        return ['option_disabled' => [false], 'option_enabled' => [true]];
    }

    public function testDeleteSingleManualRebroadcastFromManualGroupReturnsItToPendingPool(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();

        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $rebroadcast1 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $rebroadcast2 = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-08 10:00:00'),
            60,
            3,
            $groupKey
        );

        $parentId = $parent->getId();
        $rebroadcast1Id = $rebroadcast1->getId();
        $rebroadcast2Id = $rebroadcast2->getId();
        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $this->requestJson(
            '/admin/grid-drafts/delete',
            [
                'draftId' => $rebroadcast1Id,
                'deleteMode' => 'single',
            ]
        );

        $this->assertResponseIsSuccessful();

        $data = $this->getJsonResponse();

        $this->assertTrue($data['success']);

        $this->assertSame(
            $rebroadcast1Id,
            $data['draftId']
        );

        $this->assertSame(
            'single',
            $data['deleteMode']
        );

        $this->assertSame(
            1,
            $data['deletedCount']
        );

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $pendingRepository = $this->entityManager
            ->getRepository(PendingRebroadcast::class);

        /*
     * Le draft supprimé n'existe plus.
     */
        $this->assertNull(
            $draftRepository->find($rebroadcast1Id)
        );

        /*
     * Le parent et la rediffusion restante sont conservés.
     */
        $parentAfterRequest = $draftRepository->find($parentId);
        $rebroadcast2AfterRequest = $draftRepository->find($rebroadcast2Id);

        $this->assertNotNull($parentAfterRequest);
        $this->assertNotNull($rebroadcast2AfterRequest);

        /*
     * Le groupe entièrement manuel est renuméroté
     * chronologiquement après la suppression.
     *
     * Parent      => n°1
     * Rediffusion => n°2
     */
        $this->assertSame(
            1,
            $parentAfterRequest->getNombreDiffusion()
        );

        $this->assertSame(
            2,
            $rebroadcast2AfterRequest->getNombreDiffusion()
        );

        /*
     * L'émission supprimée retourne dans le Parc à rediff.
     */
        $pendingRebroadcasts = $pendingRepository->findBy([
            'assignmentGroupKey' => $groupKey,
        ]);

        $this->assertCount(
            1,
            $pendingRebroadcasts
        );

        $pendingRebroadcast = $pendingRebroadcasts[0];

        $this->assertSame(
            $groupKey,
            $pendingRebroadcast->getAssignmentGroupKey()
        );

        $this->assertSame(
            $emissionId,
            $pendingRebroadcast->getEmission()?->getId()
        );
    }

    public function testDeleteSingleRebroadcastDoesNotDuplicateExistingPending(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $existingPending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $this->entityManager->persist($existingPending);
        $this->entityManager->flush();

        $parentId = $parent->getId();
        $draftId = $draft->getId();
        $pendingId = $existingPending->getId();

        $this->client->loginUser($admin);

        $this->requestJson('/admin/grid-drafts/delete', [
            'draftId' => $draftId,
            'deleteMode' => 'single',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->getJsonResponse()['success']);

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $this->assertNull($draftRepository->find($draftId));

        $parentAfterRequest = $draftRepository->find($parentId);
        $this->assertNotNull($parentAfterRequest);
        $this->assertSame(1, $parentAfterRequest->getNombreDiffusion());

        $pendings = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy(['assignmentGroupKey' => $groupKey]);

        $this->assertCount(1, $pendings);
        $this->assertSame($pendingId, $pendings[0]->getId());
        $this->assertTrue($pendings[0]->isPending());
    }

    public function testDeleteSingleRebroadcastDoesNotRecreateDismissedPending(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $dismissedPending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $dismissedPending->dismiss();

        $this->entityManager->persist($dismissedPending);
        $this->entityManager->flush();

        $parentId = $parent->getId();
        $draftId = $draft->getId();
        $pendingId = $dismissedPending->getId();

        $this->client->loginUser($admin);

        $this->requestJson('/admin/grid-drafts/delete', [
            'draftId' => $draftId,
            'deleteMode' => 'single',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->getJsonResponse()['success']);

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $this->assertNull($draftRepository->find($draftId));

        $parentAfterRequest = $draftRepository->find($parentId);
        $this->assertNotNull($parentAfterRequest);
        $this->assertSame(1, $parentAfterRequest->getNombreDiffusion());

        $pendings = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy(['assignmentGroupKey' => $groupKey]);

        $this->assertCount(1, $pendings);
        $this->assertSame($pendingId, $pendings[0]->getId());
        $this->assertTrue($pendings[0]->isDismissed());
    }

    public function testDeleteSingleRebroadcastPrioritizesDismissedOverActivePending(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $emission = $this->createEmission();
        $groupKey = 'manual_group_' . uniqid();

        $parent = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_SPECIAL,
            new \DateTimeImmutable('2026-10-06 10:00:00'),
            60,
            1,
            $groupKey
        );

        $draft = $this->createDraft(
            $emission,
            DiffusionDraft::TYPE_MANUAL_REBROADCAST,
            new \DateTimeImmutable('2026-10-07 10:00:00'),
            60,
            2,
            $groupKey
        );

        $activePending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $dismissedPending = (new PendingRebroadcast())
            ->setEmission($emission)
            ->setAssignmentGroupKey($groupKey);

        $dismissedPending->dismiss();

        $this->entityManager->persist($activePending);
        $this->entityManager->persist($dismissedPending);
        $this->entityManager->flush();

        $parentId = $parent->getId();
        $draftId = $draft->getId();
        $activeId = $activePending->getId();
        $dismissedId = $dismissedPending->getId();

        $this->client->loginUser($admin);

        $this->requestJson('/admin/grid-drafts/delete', [
            'draftId' => $draftId,
            'deleteMode' => 'single',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->getJsonResponse()['success']);

        $this->entityManager->clear();

        $draftRepository = $this->entityManager
            ->getRepository(DiffusionDraft::class);

        $this->assertNull($draftRepository->find($draftId));

        $parentAfterRequest = $draftRepository->find($parentId);
        $this->assertNotNull($parentAfterRequest);
        $this->assertSame(1, $parentAfterRequest->getNombreDiffusion());

        $pendings = $this->entityManager
            ->getRepository(PendingRebroadcast::class)
            ->findBy(['assignmentGroupKey' => $groupKey]);

        $this->assertCount(2, $pendings);

        $byId = [];
        foreach ($pendings as $pending) {
            $byId[$pending->getId()] = $pending;
        }

        $this->assertArrayHasKey($activeId, $byId);
        $this->assertArrayHasKey($dismissedId, $byId);

        $this->assertTrue($byId[$activeId]->isPending());
        $this->assertTrue($byId[$dismissedId]->isDismissed());
    }
}

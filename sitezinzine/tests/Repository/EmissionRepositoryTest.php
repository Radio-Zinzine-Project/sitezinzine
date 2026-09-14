<?php

namespace App\Tests\Repository;

use App\Entity\Categories;
use App\Entity\Diffusion;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\InviteOldAnimateur;
use App\Entity\ProgrammationRule;
use App\Entity\ProgrammationRuleSlot;
use App\Entity\Theme;
use App\Entity\User;
use App\Repository\EmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EmissionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EmissionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(EmissionRepository::class);

        $this->em->getConnection()->beginTransaction();
    }

    public function testFindWithDureeLowerThan(): void
    {
        $categorie = $this->createCategorie();

        $courte = $this->createEmission(
            $categorie,
            'Emission courte',
            new \DateTime('-2 days'),
            90
        );

        $this->createEmission(
            $categorie,
            'Emission longue',
            new \DateTime('-1 day'),
            180
        );

        $this->flush();

        $results = $this->repository->findWithDureeLowerThan(150);

        $this->assertCount(1, $results);
        $this->assertSame($courte->getId(), $results[0]->getId());
    }

    public function testPaginateEmissionsExcludesUrlAndOrdersByLastDiffusion(): void
    {
        $categorie = $this->createCategorie();

        $excludedUrl = 'https://exclude-' . $this->unique() . '.test';
        $includedUrl = 'https://include-' . $this->unique() . '.test';

        $excluded = $this->createEmission(
            $categorie,
            'Emission exclue',
            new \DateTime('-2 days'),
            60,
            $excludedUrl
        );

        $included = $this->createEmission(
            $categorie,
            'Emission incluse',
            new \DateTime('-1 day'),
            60,
            $includedUrl
        );

        $this->createDiffusion($excluded, new \DateTime('-2 hours'));
        $includedDiffusion = $this->createDiffusion($included, new \DateTime('-1 hour'));

        $this->flush();

        $pagination = $this->repository->paginateEmissions(1, $excludedUrl);

        $this->assertInstanceOf(PaginationInterface::class, $pagination);

        $items = $pagination->getItems();

        $this->assertCount(1, $items);
        $this->assertIsArray($items[0]);
        $this->assertArrayHasKey(0, $items[0]);
        $this->assertArrayHasKey('lastDiffusion', $items[0]);
        $this->assertSame($included->getId(), $items[0][0]->getId());
        $this->assertSame(
            $includedDiffusion->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            (new \DateTime($items[0]['lastDiffusion']))->format('Y-m-d H:i:s')
        );
    }

    public function testPaginateEmissionsAdminRespectsUserAndPendingStatus(): void
    {
        $categorie = $this->createCategorie();
        $user = $this->createUser('alice');
        $otherUser = $this->createUser('bob');

        $pending = $this->createEmission(
            $categorie,
            'Alpha émission en attente',
            new \DateTime('-1 day')
        )
            ->setIsPendingCompletion(true)
            ->addUser($user);

        $completed = $this->createEmission(
            $categorie,
            'Beta émission terminée',
            new \DateTime('-2 days')
        )
            ->setIsPendingCompletion(false)
            ->addUser($user);

        $otherPending = $this->createEmission(
            $categorie,
            'Gamma autre utilisateur',
            new \DateTime('-3 days')
        )
            ->setIsPendingCompletion(true)
            ->addUser($otherUser);

        $this->createDiffusion(
            $pending,
            new \DateTime('-1 hour')
        );

        $this->createDiffusion(
            $completed,
            new \DateTime('-2 hours')
        );

        $this->createDiffusion(
            $otherPending,
            new \DateTime('-30 minutes')
        );

        $this->flush();

        $pagination = $this->repository->paginateEmissionsAdmin(
            1,
            $user,
            '',
            null,
            null,
            'pending'
        );

        $items = iterator_to_array($pagination);

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertInstanceOf(Emission::class, $item);
        $this->assertSame($pending->getId(), $item->getId());
        $this->assertNotNull($item->getLastDiffusion());
    }

    public function testPaginateEmissionsAdminSupportsSearchCategoryThemeAndInitial(): void
    {
        $categorie = $this->createCategorie();
        $otherCategorie = $this->createCategorie();
        $theme = $this->createTheme('Thème admin');
        $otherTheme = $this->createTheme('Autre thème');
        $user = $this->createUser('admin-filter');

        $matching = $this->createEmission(
            $categorie,
            'Alpha programme recherché',
            new \DateTime('-1 day')
        )
            ->setTheme($theme)
            ->addUser($user);

        $this->createEmission(
            $categorie,
            'Beta autre titre',
            new \DateTime('-2 days')
        )
            ->setTheme($theme)
            ->addUser($user);

        $this->createEmission(
            $otherCategorie,
            'Alpha mauvaise catégorie',
            new \DateTime('-3 days')
        )
            ->setTheme($otherTheme)
            ->addUser($user);

        $this->flush();

        $pagination = $this->repository->paginateEmissionsAdmin(
            1,
            $user,
            'programme',
            $categorie->getId(),
            $theme->getId(),
            '',
            'A'
        );

        $items = iterator_to_array($pagination);

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertInstanceOf(Emission::class, $item);
        $this->assertSame($matching->getId(), $item->getId());
    }

    public function testPaginateEmissionsAdminCanFilterEmissionsWithoutDiffusion(): void
    {
        $categorie = $this->createCategorie();
        $user = $this->createUser('without-diffusion');

        $withoutDiffusion = $this->createEmission(
            $categorie,
            'Sans diffusion',
            new \DateTime('-1 day')
        )->addUser($user);

        $withDiffusion = $this->createEmission(
            $categorie,
            'Avec diffusion',
            new \DateTime('-2 days')
        )->addUser($user);

        $this->createDiffusion(
            $withDiffusion,
            new \DateTime('-1 hour')
        );

        $this->flush();

        $pagination = $this->repository->paginateEmissionsAdmin(
            1,
            $user,
            '',
            null,
            null,
            'without_diffusion'
        );

        $items = iterator_to_array($pagination);

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertInstanceOf(Emission::class, $item);
        $this->assertSame($withoutDiffusion->getId(), $item->getId());
    }

    public function testFindEmissionsByDateReturnsOnlyRequestedDayInChronologicalOrder(): void
    {
        $categorie = $this->createCategorie();

        $first = $this->createEmission($categorie, 'Matinale', new \DateTime('2026-09-08 08:00:00'));
        $second = $this->createEmission($categorie, 'Midi', new \DateTime('2026-09-08 12:00:00'));
        $outside = $this->createEmission($categorie, 'Demain', new \DateTime('2026-09-09 08:00:00'));

        $this->createDiffusion($second, new \DateTime('2026-09-08 12:00:00'));
        $this->createDiffusion($first, new \DateTime('2026-09-08 08:00:00'));
        $this->createDiffusion($outside, new \DateTime('2026-09-09 08:00:00'));

        $this->flush();

        $results = $this->repository->findEmissionsByDate(new \DateTime('2026-09-08'));

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame($second->getId(), $results[1]->getId());
    }

    public function testFindEmissionsByThemeGroupHydratesLastAndNextDiffusion(): void
    {
        $categorie = $this->createCategorie();
        $theme = $this->createTheme('Thème ciblé');
        $otherTheme = $this->createTheme('Thème hors groupe');

        $matching = $this->createEmission(
            $categorie,
            'Emission du thème',
            new \DateTime('-1 day')
        )->setTheme($theme);

        $outside = $this->createEmission(
            $categorie,
            'Emission autre thème',
            new \DateTime('-1 day')
        )->setTheme($otherTheme);

        $this->createDiffusion($matching, new \DateTime('-2 hours'));
        $this->createDiffusion($matching, new \DateTime('+2 hours'));
        $this->createDiffusion($outside, new \DateTime('-1 hour'));

        $this->flush();

        $results = $this->repository->findEmissionsByThemeGroup([$theme->getId()]);

        $this->assertCount(1, $results);
        $this->assertSame($matching->getId(), $results[0]->getId());
        $this->assertNotNull($results[0]->getLastDiffusion());
        $this->assertNotNull($results[0]->getNextDiffusion());
    }

    public function testPaginateEmissionsByThemeGroupReturnsEmptyPaginationForEmptyThemeList(): void
    {
        $pagination = $this->repository->paginateEmissionsByThemeGroup([], 0);

        $this->assertInstanceOf(PaginationInterface::class, $pagination);
        $this->assertCount(0, $pagination->getItems());
    }

    public function testPaginateEmissionsByThemeGroupFiltersThemeAndHydratesLastDiffusion(): void
    {
        $categorie = $this->createCategorie();
        $theme = $this->createTheme('Thème pagination');
        $otherTheme = $this->createTheme('Autre thème pagination');

        $matching = $this->createEmission(
            $categorie,
            'Emission paginée',
            new \DateTime('-1 day'),
            60,
            'https://theme-' . $this->unique() . '.test'
        )->setTheme($theme);

        $this->createEmission(
            $categorie,
            'Emission hors thème',
            new \DateTime('-2 days'),
            60,
            'https://other-theme-' . $this->unique() . '.test'
        )->setTheme($otherTheme);

        $this->createDiffusion(
            $matching,
            new \DateTime('-1 hour')
        );

        $this->flush();

        $pagination = $this->repository->paginateEmissionsByThemeGroup(
            [$theme->getId()],
            1
        );

        $items = iterator_to_array($pagination);

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertInstanceOf(Emission::class, $item);
        $this->assertSame($matching->getId(), $item->getId());
        $this->assertNotNull($item->getLastDiffusion());
    }

    public function testGetLastAndNextDiffusionReturnClosestDatesAroundNow(): void
    {
        $categorie = $this->createCategorie();
        $emission = $this->createEmission($categorie, 'Chronologie', new \DateTime('-1 day'));

        $old = $this->createDiffusion($emission, new \DateTime('2026-09-08 08:00:00'));
        $last = $this->createDiffusion($emission, new \DateTime('2026-09-08 10:00:00'));
        $next = $this->createDiffusion($emission, new \DateTime('2026-09-08 12:00:00'));
        $future = $this->createDiffusion($emission, new \DateTime('2026-09-08 14:00:00'));

        $this->flush();

        $now = new \DateTimeImmutable('2026-09-08 11:00:00');

        $lastResult = $this->repository->getLastDiffusion($emission, $now);
        $nextResult = $this->repository->getNextDiffusion($emission, $now);

        $this->assertSame(
            $last->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            $lastResult?->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            $next->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            $nextResult?->format('Y-m-d H:i:s')
        );

        $this->assertNotSame(
            $old->getHoraireDiffusion()?->format('H:i:s'),
            $lastResult?->format('H:i:s')
        );

        $this->assertNotSame(
            $future->getHoraireDiffusion()?->format('H:i:s'),
            $nextResult?->format('H:i:s')
        );
    }

    public function testGetLastAndNextDiffusionReturnNullWhenNoMatchingDateExists(): void
    {
        $categorie = $this->createCategorie();
        $emission = $this->createEmission($categorie, 'Sans diffusion utile', new \DateTime('-1 day'));

        $this->flush();

        $now = new \DateTimeImmutable('2026-09-08 11:00:00');

        $this->assertNull($this->repository->getLastDiffusion($emission, $now));
        $this->assertNull($this->repository->getNextDiffusion($emission, $now));
    }

    public function testLastEmissionsByThemeKeepsLatestEmissionForEachConfiguredGroup(): void
    {
        $connection = $this->em->getConnection();

        $connection->executeStatement("
            INSERT IGNORE INTO theme (id, name, updated_at)
            VALUES
                (1, 'Musique test', NOW()),
                (2, 'Histoire test', NOW())
        ");

        $themeMusique = $this->em->getRepository(Theme::class)->find(1);
        $themeHistoire = $this->em->getRepository(Theme::class)->find(2);

        $this->assertNotNull($themeMusique);
        $this->assertNotNull($themeHistoire);

        $categorie = $this->createCategorie();

        $oldMusic = $this->createEmission(
            $categorie,
            'Ancienne musique',
            new \DateTime('-3 days')
        )->setTheme($themeMusique);

        $recentMusic = $this->createEmission(
            $categorie,
            'Musique récente',
            new \DateTime('-1 day')
        )->setTheme($themeMusique);

        $history = $this->createEmission(
            $categorie,
            'Histoire récente',
            new \DateTime('-1 day')
        )->setTheme($themeHistoire);

        $this->createDiffusion($oldMusic, new \DateTime('-3 days'));
        $this->createDiffusion($recentMusic, new \DateTime('-1 hour'));
        $this->createDiffusion($history, new \DateTime('-2 hours'));

        $this->flush();

        $results = $this->repository->lastEmissionsByGroupTheme(
            'https://url-a-exclure.invalid'
        );

        $titles = array_column($results, 'emission_titre');

        $this->assertContains('Musique récente', $titles);
        $this->assertContains('Histoire récente', $titles);
        $this->assertNotContains('Ancienne musique', $titles);
    }

    public function testFindBySearchWithTitle(): void
    {
        $categorie = $this->createCategorie();
        $uniqueWord = 'recherche' . $this->unique();

        $emission = $this->createEmission(
            $categorie,
            'Test ' . $uniqueWord,
            new \DateTime()
        );

        $this->flush();

        $result = $this->repository->findBySearch([
            'titre' => $uniqueWord,
        ]);

        $items = $result->getItems();

        $this->assertInstanceOf(PaginationInterface::class, $result);
        $this->assertCount(1, $items);
        $this->assertSame($emission->getId(), $items[0]->getId());
    }

    public function testFindBySearchWithDateRange(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Test plage de dates',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion($emission, new \DateTime('2026-09-08 10:00:00'));

        $this->flush();

        $result = $this->repository->findBySearch([
            'dateDebut' => new \DateTime('2026-09-07 00:00:00'),
            'dateFin' => new \DateTime('2026-09-09 23:59:59'),
        ]);

        $items = $result->getItems();

        $this->assertCount(1, $items);
        $this->assertSame($emission->getId(), $items[0]->getId());
    }

    public function testFindBySearchReturnsEmptyPaginationWhenDateRangeMatchesNoDiffusion(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Hors plage',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion($emission, new \DateTime('2026-08-01 10:00:00'));

        $this->flush();

        $result = $this->repository->findBySearch([
            'dateDebut' => new \DateTime('2026-09-07 00:00:00'),
            'dateFin' => new \DateTime('2026-09-09 23:59:59'),
        ]);

        $this->assertCount(0, $result->getItems());
    }

    public function testFindBySearchWithCategory(): void
    {
        $categorie = $this->createCategorie();
        $otherCategorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Bonne catégorie',
            new \DateTime('-1 day')
        );

        $this->createEmission(
            $otherCategorie,
            'Mauvaise catégorie',
            new \DateTime('-1 day')
        );

        $this->flush();

        $result = $this->repository->findBySearch([
            'categorie' => $categorie,
        ]);

        $items = $result->getItems();

        $this->assertCount(1, $items);
        $this->assertSame($matching->getId(), $items[0]->getId());
    }

    public function testFindBySearchWithMultipleCriteria(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Test Multiple',
            new \DateTime('2026-09-08')
        );

        $this->createDiffusion($emission, new \DateTime('2026-09-08 10:00:00'));

        $this->flush();

        $result = $this->repository->findBySearch([
            'titre' => 'Multiple',
            'categorie' => $categorie,
            'dateDebut' => new \DateTime('2026-09-07 00:00:00'),
            'dateFin' => new \DateTime('2026-09-09 23:59:59'),
        ]);

        $items = $result->getItems();

        $this->assertCount(1, $items);
        $this->assertSame($emission->getId(), $items[0]->getId());
    }

    public function testFindBySearchSupportsThemeAndUserFilter(): void
    {
        $categorie = $this->createCategorie();
        $theme = $this->createTheme('Thème recherche');
        $otherTheme = $this->createTheme('Autre thème');
        $user = $this->createUser('search-user');
        $otherUser = $this->createUser('search-other');

        $matching = $this->createEmission(
            $categorie,
            'Emission personne thème',
            new \DateTime('-1 day')
        )
            ->setTheme($theme)
            ->addUser($user);

        $this->createEmission(
            $categorie,
            'Mauvais utilisateur',
            new \DateTime('-1 day')
        )
            ->setTheme($theme)
            ->addUser($otherUser);

        $this->createEmission(
            $categorie,
            'Mauvais thème',
            new \DateTime('-1 day')
        )
            ->setTheme($otherTheme)
            ->addUser($user);

        $this->flush();

        $result = $this->repository->findBySearch([
            'theme' => $theme,
            'personne' => 'user:' . $user->getId(),
        ]);

        $items = $result->getItems();

        $this->assertCount(1, $items);
        $this->assertSame($matching->getId(), $items[0]->getId());
    }

    public function testFindBySearchSupportsOldAnimatorFilter(): void
    {
        $categorie = $this->createCategorie();
        $oldAnimator = $this->createOldAnimator('Ancienne');
        $otherAnimator = $this->createOldAnimator('Autre');

        $matching = $this->createEmission(
            $categorie,
            'Emission ancien animateur',
            new \DateTime('-1 day')
        )->addInviteOldAnimateur($oldAnimator);

        $this->createEmission(
            $categorie,
            'Emission autre ancien animateur',
            new \DateTime('-1 day')
        )->addInviteOldAnimateur($otherAnimator);

        $this->flush();

        $result = $this->repository->findBySearch([
            'personne' => 'old:' . $oldAnimator->getId(),
        ]);

        $items = $result->getItems();

        $this->assertCount(1, $items);
        $this->assertSame($matching->getId(), $items[0]->getId());
    }

    public function testFindBySearchSupportsAlphabeticalAndNumericInitials(): void
    {
        $categorie = $this->createCategorie();

        $alpha = $this->createEmission($categorie, 'Alpha programme', new \DateTime('-1 day'));
        $numeric = $this->createEmission($categorie, '7 jours', new \DateTime('-2 days'));
        $this->createEmission($categorie, 'Beta programme', new \DateTime('-3 days'));

        $this->flush();

        $alphaResults = $this->repository->findBySearch([], 1, 'A')->getItems();
        $numericResults = $this->repository->findBySearch([], 1, '0-9')->getItems();

        $this->assertCount(1, $alphaResults);
        $this->assertSame($alpha->getId(), $alphaResults[0]->getId());

        $this->assertCount(1, $numericResults);
        $this->assertSame($numeric->getId(), $numericResults[0]->getId());
    }

    public function testFindBySearchAdminCanFindEmissionWithoutPublicUrl(): void
    {
        $categorie = $this->createCategorie();

        $adminOnly = $this->createEmission(
            $categorie,
            'Fiche admin sans MP3',
            new \DateTime('-1 day')
        );

        $adminOnly->setUrl(null);

        $this->flush();

        $adminResults = $this->repository->findBySearchAdmin([
            'titre' => 'Fiche admin',
        ])->getItems();

        $publicResults = $this->repository->findBySearch([
            'titre' => 'Fiche admin',
        ])->getItems();

        $this->assertCount(1, $adminResults);
        $this->assertSame($adminOnly->getId(), $adminResults[0]->getId());
        $this->assertCount(0, $publicResults);
    }

    public function testFindBySearchAdminSupportsThemeAndOldAnimator(): void
    {
        $categorie = $this->createCategorie();
        $theme = $this->createTheme('Thème admin recherche');
        $oldAnimator = $this->createOldAnimator('Animatrice');

        $matching = $this->createEmission(
            $categorie,
            'Emission admin ciblée',
            new \DateTime('-1 day'),
            60,
            null
        )
            ->setTheme($theme)
            ->addInviteOldAnimateur($oldAnimator);

        $this->createEmission(
            $categorie,
            'Emission admin hors filtre',
            new \DateTime('-1 day'),
            60,
            null
        );

        $this->flush();

        $result = $this->repository->findBySearchAdmin([
            'theme' => $theme,
            'personne' => 'old:' . $oldAnimator->getId(),
        ]);

        $items = $result->getItems();

        $this->assertCount(1, $items);
        $this->assertSame($matching->getId(), $items[0]->getId());
    }

    public function testFindBySearchAdminSupportsDateRange(): void
    {
        $categorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Emission dans la période',
            new \DateTime('2026-09-01')
        );

        $outside = $this->createEmission(
            $categorie,
            'Emission hors période',
            new \DateTime('2026-09-01')
        );

        $this->createDiffusion(
            $matching,
            new \DateTime('2026-09-08 10:00:00')
        );

        $this->createDiffusion(
            $outside,
            new \DateTime('2026-08-01 10:00:00')
        );

        $this->flush();

        $pagination = $this->repository->findBySearchAdmin([
            'dateDebut' => new \DateTime('2026-09-07 00:00:00'),
            'dateFin' => new \DateTime('2026-09-09 23:59:59'),
        ]);

        $items = $pagination->getItems();

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertInstanceOf(Emission::class, $item);
        $this->assertSame($matching->getId(), $item->getId());
    }

    public function testFindBySearchAdminSupportsCategoryAndUserFilter(): void
    {
        $categorie = $this->createCategorie();
        $otherCategorie = $this->createCategorie();

        $user = $this->createUser('admin-search-user');
        $otherUser = $this->createUser('admin-search-other');

        $matching = $this->createEmission(
            $categorie,
            'Emission recherchée',
            new \DateTime('-1 day')
        )->addUser($user);

        $this->createEmission(
            $categorie,
            'Mauvais utilisateur',
            new \DateTime('-2 days')
        )->addUser($otherUser);

        $this->createEmission(
            $otherCategorie,
            'Mauvaise catégorie',
            new \DateTime('-3 days')
        )->addUser($user);

        $this->flush();

        $pagination = $this->repository->findBySearchAdmin([
            'categorie' => $categorie,
            'personne' => 'user:' . $user->getId(),
        ]);

        $items = $pagination->getItems();

        $this->assertCount(1, $items);

        $item = $items[0];

        $this->assertInstanceOf(Emission::class, $item);
        $this->assertSame($matching->getId(), $item->getId());
    }

    public function testFindBySearchAdminSupportsAlphabeticalAndNumericInitials(): void
    {
        $categorie = $this->createCategorie();

        $alpha = $this->createEmission(
            $categorie,
            'Alpha programme',
            new \DateTime('-1 day')
        );

        $numeric = $this->createEmission(
            $categorie,
            '7 jours',
            new \DateTime('-2 days')
        );

        $this->createEmission(
            $categorie,
            'Beta programme',
            new \DateTime('-3 days')
        );

        $this->flush();

        $alphaPagination = $this->repository->findBySearchAdmin(
            [],
            1,
            'A'
        );

        $alphaItems = $alphaPagination->getItems();

        $this->assertCount(1, $alphaItems);

        $alphaItem = $alphaItems[0];

        $this->assertInstanceOf(Emission::class, $alphaItem);
        $this->assertSame($alpha->getId(), $alphaItem->getId());

        $numericPagination = $this->repository->findBySearchAdmin(
            [],
            1,
            '0-9'
        );

        $numericItems = $numericPagination->getItems();

        $this->assertCount(1, $numericItems);

        $numericItem = $numericItems[0];

        $this->assertInstanceOf(Emission::class, $numericItem);
        $this->assertSame($numeric->getId(), $numericItem->getId());
    }

    public function testFindLastDiffusionDateReturnsLatestDateOrNull(): void
    {
        $categorie = $this->createCategorie();

        $withDiffusions = $this->createEmission(
            $categorie,
            'Avec historique',
            new \DateTime('-1 day')
        );

        $withoutDiffusion = $this->createEmission(
            $categorie,
            'Sans historique',
            new \DateTime('-1 day')
        );

        $this->createDiffusion($withDiffusions, new \DateTime('2026-09-01 10:00:00'));
        $latest = $this->createDiffusion($withDiffusions, new \DateTime('2026-09-08 18:00:00'));

        $this->flush();

        $result = $this->repository->findLastDiffusionDate($withDiffusions->getId());

        $this->assertSame(
            $latest->getHoraireDiffusion()?->format('Y-m-d H:i:s'),
            $result?->format('Y-m-d H:i:s')
        );

        $this->assertNull(
            $this->repository->findLastDiffusionDate($withoutDiffusion->getId())
        );
    }

    public function testFindLatestByCategoryUsesLatestPastDiffusionAndLimit(): void
    {
        $categorie = $this->createCategorie();
        $otherCategorie = $this->createCategorie();

        $older = $this->createEmission($categorie, 'Plus ancienne', new \DateTime('-3 days'));
        $latest = $this->createEmission($categorie, 'Plus récente', new \DateTime('-2 days'));
        $outside = $this->createEmission($otherCategorie, 'Autre catégorie', new \DateTime('-1 day'));

        $this->createDiffusion($older, new \DateTime('-3 hours'));
        $this->createDiffusion($latest, new \DateTime('-1 hour'));
        $this->createDiffusion($outside, new \DateTime('-30 minutes'));

        $this->flush();

        $results = $this->repository->findLatestByCategory(
            $categorie->getId(),
            1
        );

        $this->assertCount(1, $results);
        $this->assertSame($latest->getId(), $results[0]->getId());
        $this->assertNotNull($results[0]->getLastDiffusion());
    }

    public function testCreateLatestByCategoryQueryBuilderFiltersCategoryAndOrdersByNewestId(): void
    {
        $categorie = $this->createCategorie();
        $otherCategorie = $this->createCategorie();

        $first = $this->createEmission($categorie, 'Première', new \DateTime('-2 days'));
        $second = $this->createEmission($categorie, 'Deuxième', new \DateTime('-1 day'));
        $this->createEmission($otherCategorie, 'Hors catégorie', new \DateTime());

        $this->flush();

        $results = $this->repository
            ->createLatestByCategoryQueryBuilder($categorie->getId())
            ->getQuery()
            ->getResult();

        $this->assertCount(2, $results);
        $this->assertSame($second->getId(), $results[0]->getId());
        $this->assertSame($first->getId(), $results[1]->getId());
    }

    public function testFindAssignableForCategoryReturnsOnlyActiveNonDeletedCategoryEmissions(): void
    {
        $categorie = $this->createCategorie(active: true, softDelete: false);
        $otherCategorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Assignable',
            new \DateTime('-1 day')
        );

        $deleted = $this->createEmission(
            $categorie,
            'Supprimée',
            new \DateTime('-2 days')
        )->softDelete();

        $this->createEmission(
            $otherCategorie,
            'Autre catégorie',
            new \DateTime('-3 days')
        );

        $this->flush();

        $results = $this->repository->findAssignableForCategory($categorie);

        $ids = array_map(
            static fn(Emission $emission): ?int => $emission->getId(),
            $results
        );

        $this->assertContains($matching->getId(), $ids);
        $this->assertNotContains($deleted->getId(), $ids);
        $this->assertCount(1, $results);
    }

    public function testFindLatestFirstPassCandidatesByCategoryRespectsCategoryOrderAndLimit(): void
    {
        $categorie = $this->createCategorie();
        $otherCategorie = $this->createCategorie();

        $older = $this->createEmission(
            $categorie,
            'Ancienne candidate',
            new \DateTime('2026-09-01')
        );

        $newer = $this->createEmission(
            $categorie,
            'Candidate récente',
            new \DateTime('2026-09-08')
        );

        $this->createEmission(
            $otherCategorie,
            'Candidate autre catégorie',
            new \DateTime('2026-09-09')
        );

        $this->flush();

        $results = $this->repository->findLatestFirstPassCandidatesByCategory(
            $categorie,
            1
        );

        $this->assertCount(1, $results);
        $this->assertSame($newer->getId(), $results[0]->getId());
        $this->assertNotSame($older->getId(), $results[0]->getId());
    }

    public function testRegularSpecialCandidatesReturnAllNonAutoGeneratedEmissionsAndKeepPlayCount(): void
    {
        $categorie = $this->createCategorie();

        $zero = $this->createEmission(
            $categorie,
            'Spéciale zéro',
            new \DateTime('-1 day')
        );

        $two = $this->createEmission(
            $categorie,
            'Spéciale deux',
            new \DateTime('-2 days')
        );

        $three = $this->createEmission(
            $categorie,
            'Spéciale trois',
            new \DateTime('-3 days')
        );

        $auto = $this->createEmission(
            $categorie,
            'Spéciale auto',
            new \DateTime('-4 days')
        )->setIsAutoGenerated(true);

        $this->createDiffusion($two, new \DateTime('-5 hours'), 1);
        $this->createDiffusion($two, new \DateTime('-4 hours'), 2);

        $this->createDiffusion($three, new \DateTime('-3 hours'), 1);
        $this->createDiffusion($three, new \DateTime('-2 hours'), 2);
        $this->createDiffusion($three, new \DateTime('-1 hour'), 3);

        $this->flush();

        $rows = $this->repository->findSpecialCandidatesForRegularCategory(
            $categorie,
            null
        );

        $ids = array_map(
            static fn(array $row): ?int => $row['emission']->getId(),
            $rows
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['emission']->getId()] = $row['playCount'];
        }

        $this->assertCount(3, $rows);
        $this->assertContains($zero->getId(), $ids);
        $this->assertContains($two->getId(), $ids);
        $this->assertContains($three->getId(), $ids);
        $this->assertNotContains($auto->getId(), $ids);

        $this->assertSame(0, $counts[$zero->getId()]);
        $this->assertSame(2, $counts[$two->getId()]);
        $this->assertSame(3, $counts[$three->getId()]);

        $this->assertSame(
            3,
            $this->repository->countSpecialCandidatesForRegularCategory($categorie)
        );
    }

    public function testRegularSpecialCandidatesSupportSearch(): void
    {
        $categorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Chronique spéciale',
            new \DateTime('-1 day')
        );

        $this->createEmission(
            $categorie,
            'Magazine du soir',
            new \DateTime('-2 days')
        );

        $this->flush();

        $rows = $this->repository->findSpecialCandidatesForRegularCategory(
            $categorie,
            20,
            'chronique'
        );

        $this->assertCount(1, $rows);
        $this->assertSame($matching->getId(), $rows[0]['emission']->getId());
        $this->assertSame(
            1,
            $this->repository->countSpecialCandidatesForRegularCategory(
                $categorie,
                'chronique'
            )
        );
    }

    public function testNonRegularSpecialCandidatesIncludeEmissionsRegardlessOfPlayCount(): void
    {
        $categorie = $this->createCategorie();

        $zero = $this->createEmission(
            $categorie,
            'Non régulière zéro',
            new \DateTime('-1 day')
        );

        $three = $this->createEmission(
            $categorie,
            'Non régulière trois',
            new \DateTime('-2 days')
        );

        $auto = $this->createEmission(
            $categorie,
            'Non régulière auto',
            new \DateTime('-3 days')
        )->setIsAutoGenerated(true);

        $this->createDiffusion($three, new \DateTime('-3 hours'), 1);
        $this->createDiffusion($three, new \DateTime('-2 hours'), 2);
        $this->createDiffusion($three, new \DateTime('-1 hour'), 3);

        $this->flush();

        $rows = $this->repository->findSpecialCandidatesForNonRegularCategory(
            $categorie,
            null
        );

        $ids = array_map(
            static fn(array $row): ?int => $row['emission']->getId(),
            $rows
        );

        $this->assertCount(2, $rows);
        $this->assertContains($zero->getId(), $ids);
        $this->assertContains($three->getId(), $ids);
        $this->assertNotContains($auto->getId(), $ids);
        $this->assertSame(
            2,
            $this->repository->countSpecialCandidatesForNonRegularCategory($categorie)
        );
    }

    public function testNonRegularSpecialCandidatesSupportSearch(): void
    {
        $categorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Documentaire spécial',
            new \DateTime('-1 day')
        );

        $this->createEmission(
            $categorie,
            'Magazine musical',
            new \DateTime('-2 days')
        );

        $this->flush();

        $rows = $this->repository->findSpecialCandidatesForNonRegularCategory(
            $categorie,
            20,
            'documentaire'
        );

        $this->assertCount(1, $rows);
        $this->assertSame($matching->getId(), $rows[0]['emission']->getId());
        $this->assertSame(
            1,
            $this->repository->countSpecialCandidatesForNonRegularCategory(
                $categorie,
                'documentaire'
            )
        );
    }

    public function testFindAutoGeneratedForSlotAndStartsAtReturnsMatchingEmission(): void
    {
        $categorie = $this->createCategorie();

        $rule = new ProgrammationRule();
        $rule->setCategory($categorie);
        $rule->setValidFrom(new \DateTimeImmutable('2026-09-01'));
        $rule->setIsActive(true);
        $rule->setRuleNumber(1);

        $this->em->persist($rule);

        $slot = new ProgrammationRuleSlot();
        $slot->setRule($rule);
        $slot->setDayOfWeek(2);
        $slot->setStartTime(new \DateTimeImmutable('10:00'));
        $slot->setDurationMinutes(60);
        $slot->setBroadcastRank(1);
        $slot->setIsActive(true);

        $this->em->persist($slot);

        $storedStartsAt = new \DateTime('2026-09-08 10:00:00');

        $emission = $this->createEmission(
            $categorie,
            'Émission auto générée',
            new \DateTime('2026-09-08')
        )
            ->setIsAutoGenerated(true)
            ->setAutoGeneratedForSlot($slot)
            ->setAutoGeneratedForStartsAt($storedStartsAt);

        $this->flush();

        $result = $this->repository->findAutoGeneratedForSlotAndStartsAt(
            $slot,
            new \DateTimeImmutable('2026-09-08 10:00:00')
        );

        $this->assertSame($emission->getId(), $result?->getId());
    }

    public function testFindAutoGeneratedForSlotAndStartsAtReturnsNullForAnotherStartTime(): void
    {
        $categorie = $this->createCategorie();

        $rule = new ProgrammationRule();
        $rule->setCategory($categorie);
        $rule->setValidFrom(new \DateTimeImmutable('2026-09-01'));
        $rule->setIsActive(true);
        $rule->setRuleNumber(1);

        $this->em->persist($rule);

        $slot = new ProgrammationRuleSlot();
        $slot->setRule($rule);
        $slot->setDayOfWeek(2);
        $slot->setStartTime(new \DateTimeImmutable('10:00'));
        $slot->setDurationMinutes(60);
        $slot->setBroadcastRank(1);
        $slot->setIsActive(true);

        $this->em->persist($slot);

        $this->createEmission(
            $categorie,
            'Émission auto générée autre horaire',
            new \DateTime('2026-09-08')
        )
            ->setIsAutoGenerated(true)
            ->setAutoGeneratedForSlot($slot)
            ->setAutoGeneratedForStartsAt(new \DateTime('2026-09-08 10:00:00'));

        $this->flush();

        $result = $this->repository->findAutoGeneratedForSlotAndStartsAt(
            $slot,
            new \DateTimeImmutable('2026-09-08 11:00:00')
        );

        $this->assertNull($result);
    }

    public function testPendingCompletionMethodsRespectUserAndPendingState(): void
    {
        $categorie = $this->createCategorie();
        $user = $this->createUser('pending-user');
        $otherUser = $this->createUser('pending-other');

        $userPending = $this->createEmission(
            $categorie,
            'À compléter utilisateur',
            new \DateTime('-1 day')
        )
            ->setIsPendingCompletion(true)
            ->addUser($user);

        $this->createEmission(
            $categorie,
            'Terminée utilisateur',
            new \DateTime('-2 days')
        )
            ->setIsPendingCompletion(false)
            ->addUser($user);

        $otherPending = $this->createEmission(
            $categorie,
            'À compléter autre utilisateur',
            new \DateTime('-3 days')
        )
            ->setIsPendingCompletion(true)
            ->addUser($otherUser);

        $this->flush();

        $forUser = $this->repository->findPendingCompletionForUser($user);
        $all = $this->repository->findAllPendingCompletion();

        $this->assertCount(1, $forUser);
        $this->assertSame($userPending->getId(), $forUser[0]->getId());
        $this->assertSame(1, $this->repository->countPendingCompletionForUser($user));

        $allIds = array_map(
            static fn(Emission $emission): ?int => $emission->getId(),
            $all
        );

        $this->assertCount(2, $all);
        $this->assertContains($userPending->getId(), $allIds);
        $this->assertContains($otherPending->getId(), $allIds);
        $this->assertSame(2, $this->repository->countAllPendingCompletion());
    }

    public function testPendingCompletionMethodsExcludeSoftDeletedEmissions(): void
    {
        $categorie = $this->createCategorie();
        $user = $this->createUser('pending-deleted');

        $visible = $this->createEmission(
            $categorie,
            'Visible à compléter',
            new \DateTime('-1 day')
        )
            ->setIsPendingCompletion(true)
            ->addUser($user);

        $deleted = $this->createEmission(
            $categorie,
            'Supprimée à compléter',
            new \DateTime('-2 days')
        )
            ->setIsPendingCompletion(true)
            ->addUser($user)
            ->softDelete();

        $this->flush();

        $results = $this->repository->findPendingCompletionForUser($user);

        $this->assertCount(1, $results);
        $this->assertSame($visible->getId(), $results[0]->getId());
        $this->assertNotSame($deleted->getId(), $results[0]->getId());
        $this->assertSame(1, $this->repository->countPendingCompletionForUser($user));
        $this->assertSame(1, $this->repository->countAllPendingCompletion());
    }

    public function testFindGridCandidatesByCategoryReturnsOnlyRequestedCategory(): void
    {
        $categorie = $this->createCategorie();
        $otherCategorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Candidate attendue',
            new \DateTime('-1 day')
        )->setIsAutoGenerated(false);

        $this->createEmission(
            $otherCategorie,
            'Candidate autre catégorie',
            new \DateTime('-2 days')
        )->setIsAutoGenerated(false);

        $this->flush();

        $results = $this->repository->findGridCandidatesByCategory($categorie);

        $this->assertCount(1, $results);
        $this->assertSame($matching->getId(), $results[0]->getId());
    }

    public function testFindGridCandidatesByCategoryExcludesAutoGeneratedEmissions(): void
    {
        $categorie = $this->createCategorie();

        $normal = $this->createEmission(
            $categorie,
            'Émission normale',
            new \DateTime('-1 day')
        )->setIsAutoGenerated(false);

        $this->createEmission(
            $categorie,
            'Émission auto générée',
            new \DateTime('-2 days')
        )->setIsAutoGenerated(true);

        $this->flush();

        $results = $this->repository->findGridCandidatesByCategory($categorie);

        $this->assertCount(1, $results);
        $this->assertSame($normal->getId(), $results[0]->getId());
    }

    public function testFindGridCandidatesByCategoryFiltersByTitle(): void
    {
        $categorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Chroniques du matin',
            new \DateTime('-1 day')
        );

        $this->createEmission(
            $categorie,
            'Magazine du soir',
            new \DateTime('-2 days')
        );

        $this->flush();

        $results = $this->repository->findGridCandidatesByCategory(
            $categorie,
            'Chroniques'
        );

        $this->assertCount(1, $results);
        $this->assertSame($matching->getId(), $results[0]->getId());
    }

    public function testFindGridCandidatesByCategoryFiltersByMultipleTitleWords(): void
    {
        $categorie = $this->createCategorie();

        $matching = $this->createEmission(
            $categorie,
            'Chroniques musicales du matin',
            new \DateTime('-1 day')
        );

        $this->createEmission(
            $categorie,
            'Chroniques politiques du soir',
            new \DateTime('-2 days')
        );

        $this->flush();

        $results = $this->repository->findGridCandidatesByCategory(
            $categorie,
            'Chroniques musicales'
        );

        $this->assertCount(1, $results);
        $this->assertSame($matching->getId(), $results[0]->getId());
    }

    public function testFindGridCandidatesByCategorySupportsSimplePluralWithoutMatchingUnrelatedWords(): void
    {
        $categorie = $this->createCategorie();

        $chroniques = $this->createEmission(
            $categorie,
            'Chroniques du matin',
            new \DateTime('-1 day')
        );

        $this->createEmission(
            $categorie,
            'Le rapporteur du jour',
            new \DateTime('-2 days')
        );

        $rap = $this->createEmission(
            $categorie,
            'Magazine rap',
            new \DateTime('-3 days')
        );

        $this->flush();

        $chroniqueResults = $this->repository->findGridCandidatesByCategory(
            $categorie,
            'chronique'
        );

        $rapResults = $this->repository->findGridCandidatesByCategory(
            $categorie,
            'rap'
        );

        $this->assertCount(1, $chroniqueResults);
        $this->assertSame($chroniques->getId(), $chroniqueResults[0]->getId());

        $this->assertCount(1, $rapResults);
        $this->assertSame($rap->getId(), $rapResults[0]->getId());
    }

    public function testFindGridCandidatesByCategoryRespectsLimitAndNewestPublicationOrder(): void
    {
        $categorie = $this->createCategorie();

        $this->createEmission(
            $categorie,
            'Ancienne candidate',
            new \DateTime('2026-09-01')
        );

        $newest = $this->createEmission(
            $categorie,
            'Nouvelle candidate',
            new \DateTime('2026-09-08')
        );

        $this->flush();

        $results = $this->repository->findGridCandidatesByCategory(
            $categorie,
            null,
            false,
            1
        );

        $this->assertCount(1, $results);
        $this->assertSame($newest->getId(), $results[0]->getId());
    }

    public function testFindProgramForDateBuildsProgramAndSelectsCurrentEmission(): void
    {
        $categorie = $this->createCategorie();

        $first = $this->createEmission(
            $categorie,
            'Première émission',
            new \DateTime('2026-09-08'),
            120
        );

        $second = $this->createEmission(
            $categorie,
            'Deuxième émission',
            new \DateTime('2026-09-08'),
            60
        );

        /*
         * La première diffusion annonce 2 heures, mais la suivante démarre à 11h.
         * À 11h30, la première ne doit donc plus être considérée en direct.
         */
        $this->createDiffusion(
            $first,
            new \DateTime('2026-09-08 10:00:00'),
            1,
            120
        );

        $this->createDiffusion(
            $second,
            new \DateTime('2026-09-08 11:00:00'),
            1,
            60
        );

        $this->flush();

        $program = $this->repository->findProgramForDate(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable(
                '2026-09-08 11:30:00',
                new \DateTimeZone('Europe/Paris')
            )
        );

        $items = $program['items'];

        $this->assertCount(2, $items);
        $this->assertSame($first->getId(), $items[0]['emission']->getId());
        $this->assertFalse($items[0]['isCurrent']);
        $this->assertSame($second->getId(), $items[1]['emission']->getId());
        $this->assertTrue($items[1]['isCurrent']);
        $this->assertSame(1, $program['activeIndex']);
    }

    public function testFindProgramForDateUsesExplicitEndsAtBeforeFallbackDuration(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Émission fin explicite',
            new \DateTime('2026-09-08'),
            120
        );

        $diffusion = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-08 10:00:00'),
            1,
            120
        );

        $diffusion->setEndsAt(new \DateTime('2026-09-08 10:45:00'));

        $this->flush();

        $program = $this->repository->findProgramForDate(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable(
                '2026-09-08 10:50:00',
                new \DateTimeZone('Europe/Paris')
            )
        );

        $this->assertCount(1, $program['items']);
        $this->assertSame(
            '2026-09-08 10:45:00',
            $program['items'][0]['endDiffusion']->format('Y-m-d H:i:s')
        );
        $this->assertFalse($program['items'][0]['isCurrent']);
    }

    public function testFindProgramForDateFallsBackToEmissionDurationForLegacyDiffusion(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Ancienne diffusion',
            new \DateTime('2026-09-08'),
            45
        );

        $diffusion = new Diffusion();
        $diffusion
            ->setEmission($emission)
            ->setHoraireDiffusion(new \DateTime('2026-09-08 10:00:00'))
            ->setNombreDiffusion(1);

        $this->em->persist($diffusion);
        $this->flush();

        $program = $this->repository->findProgramForDate(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable(
                '2026-09-08 10:30:00',
                new \DateTimeZone('Europe/Paris')
            )
        );

        $this->assertCount(1, $program['items']);
        $this->assertSame(
            '2026-09-08 10:45:00',
            $program['items'][0]['endDiffusion']->format('Y-m-d H:i:s')
        );
        $this->assertTrue($program['items'][0]['isCurrent']);
        $this->assertSame(0, $program['activeIndex']);
    }

    public function testFindProgramForDateSuppressesDuplicateEmissionAtSameStartTime(): void
    {
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            $categorie,
            'Emission dupliquée',
            new \DateTime('2026-09-08'),
            60
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-08 10:00:00'),
            1,
            60
        );

        $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-08 10:00:00'),
            2,
            60
        );

        $this->flush();

        $program = $this->repository->findProgramForDate(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable(
                '2026-09-08 10:30:00',
                new \DateTimeZone('Europe/Paris')
            )
        );

        $this->assertCount(1, $program['items']);
        $this->assertSame($emission->getId(), $program['items'][0]['emission']->getId());
    }

    public function testFindProgramForDateUsesLastStartedEmissionAsActiveIndexWhenNothingIsCurrent(): void
    {
        $categorie = $this->createCategorie();

        $first = $this->createEmission($categorie, 'Matin', new \DateTime('2026-09-08'), 30);
        $second = $this->createEmission($categorie, 'Midi', new \DateTime('2026-09-08'), 30);

        $this->createDiffusion($first, new \DateTime('2026-09-08 08:00:00'), 1, 30);
        $this->createDiffusion($second, new \DateTime('2026-09-08 12:00:00'), 1, 30);

        $this->flush();

        $program = $this->repository->findProgramForDate(
            new \DateTimeImmutable('2026-09-08'),
            new \DateTimeImmutable(
                '2026-09-08 14:00:00',
                new \DateTimeZone('Europe/Paris')
            )
        );

        $this->assertFalse($program['items'][0]['isCurrent']);
        $this->assertFalse($program['items'][1]['isCurrent']);
        $this->assertSame(1, $program['activeIndex']);
    }

    public function testFindCategoriesAndThemesForUserReturnOnlyUsedRelations(): void
    {
        $user = $this->createUser('relations-user');
        $otherUser = $this->createUser('relations-other');

        $categorieA = $this->createCategorie(title: 'Alpha catégorie');
        $categorieB = $this->createCategorie(title: 'Beta catégorie');
        $categorieOther = $this->createCategorie(title: 'Gamma catégorie');

        $themeA = $this->createTheme('Alpha thème');
        $themeB = $this->createTheme('Beta thème');
        $themeOther = $this->createTheme('Gamma thème');

        $this->createEmission(
            $categorieB,
            'Emission B',
            new \DateTime('-1 day')
        )
            ->setTheme($themeB)
            ->addUser($user);

        $this->createEmission(
            $categorieA,
            'Emission A',
            new \DateTime('-2 days')
        )
            ->setTheme($themeA)
            ->addUser($user);

        $this->createEmission(
            $categorieOther,
            'Emission autre utilisateur',
            new \DateTime('-3 days')
        )
            ->setTheme($themeOther)
            ->addUser($otherUser);

        $this->flush();

        $categories = $this->repository->findCategoriesForUser($user);
        $themes = $this->repository->findThemesForUser($user);

        $this->assertCount(2, $categories);
        $this->assertSame('Alpha catégorie', $categories[0]->getTitre());
        $this->assertSame('Beta catégorie', $categories[1]->getTitre());

        $this->assertCount(2, $themes);
        $this->assertSame($themeA->getId(), $themes[0]->getId());
        $this->assertSame($themeB->getId(), $themes[1]->getId());
    }

    private function createEmission(
        Categories $categorie,
        string $title,
        \DateTime $datepub,
        int $duration = 60,
        ?string $url = null
    ): Emission {
        $emission = (new Emission())
            ->setTitre($title)
            ->setKeyword('test')
            ->setDatepub($datepub)
            ->setRef('REF-' . $this->unique())
            ->setDuree($duration)
            ->setUrl($url ?? 'https://emission-' . $this->unique() . '.test')
            ->setDescriptif('Description de test')
            ->setCategorie($categorie)
            ->setUpdatedAt(new \DateTime())
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->em->persist($emission);

        return $emission;
    }

    private function createDiffusion(
        Emission $emission,
        \DateTime $startsAt,
        int $number = 1,
        ?int $durationMinutes = null
    ): Diffusion {
        $diffusion = new Diffusion();
        $diffusion
            ->setEmission($emission)
            ->setHoraireDiffusion($startsAt)
            ->setNombreDiffusion($number);

        if ($durationMinutes !== null) {
            $diffusion->setDurationMinutes($durationMinutes);
        }

        $this->em->persist($diffusion);

        return $diffusion;
    }

    private function createCategorie(
        ?string $title = null,
        bool $active = true,
        bool $softDelete = false
    ): Categories {
        $categorie = (new Categories())
            ->setTitre($title ?? 'Catégorie test ' . $this->unique())
            ->setDuree(60)
            ->setActive($active)
            ->setSoftDelete($softDelete)
            ->setDescriptif('Description catégorie test')
            ->setUpdatedAt(new \DateTime())
            ->setEditeur($this->createEditeur());

        $this->em->persist($categorie);

        return $categorie;
    }

    private function createEditeur(): Editeur
    {
        $editeur = new Editeur();
        $editeur->setName('Éditeur test ' . $this->unique());
        $editeur->setUpdateAt(new \DateTime());

        $this->em->persist($editeur);

        return $editeur;
    }

    private function createUser(string $prefix): User
    {
        $unique = $this->unique();

        $user = (new User())
            ->setUsername($prefix . '-' . $unique)
            ->setEmail($prefix . '-' . $unique . '@example.test')
            ->setPassword('password-test')
            ->setRoles(['ROLE_USER'])
            ->setVerified(true);

        $this->em->persist($user);

        return $user;
    }

    private function createTheme(string $name): Theme
    {
        $theme = (new Theme())
            ->setName($name . ' ' . $this->unique())
            ->setUpdatedAt(new \DateTime());

        $this->em->persist($theme);

        return $theme;
    }

    private function createOldAnimator(string $firstName): InviteOldAnimateur
    {
        $animator = (new InviteOldAnimateur())
            ->setFirstName($firstName)
            ->setLastName('Test ' . $this->unique())
            ->setAncienanimateur(true);

        $this->em->persist($animator);

        return $animator;
    }

    private function flush(): void
    {
        $this->em->flush();
    }

    private function unique(): string
    {
        return bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $this->em->close();

        parent::tearDown();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Entity\Theme;
use App\Repository\EmissionRepository;
use App\Repository\ThemeRepository;
use App\Twig\Components\RelatedEmissions;
use Knp\Component\Pager\Pagination\PaginationInterface;
use PHPUnit\Framework\TestCase;

final class RelatedEmissionsTest extends TestCase
{
    public function testMountDefaultsToAnEmptyGroupWithoutSelectionOnFirstPage(): void
    {
        $component = $this->createComponent();
        $component->mount();

        self::assertSame([], $component->groupIds);
        self::assertSame([], $component->selected);
        self::assertSame(1, $component->page);
    }

    /** @dataProvider mountCases */
    public function testMountNormalizesIdsAndKeepsOnlyTheFirstSelection(?array $selected, int $page, array $expectedSelection, int $expectedPage): void
    {
        $component = $this->createComponent();
        $component->mount(['12', '34'], $selected, $page);

        self::assertSame([12, 34], $component->groupIds);
        self::assertSame($expectedSelection, $component->selected);
        self::assertSame($expectedPage, $component->page);
    }

    public static function mountCases(): iterable
    {
        yield 'no selection' => [null, 3, [], 3];
        yield 'explicit empty selection' => [[], 0, [], 1];
        yield 'one selection' => [['34'], 2, [34], 2];
        yield 'multiple selections and negative page' => [['34', '12'], -2, [34], 1];
    }

    /** @dataProvider pageCases */
    public function testGoToPageClampsThePageWithoutChangingTheFilters(int $page, int $expected): void
    {
        $component = $this->createComponent();
        $component->mount([12, 34], [34], 3);
        $component->goToPage($page);

        self::assertSame($expected, $component->page);
        self::assertSame([12, 34], $component->groupIds);
        self::assertSame([34], $component->selected);
    }

    public static function pageCases(): iterable
    {
        yield [4, 4];
        yield [1, 1];
        yield [0, 1];
        yield [-3, 1];
    }

    /** @dataProvider selectionCases */
    public function testTogglingAThemeSelectsReplacesOrClearsItAndResetsPagination(array $selected, int $id, array $expected): void
    {
        $component = $this->createComponent();
        $component->mount([12, 34], $selected, 5);
        $component->toggleTheme($id);

        self::assertSame($expected, $component->selected);
        self::assertSame(1, $component->page);
        self::assertSame([12, 34], $component->groupIds);
    }

    public static function selectionCases(): iterable
    {
        yield 'select' => [[], 12, [12]];
        yield 'replace' => [[12], 34, [34]];
        yield 'clear' => [[12], 12, []];
    }

    public function testThemesAreLoadedForTheWholeGroupEvenWhenOneIsSelected(): void
    {
        $themes = [new Theme(), new Theme()];
        $repository = $this->createMock(ThemeRepository::class);
        $repository->expects(self::once())->method('findBy')
            ->with(['id' => [12, 34]])->willReturn($themes);
        $component = new RelatedEmissions($this->createMock(EmissionRepository::class), $repository);
        $component->mount([12, 34], [34]);

        self::assertSame($themes, $component->getThemesInGroup());
    }

    /** @dataProvider emissionFilterCases */
    public function testRelatedEmissionsUsesTheActiveFilterAndCurrentPage(array $groupIds, array $selected, array $expectedIds): void
    {
        $pagination = $this->createMock(PaginationInterface::class);
        $repository = $this->createMock(EmissionRepository::class);
        $repository->expects(self::once())->method('paginateEmissionsByThemeGroup')
            ->with($expectedIds, 3)->willReturn($pagination);
        $component = new RelatedEmissions($repository, $this->createMock(ThemeRepository::class));
        $component->mount($groupIds, $selected, 3);

        self::assertSame($pagination, $component->getRelatedEmissions());
    }

    public static function emissionFilterCases(): iterable
    {
        yield 'whole group' => [[12, 34], [], [12, 34]];
        yield 'selected theme' => [[12, 34], [34], [34]];
        yield 'empty group' => [[], [], []];
    }

    private function createComponent(): RelatedEmissions
    {
        return new RelatedEmissions(
            $this->createMock(EmissionRepository::class),
            $this->createMock(ThemeRepository::class)
        );
    }
}

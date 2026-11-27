<?php
namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use App\Repository\EmissionRepository;
use App\Repository\ThemeRepository;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\UX\LiveComponent\Attribute\LiveArg;

#[AsLiveComponent('related_emissions')]
class RelatedEmissions
{
    use DefaultActionTrait;

    /** Liste complète des thèmes du groupe (IDs) pour cette page */
    #[LiveProp]
    public array $groupIds = [];

    /** Thèmes actuellement sélectionnés (IDs) */
    #[LiveProp(writable: true)]
    public array $selected = [];

    /** Page courante */
    #[LiveProp(writable: true)]
    public int $page = 1;

    public function __construct(
        private EmissionRepository $emissionRepository,
        private ThemeRepository $themeRepository,
    ) {}

    public function mount(array $groupIds = [], ?array $selected = null, int $page = 1): void
    {
        $this->groupIds = array_map('intval', $groupIds);
        // par défaut : rien de sélectionné
        $this->selected = $selected !== null ? array_map('intval', $selected) : [];
        // si jamais on reçoit plusieurs ids, on force le single-select
        if (\count($this->selected) > 1) {
            $this->selected = [ (int) $this->selected[0] ];
        }
        $this->page = max(1, $page);
    }

    public function getThemesInGroup(): array
    {
        return $this->themeRepository->findBy(['id' => $this->groupIds]);
    }

    public function getRelatedEmissions(): PaginationInterface
    {
        // si rien n'est sélectionné => on affiche TOUT le groupe
        $ids = empty($this->selected) ? $this->groupIds : $this->selected;
        return $this->emissionRepository->paginateEmissionsByThemeGroup($ids, $this->page);
    }

    #[LiveAction]
    public function goToPage(#[LiveArg] int $page): void
    {
        $this->page = max(1, $page);
    }

    #[LiveAction]
public function toggleTheme(#[LiveArg] int $id): void
{
    $id = (int) $id;

    // si déjà sélectionné => on désactive tout
    if (!empty($this->selected) && $this->selected[0] === $id) {
        $this->selected = [];
    } else {
        // sélection unique : on remplace par [id]
        $this->selected = [$id];
    }

    $this->page = 1; // reset pagination
}


}

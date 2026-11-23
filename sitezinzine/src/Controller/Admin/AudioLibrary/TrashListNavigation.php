<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Enum\Mp3TrashPhase;
use App\Service\AudioLibrary\Mp3TrashList;

final class TrashListNavigation
{
    public static function filters(array $query): array
    {
        $filters = [];
        $phase = $query['phase'] ?? null;
        if (is_string($phase) && ($phase === '' || Mp3TrashPhase::tryFrom($phase) !== null)) {
            $filters['phase'] = $phase;
        }
        $blocked = $query['blocked'] ?? null;
        if (in_array($blocked, ['', '0', '1'], true)) {
            $filters['blocked'] = $blocked;
        }
        $page = $query['page'] ?? null;
        if (is_string($page) && filter_var($page, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, Mp3TrashList::PAGE_SIZE)],
        ]) !== false) {
            $filters['page'] = $page;
        }

        return $filters;
    }
}

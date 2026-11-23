<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Service\AudioLibrary\Mp3TrashHistory;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class TrashHistoryController extends AbstractController
{
    #[Route('/admin/audio-library/trash/{operationId}/history', name: 'admin.audio_library.trash.history',
        requirements: ['operationId' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function history(Request $request, string $operationId, Mp3TrashHistory $history): Response
    {
        $query = $request->query->all();
        $before = $query['before'] ?? null;
        $after = $query['after'] ?? null;
        $status = Response::HTTP_OK;
        $page = null;
        $error = null;
        if (!self::validCursor($before) || !self::validCursor($after) || ($before !== null && $after !== null)) {
            $status = Response::HTTP_BAD_REQUEST;
            $error = 'Le curseur de pagination est invalide.';
        } else {
            try {
                $page = $history->read($operationId, $before, $after);
                if ($page === null) {
                    $status = Response::HTTP_NOT_FOUND;
                    $error = 'Opération de corbeille introuvable.';
                }
            } catch (Exception) {
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
                $error = 'Le journal administratif est indisponible.';
            }
        }

        return $this->render('admin/audio_library/trash/history.html.twig', [
            'operationId' => $operationId,
            'history' => $page,
            'error' => $error,
            'hasCursor' => $before !== null || $after !== null,
            'filters' => TrashListNavigation::filters($query),
        ], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }

    private static function validCursor(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/\A[1-9][0-9]{0,19}\z/', $value) === 1
            && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0));
    }
}

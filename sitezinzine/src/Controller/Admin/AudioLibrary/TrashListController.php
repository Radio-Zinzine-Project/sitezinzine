<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Enum\Mp3TrashPhase;
use App\Service\AudioLibrary\Mp3TrashList;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class TrashListController extends AbstractController
{
    #[Route('/admin/audio-library/trash', name: 'admin.audio_library.trash.index', methods: ['GET'])]
    public function index(Request $request, Mp3TrashList $list): Response
    {
        $query = $request->query->all();
        $phaseValue = $query['phase'] ?? '';
        $blocked = $query['blocked'] ?? '';
        $rawPage = $query['page'] ?? '1';
        $phase = is_string($phaseValue) ? Mp3TrashPhase::tryFrom($phaseValue) : null;
        $page = is_string($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, Mp3TrashList::PAGE_SIZE)],
        ]) : false;
        $filters = [
            'phase' => $phase?->value ?? '',
            'blocked' => in_array($blocked, ['0', '1'], true) ? $blocked : '',
        ];
        $data = ['filters' => $filters, 'page' => $page, 'rows' => [], 'previous' => null, 'next' => null, 'error' => null];
        $status = Response::HTTP_OK;
        if (($phaseValue !== '' && $phase === null) || !in_array($blocked, ['', '0', '1'], true) || $page === false) {
            $status = Response::HTTP_BAD_REQUEST;
            $data['error'] = 'Les filtres ou le numéro de page sont invalides.';
        } else {
            try {
                $result = $list->read($page, $phase, $blocked === '' ? null : $blocked === '1');
                $data['rows'] = $result['rows'];
                $data['previous'] = $page > 1 ? $page - 1 : null;
                $data['next'] = $result['has_next'] ? $page + 1 : null;
            } catch (Exception) {
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
                $data['error'] = 'Le journal de la corbeille MP3 est indisponible.';
            }
        }

        return $this->render('admin/audio_library/trash/index.html.twig', $data,
            new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}

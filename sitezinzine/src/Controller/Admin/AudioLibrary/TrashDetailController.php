<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Service\AudioLibrary\Mp3TrashDetail;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class TrashDetailController extends AbstractController
{
    #[Route('/admin/audio-library/trash/{operationId}', name: 'admin.audio_library.trash.show',
        requirements: ['operationId' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(Request $request, string $operationId, Mp3TrashDetail $detail): Response
    {
        $detailView = null;
        $status = Response::HTTP_OK;
        try {
            $detailView = $detail->read($operationId);
            if ($detailView === null) {
                $status = Response::HTTP_NOT_FOUND;
            } elseif ($detailView['inspection']['journal'] === null) {
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        } catch (Exception|\LogicException) {
            $status = Response::HTTP_SERVICE_UNAVAILABLE;
        }

        return $this->render('admin/audio_library/trash/show.html.twig', [
            'operationId' => $operationId,
            'detail' => $detailView,
            'unavailable' => $status === Response::HTTP_SERVICE_UNAVAILABLE,
            'canPrepareRestore' => TrashRestoreAvailability::canPrepare($detailView['inspection'] ?? null),
            'filters' => TrashListNavigation::filters($request->query->all()),
        ], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}

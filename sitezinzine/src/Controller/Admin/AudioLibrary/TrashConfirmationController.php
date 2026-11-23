<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Enum\Mp3TrashPhase as Phase;
use App\Service\AudioLibrary\Mp3TrashConfirmationStore;
use App\Service\AudioLibrary\Mp3TrashDetail;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class TrashConfirmationController extends AbstractController
{
    #[Route('/admin/audio-library/trash/{operationId}/confirm/{action}', name: 'admin.audio_library.trash.confirm',
        requirements: ['operationId' => '[a-f0-9]{32}', 'action' => Action::ROUTE_REQUIREMENT], methods: ['GET'])]
    public function confirm(Request $request, string $operationId, string $action, Mp3TrashDetail $detail,
        Mp3TrashConfirmationStore $confirmations): Response
    {
        $data = [
            'operationId' => $operationId,
            'action' => $action,
            'filters' => TrashListNavigation::filters($request->query->all()),
            'inspection' => null,
            'error' => null,
            'submission' => null,
        ];
        $actor = $this->getUser();
        if ($this->isGranted('IS_IMPERSONATOR') || !$actor instanceof User || $actor->getId() === null || $actor->getId() <= 0) {
            $data['error'] = 'Confirmation interdite pour cette session.';
            return $this->page($data, Response::HTTP_FORBIDDEN);
        }
        try {
            $view = $detail->read($operationId);
        } catch (Exception|\LogicException) {
            $data['error'] = 'Inspection indéterminée. Le journal MP3 est indisponible.';
            return $this->page($data, Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($view === null) {
            $data['error'] = 'Opération de corbeille introuvable.';
            return $this->page($data, Response::HTTP_NOT_FOUND);
        }
        $inspection = $view['inspection'];
        $journal = $inspection['journal'];
        if ($journal === null) {
            $data['error'] = 'Inspection indéterminée. Le journal MP3 est indisponible.';
            return $this->page($data, Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $data['inspection'] = $inspection;
        $selected = Action::from($action);
        $phase = Phase::tryFrom($journal['phase']);
        $attemptId = $selected->requiresAttempt() ? $journal['last_attempt_id'] : null;
        $version = $journal['blocked'] ? $journal['block_version'] : null;
        if ($phase === null || !$selected->acceptsPhase($phase)
            || ($selected->requiresAttempt() && ($attemptId === null || ($journal['last_attempt']['outcome'] ?? null) !== 'pending'))
            || ($journal['blocked'] && $version === null)
            || ($selected === Action::RESTORE && !TrashRestoreAvailability::canPrepare($inspection))) {
            $data['error'] = 'La phase, la tentative ou le blocage observé ne permet pas de préparer cette confirmation.';
            return $this->page($data, Response::HTTP_CONFLICT);
        }

        $data['submission'] = $confirmations->issue($request->getSession(), $operationId, $selected, $actor->getId(), $phase, $attemptId, $version,
            lastAttemptId: $selected === Action::RESTORE ? $journal['last_attempt_id'] : null);
        $data['submissionRoute'] = match (true) {
            $selected === Action::RESTORE => 'admin.audio_library.trash.restore',
            $selected->isRecovery() => 'admin.audio_library.trash.resume',
            default => 'admin.audio_library.trash.cancel',
        };

        return $this->page($data, Response::HTTP_OK);
    }

    private function page(array $data, int $status): Response
    {
        return $this->render('admin/audio_library/trash/confirm.html.twig', $data,
            new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}

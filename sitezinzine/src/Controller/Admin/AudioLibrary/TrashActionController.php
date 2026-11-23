<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Enum\Mp3TrashConfirmationAction as Action;
use App\Exception\Mp3RestoreFailure;
use App\Exception\Mp3TrashCancellationFailure;
use App\Exception\Mp3TrashRecoveryFailure;
use App\Service\AudioLibrary\Mp3TrashCancellationAction;
use App\Service\AudioLibrary\Mp3TrashConfirmationStore;
use App\Service\AudioLibrary\Mp3TrashRecoveryAction;
use App\Service\AudioLibrary\Mp3RestoreAction;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class TrashActionController extends AbstractController
{
    #[Route('/admin/audio-library/trash/{operationId}/{action}', name: 'admin.audio_library.trash.resume',
        requirements: ['operationId' => '[a-f0-9]{32}', 'action' => Action::RECOVERY_ROUTE_REQUIREMENT], methods: ['POST'])]
    #[Route('/admin/audio-library/trash/{operationId}/{action}', name: 'admin.audio_library.trash.cancel',
        requirements: ['operationId' => '[a-f0-9]{32}', 'action' => Action::CANCELLATION_ROUTE_REQUIREMENT], methods: ['POST'])]
    #[Route('/admin/audio-library/trash/{operationId}/{action}', name: 'admin.audio_library.trash.restore',
        requirements: ['operationId' => '[a-f0-9]{32}', 'action' => 'restore'], methods: ['POST'])]
    public function submit(Request $request, string $operationId, string $action, Mp3TrashConfirmationStore $confirmations,
        Mp3TrashRecoveryAction $recovery, Mp3TrashCancellationAction $cancellation, Mp3RestoreAction $restore): Response
    {
        $parameters = TrashListNavigation::filters($request->query->all()) + ['operationId' => $operationId];
        $selected = Action::from($action);
        $label = match (true) {
            $selected === Action::RESTORE => 'restauration',
            $selected->isRecovery() => 'reprise',
            default => 'annulation',
        };
        $actor = $this->getUser();
        if ($this->isGranted('IS_IMPERSONATOR') || !$actor instanceof User || $actor->getId() === null || $actor->getId() <= 0) {
            return $this->failure($parameters, 403, ucfirst($label) . ' interdite pour cette session.');
        }
        $data = $request->request->all();
        $requestId = $data['request_id'] ?? null;
        $token = $data['_token'] ?? null;
        if (!is_string($requestId) || preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1) {
            return $this->failure($parameters, 422, 'La confirmation est absente, invalide ou expirée.');
        }
        try {
            $confirmation = $confirmations->validate($request->getSession(), $operationId, $selected, $actor->getId(),
                $requestId, is_string($token) ? $token : '');
        } catch (InvalidCsrfTokenException) {
            return $this->failure($parameters, 403, 'Le jeton de sécurité de la confirmation est invalide.');
        }
        if ($confirmation === null) {
            return $this->failure($parameters, 422, 'La confirmation est absente, invalide ou expirée.');
        }
        try {
            if ($selected === Action::RESTORE) {
                if (!$restore->execute($confirmation)) {
                    return $this->failure($parameters, 404, 'Opération de corbeille introuvable.', $requestId);
                }
            } else {
                ($selected->isRecovery() ? $recovery : $cancellation)->execute($selected, $confirmation);
            }
        } catch (Mp3TrashRecoveryFailure|Mp3TrashCancellationFailure|Mp3RestoreFailure $failure) {
            $reason = $failure instanceof Mp3RestoreFailure && $failure->commitUncertain ? 'commit_uncertain' : $failure->reason;
            [$status, $message] = match ($reason) {
                'conflict' => $failure->step === 'journal_lookup'
                    ? [404, 'Opération de corbeille introuvable.']
                    : [409, 'L’état de l’opération ne permet plus cette ' . $label . '. Consultez une nouvelle inspection.'],
                'duplicate_request' => [409, 'Cette demande est déjà enregistrée. Consultez son historique.'],
                'confirmation_obsolete' => [409, 'Cette confirmation est obsolète. Consultez une nouvelle inspection de l’opération.'],
                'commit_uncertain' => [409, 'Le résultat du commit est inconnu. Consultez le détail et l’historique avant toute nouvelle décision.'],
                'trace_unavailable' => [503, 'Le journal administratif est indisponible. Consultez l’état de l’opération avant de renouveler la demande.'],
                'lock_unavailable' => [503, 'Le verrou du stockage MP3 est indisponible.'],
                'refused' => $failure->step === 'storage_lock'
                    ? [503, 'Le verrou du stockage MP3 est indisponible.']
                    : [503, 'Cette ' . $label . ' MP3 ne peut pas démarrer.'],
                default => [503, 'Cette ' . $label . ' a été interrompue. Consultez le détail pour vérifier les étapes confirmées.'],
            };

            return $this->failure($parameters, $status, $message, $requestId, $failure->traceFailure !== null);
        } catch (Exception|\LogicException) {
            return $this->failure($parameters, 503, 'La connexion au journal MP3 est indisponible.', $requestId);
        }

        $this->addFlash('success', match ($selected) {
            Action::RESUME_DELETION => 'La reprise de la suppression MP3 est confirmée.',
            Action::RESUME_RESTORATION => 'La reprise de la restauration MP3 est confirmée.',
            Action::CANCEL_DELETION => 'L’annulation de la suppression MP3 est confirmée.',
            Action::CANCEL_RESTORATION => 'L’annulation de la restauration MP3 est confirmée.',
            Action::RESTORE => 'La restauration du MP3 est confirmée.',
        });
        $response = $this->redirectToRoute('admin.audio_library.trash.show', $parameters, Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function failure(array $parameters, int $status, string $message, ?string $requestId = null, bool $traceUnavailable = false): Response
    {
        return $this->render('admin/audio_library/trash/action_failure.html.twig', [
            'destination' => $parameters,
            'message' => $message,
            'requestId' => $requestId,
            'traceUnavailable' => $traceUnavailable,
        ], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}

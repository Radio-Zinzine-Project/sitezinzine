<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Entity\User;
use App\Exception\Mp3CleanupTransactionFailure;
use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueReader;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupAction;
use App\Service\AudioLibrary\Orphan\Mp3DeferredCleanupResult;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class CleanupIssueDetailController extends AbstractController
{
    #[Route('/admin/audio-library/cleanup-issues/{issueId}', name: 'admin.audio_library.cleanup_issues.show',
        requirements: ['issueId' => '[1-9][0-9]{0,9}'], methods: ['GET'])]
    public function show(Request $request, int $issueId, Mp3CleanupIssueReader $reader, Mp3DeferredCleanupAction $cleanup): Response
    {
        $query = $request->query->all();
        $before = self::positiveId($query['before'] ?? null);
        $last = self::positiveId($query['last'] ?? null);
        $hasCursor = array_key_exists('before', $query) || array_key_exists('last', $query);
        $detail = null;
        $error = null;
        $status = Response::HTTP_OK;
        if ($issueId > 2147483647) {
            $status = Response::HTTP_NOT_FOUND;
            $error = 'Incident introuvable.';
        } elseif ($hasCursor && ($before === false || $last === false || $before > $last)) {
            $status = Response::HTTP_BAD_REQUEST;
            $error = 'Le curseur de pagination est invalide.';
        } else {
            try {
                $detail = $reader->readInitialDetail($issueId);
                if ($detail === null) {
                    $status = Response::HTTP_NOT_FOUND;
                    $error = 'Incident introuvable.';
                } else {
                    if ($hasCursor) {
                        $history = $reader->readNextEventsPage($issueId, $last, $before);
                        $detail['events'] = $history['events'];
                        $detail['pagination'] = $history['pagination'];
                    } else {
                        $last = $detail['token']['last_event_id'] ?? null;
                    }
                    $detail['issue']['obsolete_relative_path'] = CleanupIssuePathPresentation::display($detail['issue']['obsolete_relative_path']);
                    foreach ($detail['events'] as &$event) {
                        $event['context']['replacement_relative_path'] = CleanupIssuePathPresentation::display($event['context']['replacement_relative_path']);
                    }
                    unset($event);
                }
            } catch (Exception|Mp3CleanupTransactionFailure|\JsonException) {
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
                $error = 'Le journal des incidents de nettoyage MP3 est indisponible.';
            }
        }

        $cleanupView = null;
        $form = null;
        $attemptId = $query['attempt'] ?? null;
        if ($status === Response::HTTP_OK) {
            if (array_key_exists('attempt', $query) && !self::isRequestId($attemptId)) {
                $status = Response::HTTP_NOT_FOUND;
            } else {
                try {
                    $cleanupView = $cleanup->read($issueId, $attemptId);
                    if ($cleanupView['unknown'] ?? false) {
                        $cleanupView = null;
                        $status = Response::HTTP_NOT_FOUND;
                    }
                } catch (Exception|\LogicException) {
                    $status = Response::HTTP_SERVICE_UNAVAILABLE;
                }
            }
            $actorId = $this->actorId();
            $eventId = $detail['token']['last_event_id'] ?? null;
            if ($cleanupView !== null && $cleanupView['configured'] && $cleanupView['active'] === null
                && !$hasCursor && $attemptId === null && $actorId !== null && $eventId !== null
                && $detail['issue']['status'] !== 'resolved') {
                $requestId = bin2hex(random_bytes(16));
                $form = ['request_id' => $requestId, 'event_id' => $eventId,
                    'csrf_id' => self::CSRF_ID];
                $request->getSession()->set(self::SESSION_KEY, [$issueId, $eventId, $requestId, $actorId, time() + 900]);
            }
        }

        return $this->render('admin/audio_library/cleanup_issues/show.html.twig', [
            'detail' => $detail, 'error' => $error, 'last' => $last, 'hasCursor' => $hasCursor,
            'detailCursor' => $before, 'cleanup' => $cleanupView, 'cleanupForm' => $form, 'attemptNotFound' => $detail !== null && $status === 404,
            'cleanupResults' => self::RESULT_LABELS,
        ], new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/admin/audio-library/cleanup-issues/{issueId}/cleanup', name: 'admin.audio_library.cleanup_issues.cleanup',
        requirements: ['issueId' => '[1-9][0-9]{0,9}'], methods: ['POST'])]
    public function cleanup(Request $request, int $issueId, Mp3DeferredCleanupAction $cleanup): Response
    {
        $actorId = $this->actorId();
        if ($actorId === null) {
            throw $this->createAccessDeniedException();
        }
        if ($issueId > 2147483647) {
            throw $this->createNotFoundException();
        }

        $eventId = $this->confirmedEvent($request, $issueId, $actorId, self::CSRF_ID);
        if ($eventId instanceof Response) {
            return $eventId;
        }
        $requestId = $request->request->all()['request_id'];

        try {
            $result = $cleanup->remove($issueId, $eventId, $requestId, $actorId);
        } catch (Exception|\LogicException) {
            $result = Mp3DeferredCleanupResult::OutcomeUncertain;
        }

        return $this->backToIssue($issueId, $result === Mp3DeferredCleanupResult::Removed ? 'success' : 'warning',
            self::RESULT_LABELS[$result->value]);
    }

    #[Route('/admin/audio-library/cleanup-issues/{issueId}/close', name: 'admin.audio_library.cleanup_issues.close',
        requirements: ['issueId' => '[1-9][0-9]{0,9}'], methods: ['POST'])]
    public function close(Request $request, int $issueId, Mp3DeferredCleanupAction $cleanup): Response
    {
        $actorId = $this->actorId();
        if ($actorId === null) {
            throw $this->createAccessDeniedException();
        }
        if ($issueId > 2147483647) {
            throw $this->createNotFoundException();
        }

        $eventId = $this->confirmedEvent($request, $issueId, $actorId, 'mp3-cleanup-issue-close');
        if ($eventId instanceof Response) {
            return $eventId;
        }
        $reason = $request->request->all()['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            return new Response('Motif de clôture obligatoire.', 422, ['Cache-Control' => 'private, no-store']);
        }

        try {
            $result = $cleanup->closeIncident($issueId, $eventId, $actorId, $reason);
        } catch (Exception|\LogicException) {
            $result = 'indeterminate';
        }

        $message = match ($result) {
            'allowed' => 'La clôture de l’incident est confirmée.',
            'active_attempt' => 'Une tentative en attente ou indéterminée bloque la clôture.',
            'invalid_path', 'unsafe_path' => 'Clôture refusée : le chemin est invalide ou inaccessible.',
            'unusable_proof', 'missing_proof', 'conflicting_proofs' => 'Clôture refusée : les preuves disponibles ne permettent pas d’établir le sort du fichier.',
            'file_present', 'different' => 'Clôture refusée : un fichier occupe encore le chemin.',
            'invalid_reason' => 'Le motif doit contenir entre 1 et 500 caractères, sur une seule ligne.',
            'reference_active' => 'Clôture refusée : le fichier est encore référencé.',
            'reservation_active' => 'Clôture refusée : le chemin est réservé.',
            'indeterminate' => 'La clôture n’a pas pu être confirmée. Consultez l’état actuel de l’incident.',
            default => self::RESULT_LABELS[$result] ?? 'Les conditions de clôture ne sont pas réunies.',
        };

        return $this->backToIssue($issueId, $result === 'allowed' ? 'success' : 'warning', $message);
    }

    private function confirmedEvent(Request $request, int $issueId, int $actorId, string $csrfId): int|Response
    {
        $data = $request->request->all();
        $requestId = $data['request_id'] ?? null;
        $eventId = self::positiveId($data['event_id'] ?? null);
        if (!self::isRequestId($requestId) || $eventId === false || ($data['confirmed'] ?? null) !== '1') {
            return new Response('Confirmation requise.', 422, ['Cache-Control' => 'private, no-store']);
        }
        $confirmation = $request->getSession()->get(self::SESSION_KEY, []);
        if (array_slice($confirmation, 0, 4) !== [$issueId, $eventId, $requestId, $actorId]
            || $confirmation[4] <= time()) {
            return new Response('Confirmation invalide ou expirée.', 422, ['Cache-Control' => 'private, no-store']);
        }
        $token = $data['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid($csrfId, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        return $eventId;
    }

    private function backToIssue(int $issueId, string $level, string $message): Response
    {
        $this->addFlash($level, $message);
        $response = $this->redirectToRoute('admin.audio_library.cleanup_issues.show',
            ['issueId' => $issueId], Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function actorId(): ?int
    {
        $user = $this->getUser();
        return $user instanceof User && $user->getId() > 0 && !$this->isGranted('IS_IMPERSONATOR') ? $user->getId() : null;
    }

    private static function isRequestId(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{32}\z/', $value) === 1;
    }

    private const CSRF_ID = 'mp3-deferred-cleanup';
    private const SESSION_KEY = 'mp3_deferred_cleanup_confirmation';

    private const RESULT_LABELS = [
        'removed' => 'Retrait du fichier confirmé.',
        'already_absent' => 'Fichier déjà absent lors de la vérification.',
        'reference_active' => 'Retrait refusé : le fichier est encore référencé.',
        'reservation_active' => 'Retrait refusé : le chemin est réservé par la corbeille.',
        'already_resolved' => 'Cet incident est déjà résolu.',
        'stale_view' => 'L’incident a changé. Consultez sa fiche actualisée avant de confirmer.',
        'not_found' => 'Incident introuvable.',
        'lock_unavailable' => 'Le verrou du stockage MP3 est indisponible.',
        'invalid_path' => 'Retrait refusé : le chemin est invalide ou inaccessible.',
        'different' => 'Retrait refusé : l’identité du fichier a changé.',
        'missing_proof' => 'Retrait refusé : preuve historique manquante.',
        'unusable_proof' => 'Retrait refusé : preuve historique inexploitable.',
        'conflicting_proofs' => 'Retrait refusé : les preuves historiques sont incompatibles.',
        'removal_refused' => 'Le stockage a refusé le retrait du fichier.',
        'indeterminate' => 'Résultat indéterminé. Consultez le diagnostic de la tentative.',
        'request_not_recorded' => 'La demande de nettoyage n’a pas pu être enregistrée.',
        'request_uncertain' => 'L’enregistrement de la demande est incertain. Consultez l’historique avant toute autre demande.',
        'duplicate_or_active_request' => 'Cette demande existe déjà ou une tentative bloque le nettoyage.',
        'outcome_uncertain' => 'Le résultat du nettoyage est incertain. Consultez l’historique avant toute autre demande.',
        'identity_present' => 'Le fichier attendu est présent lors de la consultation.',
        'absent_now' => 'Le fichier est absent au moment de la consultation. L’issue de la tentative reste inconnue.',
    ];

    private static function positiveId(mixed $value): int|false
    {
        return is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    }
}

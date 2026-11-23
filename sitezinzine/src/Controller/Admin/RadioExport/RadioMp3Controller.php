<?php

declare(strict_types=1);

namespace App\Controller\Admin\RadioExport;

use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationCatalog;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationLaunch;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationOrchestrator;
use App\Service\AudioLibrary\RadioExport\Preparation\RadioPreparationPublisher;
use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferJournal;
use App\Service\AudioLibrary\RadioExport\Transfer\RadioTransferWorklist;
use App\Service\AudioLibrary\RadioExport\Transfer\RadioCurrentTransferConfirmation;
use App\Entity\User;
use App\Exception\Mp3StorageLockTimeout;
use App\Service\AudioLibrary\RadioExport\RadioExportDestination;
use App\Service\AudioLibrary\RadioExport\RadioExportWeekLifetime;
use App\Service\GridWeekValidation;
use App\Service\Mp3StoragePath;
use App\Service\RadioWeek;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class RadioMp3Controller extends AbstractController
{
    #[Route('/admin/radio-mp3', name: 'admin.radio_mp3.index', methods: ['GET'])]
    public function index(
        Request $request,
        GridWeekValidation $validation,
        RadioPreparationCatalog $catalog,
        RadioPreparationOrchestrator $preparations,
        RadioTransferWorklist $worklist,
        RadioTransferJournal $journal,
        RadioExportWeekLifetime $lifetime,
        #[Autowire('%env(RADIO_EXPORT_ROOT)%')] string $root,
        #[Autowire('%env(RADIO_EXPORT_DESTINATION)%')] string $destination,
        #[Autowire('%app.mp3_base_dir%')] string $library,
        #[Autowire('%kernel.project_dir%/public')] string $public,
    ): Response {
        $zone = new \DateTimeZone('Europe/Paris');
        $query = $request->query->all();
        $value = $query['week'] ?? null;
        $historyFile = $query['history'] ?? null;
        $beforeValue = $query['before'] ?? null;
        $before = is_string($beforeValue) ? filter_var($beforeValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $week = $value === null ? RadioWeek::start(new \DateTimeImmutable('now', $zone)) : self::parseWeek($value, $zone);
        $destinationValid = RadioExportDestination::accepts($destination);
        $expired = $week !== null && $lifetime->isExpired($week);
        $data = ['week' => $week, 'expired' => $expired, 'validated' => null, 'configured' => $root !== '', 'rows' => [],
            'freshness' => null, 'revisionCurrent' => null, 'referenceId' => null, 'orderUncertain' => false,
            'summary' => null, 'work' => null, 'transferHistory' => null, 'error' => null, 'launchRequestId' => null, 'launchBlock' => null];
        $response = new Response(headers: ['Cache-Control' => 'private, no-store']);
        if ($week === null || ($historyFile !== null && (!is_string($historyFile) || strlen($historyFile) > 255
            || preg_match("/\A[A-Za-z0-9][A-Za-z0-9'-]*\.mp3\z/", $historyFile) !== 1))
            || ($beforeValue !== null && ($historyFile === null || $before === false))) {
            $response->setStatusCode(400);
            $data['error'] = 'La semaine ou le filtre fourni est invalide.';
        } else {
            try {
                $state = $validation->captureForExport($week);
                $data['validated'] = $state['validated'];
                if ($historyFile !== null && $destinationValid) {
                    $events = $journal->history($destination, $historyFile, 51, $before === false ? null : $before);
                    $page = array_slice($events, 0, 50);
                    $data['transferHistory'] = ['filename' => $historyFile, 'events' => $page,
                        'older' => count($events) > 50 ? $page[49]['id'] : null];
                }
                if ($root !== '') {
                    $root = self::privateExportRoot($root, $public);
                    $listing = $catalog->read($root, $week);
                    $data['rows'] = $listing['rows'];
                    $data['orderUncertain'] = $listing['orderUncertain'];
                    $data['launchBlock'] = RadioPreparationLaunch::blockedByCatalog($listing);
                    if (!$expired && $state['validated'] && $data['launchBlock'] === null && $destinationValid) {
                        $data['launchRequestId'] = bin2hex(random_bytes(16));
                    }
                    $reference = $listing['reference'];
                    if ($reference !== null) {
                        $data['referenceId'] = $reference['attemptId'];
                        $data['revisionCurrent'] = $state['validated'] && $state['revision'] === ($reference['revision'] ?? null);
                        $data['freshness'] = $preparations->checkFreshness($library, $week, $reference, false);
                        if ($destinationValid && $data['revisionCurrent']) {
                            $work = $worklist->read($root, $week, $reference['attemptId'], $destination);
                            if ($work['status'] === 'current') {
                                $actor = $this->getUser();
                                $data['summary'] = array_count_values(array_column($work['lines'], 'transferState'));
                                foreach ($work['lines'] as &$line) {
                                    if (!$expired && $actor instanceof User && ($actor->getId() ?? 0) > 0
                                        && in_array($line['transferState'], ['to_transfer', 'new_version'], true)) {
                                        $line['requestId'] = bin2hex(random_bytes(16));
                                        $line['version'] = RadioCurrentTransferConfirmation::version($line['filename'], $line['sha256']);
                                    }
                                }
                                unset($line);
                                $data['work'] = $work;
                            }
                        }
                    }
                }
            } catch (\RuntimeException|DatabaseException) {
                $response->setStatusCode(503);
                $data['error'] = 'La consultation des préparations est momentanément indisponible.';
            }
        }

        return $this->render('admin/radio_export/index.html.twig', $data, $response);
    }

    #[Route('/admin/radio-mp3/{week}/prepare', name: 'admin.radio_mp3.prepare', methods: ['POST'])]
    public function prepare(
        string $week,
        Request $request,
        RadioPreparationLaunch $launch,
        #[Autowire('%env(RADIO_EXPORT_ROOT)%')] string $root,
        #[Autowire('%env(RADIO_EXPORT_DESTINATION)%')] string $destination,
        #[Autowire('%app.mp3_base_dir%')] string $library,
        #[Autowire('%app.mp3_work_dir%')] string $work,
        #[Autowire('%app.mp3_trash_dir%')] ?string $trash,
        #[Autowire('%kernel.project_dir%/public')] string $public,
    ): Response {
        $date = self::parseWeek($week, new \DateTimeZone('Europe/Paris'));
        $form = $request->request->all();
        $id = $form['request_id'] ?? null;
        $token = $form['_token'] ?? null;
        if ($date === null || !is_string($id) || preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            return new Response('Demande de préparation invalide.', Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'private, no-store']);
        }
        if (!is_string($token) || !$this->isCsrfTokenValid('radio-prepare-' . $week . '-' . $id, $token)) {
            return new Response('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'private, no-store']);
        }

        // Let another request from the same session reach the week lock while work is in progress.
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }
        try {
            $outcome = $trash === null ? 'not_configured' : $launch->run($root,
                ['library' => $library, 'work' => $work, 'trash' => $trash, 'public' => $public], $date, $id, $destination);
        } catch (\Exception) {
            $outcome = 'failed';
        }
        [$type, $message] = match ($outcome) {
            'complete_zip' => ['success', 'L’archive complète a été préparée.'],
            'corrective_zip' => ['success', 'L’archive corrective a été préparée.'],
            'unchanged' => ['success', 'Aucun changement depuis la dernière préparation.'],
            'report_only' => ['success', 'Le rapport a été publié sans ZIP. Consultez les retraits et anomalies signalés.'],
            'already_requested' => ['info', 'Cette demande a déjà été traitée. Consultez son état dans l’historique.'],
            'expired' => ['warning', 'Cette semaine radio est terminee. Les nouvelles preparations sont fermees.'],
            'busy' => ['warning', 'Une préparation est déjà en cours pour cette semaine.'],
            'unvalidated' => ['warning', 'Cette semaine doit être validée avant de préparer les MP3.'],
            'ambiguous' => ['warning', 'Plusieurs préparations utilisables subsistent et aucune référence ne peut être choisie automatiquement.'],
            'reference_unavailable' => ['warning', 'La préparation de référence est inutilisable. Consultez l’historique.'],
            'not_configured' => ['warning', 'Le stockage radio ou la destination reste à configurer.'],
            default => ['danger', 'La préparation a échoué. Consultez son état dans l’historique.'],
        };
        $this->addFlash($type, $message);
        $response = $this->redirectToRoute('admin.radio_mp3.index', ['week' => $week], Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/admin/radio-mp3/{week}/{attemptId}/transfer/{diffusionId}', name: 'admin.radio_mp3.confirm_transfer',
        requirements: ['week' => '\d{4}-\d{2}-\d{2}', 'attemptId' => '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}', 'diffusionId' => '[1-9]\d{0,9}'],
        methods: ['POST'])]
    public function confirmTransfer(
        string $week,
        string $attemptId,
        int $diffusionId,
        Request $request,
        RadioCurrentTransferConfirmation $confirmation,
        #[Autowire('%env(RADIO_EXPORT_ROOT)%')] string $root,
        #[Autowire('%env(RADIO_EXPORT_DESTINATION)%')] string $destination,
        #[Autowire('%app.mp3_base_dir%')] string $library,
        #[Autowire('%app.mp3_work_dir%')] string $work,
        #[Autowire('%app.mp3_trash_dir%')] ?string $trash,
        #[Autowire('%kernel.project_dir%/public')] string $public,
    ): Response {
        $date = self::parseWeek($week, new \DateTimeZone('Europe/Paris'));
        $form = $request->request->all();
        $id = $form['request_id'] ?? null;
        $version = $form['version'] ?? null;
        $token = $form['_token'] ?? null;
        $headers = ['Cache-Control' => 'private, no-store'];
        if ($date === null || $diffusionId > 2147483647
            || !is_string($id) || preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1
            || !is_string($version) || preg_match('/\A[a-f0-9]{64}\z/', $version) !== 1) {
            return new Response('Demande de confirmation invalide.', Response::HTTP_BAD_REQUEST, $headers);
        }
        $actor = $this->getUser();
        if (!$actor instanceof User || ($actor->getId() ?? 0) < 1 || !is_string($token)
            || !$this->isCsrfTokenValid('radio-transfer-' . $week . '-' . $attemptId, $token)) {
            return new Response('Confirmation non autoris?e.', Response::HTTP_FORBIDDEN, $headers);
        }
        if ($root === '' || $trash === null || !RadioExportDestination::accepts($destination)) {
            return new Response('Le stockage radio ou la destination reste ? configurer.', Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->save();
        }
        try {
            $confirmation->confirm($root, ['library' => $library, 'work' => $work, 'trash' => $trash, 'public' => $public],
                $date, $attemptId, $diffusionId, $destination, $version, $actor->getId(), $id);
        } catch (\DomainException|Mp3StorageLockTimeout) {
            return new Response('Le fichier affich? ne peut plus \u00eatre confirm?. Actualisez la semaine avant de poursuivre.',
                Response::HTTP_CONFLICT, $headers);
        } catch (\RuntimeException|DatabaseException) {
            return new Response('La confirmation est momentan?ment indisponible.', Response::HTTP_SERVICE_UNAVAILABLE, $headers);
        }

        $this->addFlash('success', 'Votre d?claration de transfert a ?t? enregistr?e.');
        $response = $this->redirectToRoute('admin.radio_mp3.index', ['week' => $week], Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/admin/radio-mp3/{week}/{attemptId}/download/{artifact}', name: 'admin.radio_mp3.download',
        requirements: ['week' => '\d{4}-\d{2}-\d{2}', 'attemptId' => '[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}', 'artifact' => 'zip|report'],
        methods: ['GET'])]
    public function download(
        string $week,
        string $attemptId,
        string $artifact,
        RadioPreparationPublisher $publisher,
        #[Autowire('%env(RADIO_EXPORT_ROOT)%')] string $root,
        #[Autowire('%kernel.project_dir%/public')] string $public,
    ): Response {
        $date = self::parseWeek($week, new \DateTimeZone('Europe/Paris'));
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        $download = null;
        if ($date !== null && $root !== '') {
            try {
                $download = $publisher->openDownload(self::privateExportRoot($root, $public), $date, $attemptId, $artifact);
            } catch (\RuntimeException|\InvalidArgumentException) {
                // Report only a generic availability failure.
            }
        }
        if ($download === null) {
            return new Response('Artefact radio indisponible.', Response::HTTP_NOT_FOUND, $headers);
        }

        $stream = $download['stream'];
        $response = new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, headers: $headers + [
            'Content-Type' => $artifact === 'zip' ? 'application/zip' : 'application/json',
            'Content-Length' => (string) $download['size'],
        ]);
        $extension = $artifact === 'zip' ? 'zip' : 'json';
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('radio-mp3-%s-%s.%s', $week, $attemptId, $extension),
        ));

        return $response;
    }

    private static function privateExportRoot(string $root, string $public): string
    {
        $root = Mp3StoragePath::directory($root);
        $publicRoot = Mp3StoragePath::directory($public) . DIRECTORY_SEPARATOR;
        if (str_starts_with($root . DIRECTORY_SEPARATOR, $publicRoot)
            || str_starts_with($publicRoot, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('The private export root overlaps the public directory.');
        }

        return $root;
    }

    private static function parseWeek(mixed $value, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);

        return $date !== false && $date->format('Y-m-d') === $value && $date->format('N') === '2' ? $date : null;
    }
}

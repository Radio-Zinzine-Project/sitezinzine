<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Service\AudioLibrary\Inventory\AudioInventoryReportDiscovery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/audio-library/inventory', name: 'admin.audio_library.inventory.')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class InventoryController extends AbstractController
{
    private const LABELS = ['associated' => 'associé', 'multiple_associations' => 'associations multiples',
        'orphan_candidate' => 'orphelin potentiel', 'broken_reference' => 'référence cassée', 'indeterminate' => 'indéterminé'];

    public function __construct(private readonly AudioInventoryReportDiscovery $reader) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $profiles = $this->reader->getProfiles();
        foreach ($profiles as $name) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D', $name) !== 1) { return $this->error('Configuration des profils invalide.', 503); }
        }
        $selected = $request->query->all()['profile'] ?? ($profiles[0] ?? null);
        if ($selected !== null && (!is_string($selected) || !in_array($selected, $profiles, true))) {
            return $this->error('Profil inconnu.', 404);
        }
        $discovery = null;
        if ($selected !== null) {
            try {
                $result = $this->reader->discover($selected);
                $discovery = ['partial' => $result['partial'], 'status' => $result['status'],
                    'unpublished_count' => $result['unpublished_count'], 'entries_examined' => $result['entries_examined'],
                    'reports' => [], 'anomalies' => []];
                foreach ($result['reports'] as $report) { $discovery['reports'][] = $this->summary($report); }
                foreach ($result['anomalies'] as $anomaly) {
                    $discovery['anomalies'][] = ['inventory_id' => $this->text($anomaly['inventory_id']),
                        'status' => $this->code($anomaly['status']), 'reason' => $this->code($anomaly['reason'])];
                }
            } catch (\RuntimeException|\InvalidArgumentException|\LengthException) {
                return $this->error('Stockage des rapports indisponible ou configuration invalide.', 503);
            }
        }
        return $this->view('index', ['profiles' => $profiles, 'profile' => $selected, 'discovery' => $discovery]);
    }

    #[Route('/{profile}/{inventoryId}', name: 'show', methods: ['GET'], requirements: ['inventoryId' => '[a-f0-9]{32}'])]
    public function show(Request $request, string $profile, string $inventoryId): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        if (!in_array($profile, $this->reader->getProfiles(), true) || preg_match('/^[a-f0-9]{32}$/D', $inventoryId) !== 1) {
            return $this->error('Profil ou identifiant inconnu.', 404);
        }
        $rawPage = $request->query->all()['page'] ?? '1';
        if (!is_string($rawPage) || preg_match('/^[1-9][0-9]*$/D', $rawPage) !== 1 || (string)(int)$rawPage !== $rawPage) {
            return $this->error('Numéro de page invalide.', 400);
        }
        $page = (int)$rawPage;
        try {
            $inspection = $this->reader->readManifest($profile, $inventoryId);
            if ($inspection['status'] !== 'published') {
                return match ($inspection['status']) {
                    'unpublished' => $this->error('Rapport non publié.', 404),
                    'corrupt_or_unreadable' => $this->error('Rapport corrompu ou illisible.', 409),
                    default => $this->error('Rapport indisponible ou inexistant.', 503),
                };
            }
            $manifest = $inspection['manifest']; $count = $manifest['page_count'];
            if (($count === 0 && $page !== 1) || ($count > 0 && $page > $count)) { return $this->error('Page inexistante.', 404); }
            $results = []; $next = null;
            if ($count > 0) {
                $read = $this->reader->readPage($profile, $inventoryId, $page);
                foreach ($read['results'] as $row) {
                    $associations = [];
                    foreach ($row['associations'] as $a) {
                        $associations[] = ['emission_id' => is_int($a['emission_id'] ?? null) && $a['emission_id'] > 0 ? $a['emission_id'] : null,
                            'origin' => in_array($a['origin'] ?? null, ['thumbnailMp3', 'local_audio_url'], true) ? $a['origin'] : 'inconnue',
                            'thumbnail_missing' => ($a['thumbnail_missing'] ?? false) === true];
                    }
                    $results[] = ['relative_path' => $this->text($row['relative_path']),
                        'classification' => self::LABELS[$row['classification']] ?? 'indéterminé',
                        'presence' => $row['present'] === null ? 'présence non déterminée' : ($row['present'] ? 'observé' : 'non observé — absence non certifiée'),
                        'associations' => $associations];
                }
                $next = $read['next_page'];
            }
            $m = $manifest + ['inventory_date' => $inspection['inventory_date'] ?? null];
            $m['scope'] = $manifest['report']['scope'];
            $m['collection_mode'] = $manifest['report']['collection_mode'] ?? null;
            $m['restrictions'] = $manifest['report']['restrictions'];
            $diagnostics = ['physical' => [], 'sql' => [], 'secondary_count' => 0];
            $preparation = $manifest['report']['preparation'] ?? [];
            foreach ($preparation['physical_errors'] ?? [] as $error) {
                $diagnostics['physical'][] = ['path' => $this->text($error['relative_path'] ?? ''), 'reason' => $this->code($error['reason'] ?? '')];
            }
            foreach ($preparation['sql_export']['uncertainties'] ?? [] as $error) {
                $diagnostics['sql'][] = ['emission_id' => is_int($error['emission_id'] ?? null) && $error['emission_id'] > 0 ? $error['emission_id'] : null,
                    'reason' => $this->code($error['reason'] ?? '')];
            }
            $diagnostics['secondary_count'] = count($preparation['secondary_errors'] ?? []);
            return $this->view('show', ['profile' => $profile, 'summary' => $this->summary($m), 'diagnostics' => $diagnostics,
                'results' => $results, 'page' => $page, 'page_count' => $count, 'previous' => $page > 1 ? $page - 1 : null, 'next' => $next]);
        } catch (\OutOfBoundsException) { return $this->error('Page inexistante.', 404); }
        catch (\UnexpectedValueException|\LengthException) { return $this->error('Rapport corrompu ou illisible.', 409); }
        catch (\RuntimeException|\InvalidArgumentException) { return $this->error('Rapport indisponible ou configuration invalide.', 503); }
    }

    private function summary(array $m): array
    {
        $classes = [];
        foreach (self::LABELS as $key => $label) { $classes[] = ['label' => $label, 'count' => $m['classification_counts'][$key] ?? 0]; }
        return ['inventory_id' => $m['inventory_id'], 'date' => 'Date non enregistrée',
            'scope' => in_array($m['scope'], ['web_full', 'test_subset'], true) ? $m['scope'] : 'Périmètre non reconnu',
            'mode' => in_array($m['collection_mode'] ?? null, ['strict', 'observation_only'], true) ? $m['collection_mode'] : 'Mode non enregistré',
            'result_count' => $m['result_count'], 'physical_paths' => $m['physical_paths'] ?? 0,
            'referenced_paths' => $m['referenced_paths'] ?? 0, 'association_count' => $m['association_count'] ?? 0,
            'classes' => $classes, 'references_complete' => $m['restrictions']['references_complete'],
            'physical_complete' => $m['restrictions']['physical_collection_complete']];
    }

    /** Reversible hex for binary/control bytes; absolute server paths are redacted. */
    private function text(string $value): string
    {
        if (preg_match('~^(?:[/\\\\]|[A-Za-z]:|[A-Za-z][A-Za-z0-9+.-]*://)~D', $value) === 1) { return '[valeur masquée]'; }
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) { return 'hex:'.bin2hex($value); }
        return $value;
    }
    private function code(string $value): string { return preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $value) === 1 ? $value : 'code_non_reconnu'; }
    private function error(string $message, int $status): Response { return $this->view('index', ['error' => $message, 'profiles' => [], 'profile' => null, 'discovery' => null], $status); }
    private function view(string $name, array $data, int $status = 200): Response
    {
        return $this->render('admin/audio_library/inventory/'.$name.'.html.twig', $data,
            new Response('', $status, ['Cache-Control' => 'private, no-store']));
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use App\Service\AudioLibrary\Orphan\Mp3CleanupIssueList;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class CleanupIssueListController extends AbstractController
{
    #[Route('/admin/audio-library/cleanup-issues', name: 'admin.audio_library.cleanup_issues.index', methods: ['GET'])]
    public function index(Request $request, Mp3CleanupIssueList $list): Response
    {
        $rawPage = $request->query->all()['page'] ?? '1';
        $page = is_string($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, Mp3CleanupIssueList::PAGE_SIZE)],
        ]) : false;
        $data = ['rows' => [], 'previous' => null, 'next' => null, 'error' => null];
        $status = Response::HTTP_OK;

        if ($page === false) {
            $status = Response::HTTP_BAD_REQUEST;
            $data['error'] = 'Le numéro de page est invalide.';
        } else {
            try {
                $result = $list->read($page);
                foreach ($result['rows'] as $row) {
                    $row['obsolete_relative_path'] = CleanupIssuePathPresentation::display($row['obsolete_relative_path']);
                    $data['rows'][] = $row;
                }
                $data['previous'] = $page > 1 ? $page - 1 : null;
                $data['next'] = $result['has_next'] ? $page + 1 : null;
            } catch (Exception) {
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
                $data['error'] = 'Le journal des incidents de nettoyage MP3 est indisponible.';
            }
        }

        return $this->render('admin/audio_library/cleanup_issues/index.html.twig', $data,
            new Response(status: $status, headers: ['Cache-Control' => 'private, no-store']));
    }
}

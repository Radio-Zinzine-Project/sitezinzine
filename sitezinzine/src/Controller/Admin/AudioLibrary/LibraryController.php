<?php

declare(strict_types=1);

namespace App\Controller\Admin\AudioLibrary;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUPER_ADMIN')]
final class LibraryController extends AbstractController
{
    #[Route('/admin/audio-library', name: 'admin.audio_library.index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/audio_library/index.html.twig', [],
            new Response(headers: ['Cache-Control' => 'private, no-store']));
    }
}

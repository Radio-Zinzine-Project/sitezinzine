<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class TinyMCEPublicController extends AbstractController
{
    #[Route('/tinymce/upload', name: 'public.tinymce.upload', methods: ['POST'])]
    public function upload(Request $request, SluggerInterface $slugger): JsonResponse
    {
        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile) {
            return new JsonResponse(['error' => 'Aucun fichier reçu'], 400);
        }

        // (optionnel mais conseillé) limite taille
        // if ($uploadedFile->getSize() > 5_000_000) { ... }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = $uploadedFile->getMimeType();

        if (!$mime || !in_array($mime, $allowed, true)) {
            return new JsonResponse(['error' => 'Format d’image non supporté'], 400);
        }

        $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename)->lower();
        $extension = $uploadedFile->guessExtension() ?: 'bin';

        $newFilename = $safeFilename.'-'.bin2hex(random_bytes(6)).'.'.$extension;

        $targetDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/tinymce';

        if (!is_dir($targetDirectory)) {
            @mkdir($targetDirectory, 0775, true);
        }

        try {
            $uploadedFile->move($targetDirectory, $newFilename);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Erreur pendant l’upload du fichier'], 500);
        }

        return new JsonResponse(['location' => '/uploads/tinymce/'.$newFilename]);
    }
}

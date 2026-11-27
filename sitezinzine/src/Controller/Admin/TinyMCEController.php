<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

class TinyMCEController extends AbstractController
{
    #[Route('/admin/tinymce/upload', name: 'admin.tinymce.upload', methods: ['POST'])]
    public function upload(Request $request, SluggerInterface $slugger): JsonResponse
    {
        // Optionnel mais conseillé si cette route doit rester admin-only
        // $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $uploadedFile = $request->files->get('file');

        if (!$uploadedFile) {
            return new JsonResponse(['error' => 'Aucun fichier reçu'], 400);
        }

        if ($uploadedFile->getSize() > 5 * 1024 * 1024) {
            return new JsonResponse(['error' => 'Fichier trop volumineux (max 5 Mo)'], 400);
        }

        if (!in_array($uploadedFile->getMimeType(), ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return new JsonResponse(['error' => 'Format d’image non supporté'], 400);
        }

        $originalFilename = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename);

        $ext = $uploadedFile->guessExtension() ?: 'bin';
        $newFilename = $safeFilename.'-'.uniqid().'.'.$ext;

        $targetDirectory = $this->getParameter('kernel.project_dir').'/public/uploads/tinymce';

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0775, true);
        }

        try {
            $uploadedFile->move($targetDirectory, $newFilename);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Erreur pendant l’upload du fichier'], 500);
        }

        return new JsonResponse(['location' => '/uploads/tinymce/'.$newFilename]);
    }
}

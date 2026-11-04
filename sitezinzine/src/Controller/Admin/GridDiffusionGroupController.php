<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\DiffusionRepository;
use App\Service\GridGroupConsultation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class GridDiffusionGroupController extends AbstractController
{
    #[Route('/admin/diffusions/{diffusionId}/group', name: 'admin.diffusion_group', methods: ['GET'])]
    public function group(int $diffusionId, DiffusionRepository $repository, GridGroupConsultation $consultation): JsonResponse
    {
        $diffusion = $repository->find($diffusionId);
        if ($diffusion === null) {
            return $this->json(['success' => false, 'error' => 'Diffusion introuvable.', 'items' => []], 404);
        }

        return $this->json($consultation->consult($diffusion));
    }
}

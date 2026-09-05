<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\EmissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/admin", name: 'admin.')]
#[IsGranted('ROLE_USER')]
class AdminController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(
        UserRepository $userRepository,
        EmissionRepository $emissionRepository
    ): Response {
        $user = $this->getUser();
        $users = $userRepository->findAll();

        usort($users, static function ($a, $b) {
            return strcmp(
                mb_strtolower($a->getUsername() ?? ''),
                mb_strtolower($b->getUsername() ?? '')
            );
        });

        $pendingEmissions = [];
        $pendingEmissionsCount = 0;

        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            $pendingEmissions = $emissionRepository->findAllPendingCompletion();
            $pendingEmissionsCount = $emissionRepository->countAllPendingCompletion();
        } elseif ($user instanceof User) {
            $pendingEmissions = $emissionRepository->findPendingCompletionForUser($user, 5);
            $pendingEmissionsCount = $emissionRepository->countPendingCompletionForUser($user);
        }

        return $this->render('admin/index.html.twig', [
            'user' => $user,
            'users' => $users,
            'pendingEmissions' => $pendingEmissions,
            'pendingEmissionsCount' => $pendingEmissionsCount,
        ]);
    }
}
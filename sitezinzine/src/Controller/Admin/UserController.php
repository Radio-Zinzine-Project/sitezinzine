<?php

namespace App\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use App\Form\UserRolesType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/admin/user', name: 'admin.user.')]
#[IsGranted("ROLE_ADMIN")]
#[IsGranted("ROLE_SUPER_ADMIN")]
class UserController extends AbstractController
{
    private const ROLES = [
        'ROLE_USER' => 'Utilisateur',
        'ROLE_EDITOR' => 'Éditeur',
        'ROLE_ADMIN' => 'Administrateur',
        'ROLE_SUPER_ADMIN' => 'Super Administrateur'

    ];

    #[Route('/', name: 'index')]
    public function index(
        Request $request,
        UserRepository $userRepository
    ): Response {
        $allowedSorts = [
            'id' => 'id',
            'username' => 'username',
            'email' => 'email',
        ];

        $sort = (string) $request->query->get('sort', 'username');
        $direction = strtoupper((string) $request->query->get('direction', 'ASC'));

        if (!isset($allowedSorts[$sort])) {
            $sort = 'username';
        }

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $users = $userRepository->findBy(
            [],
            [$allowedSorts[$sort] => $direction]
        );

        return $this->render('admin/user/index.html.twig', [
            'users' => $users,
            'available_roles' => self::ROLES,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit')]
    public function edit(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(UserRolesType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Rôles modifiés avec succès');
            return $this->redirectToRoute('admin.user.index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView()
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete-user-' . $user->getId(), $request->request->get('_token'))) {
            $em->remove($user);
            $em->flush();

            $this->addFlash('success', 'L\'utilisateur a bien été supprimé.');
        } else {
            $this->addFlash('error', 'Jeton CSRF invalide. Suppression annulée.');
        }

        return $this->redirectToRoute('admin.user.index');
    }
}

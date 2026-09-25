<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\UserRolesType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/user', name: 'admin.user.')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    private const ROLES = [
        'ROLE_USER' => 'Utilisateur',
        'ROLE_EDITOR' => 'Éditeur',
        'ROLE_ADMIN' => 'Administrateur',
        'ROLE_SUPER_ADMIN' => 'Super Administrateur',
    ];

    #[Route('/', name: 'index', methods: ['GET'])]
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
        $direction = strtoupper(
            (string) $request->query->get('direction', 'ASC')
        );

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

    #[Route(
        '/{id}/edit',
        name: 'edit',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+']
    )]
    public function edit(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager
    ): Response {
        $canManageSuperAdmin = $this->isGranted('ROLE_SUPER_ADMIN');

        $wasSuperAdmin = in_array(
            'ROLE_SUPER_ADMIN',
            $user->getRoles(),
            true
        );

        $currentUser = $this->getUser();

        $isEditingSelf = $currentUser instanceof User
            && $currentUser->getId() === $user->getId();

        $form = $this->createForm(
            UserRolesType::class,
            $user,
            [
                'can_manage_super_admin' => $canManageSuperAdmin,
            ]
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $roles = $user->getRoles();

            /*
         * Un ADMIN ne peut jamais gérer ROLE_SUPER_ADMIN.
         *
         * - si la personne éditée était déjà SUPER_ADMIN,
         *   le rôle est conservé ;
         * - sinon, une éventuelle tentative d'injection
         *   de ROLE_SUPER_ADMIN est supprimée.
         */
            if (!$canManageSuperAdmin) {
                if ($wasSuperAdmin) {
                    if (!in_array('ROLE_SUPER_ADMIN', $roles, true)) {
                        $roles[] = 'ROLE_SUPER_ADMIN';
                    }
                } else {
                    $roles = array_values(
                        array_filter(
                            $roles,
                            static fn(string $role): bool =>
                            $role !== 'ROLE_SUPER_ADMIN'
                        )
                    );
                }
            }

            /*
         * Un SUPER_ADMIN ne peut jamais retirer son propre
         * rôle SUPER_ADMIN.
         *
         * Pour être rétrogradé, son rôle doit être retiré
         * par un autre SUPER_ADMIN.
         */
            if (
                $canManageSuperAdmin
                && $isEditingSelf
                && $wasSuperAdmin
                && !in_array('ROLE_SUPER_ADMIN', $roles, true)
            ) {
                $roles[] = 'ROLE_SUPER_ADMIN';
            }

            $user->setRoles(
                array_values(array_unique($roles))
            );

            $entityManager->flush();

            $this->addFlash(
                'success',
                'Rôles modifiés avec succès.'
            );

            return $this->redirectToRoute('admin.user.index');
        }

        return $this->render('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }

    #[Route(
        '/{id}/approve',
        name: 'approve',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function approve(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid(
            'approve-user-' . $user->getId(),
            $request->request->getString('_token')
        )) {
            $this->addFlash(
                'error',
                'Jeton CSRF invalide. Validation annulée.'
            );

            return $this->redirectToRoute('admin.user.index');
        }

        if (!$user->isVerified()) {
            $this->addFlash(
                'error',
                'Ce compte ne peut pas être validé tant que son adresse e-mail n’a pas été confirmée.'
            );

            return $this->redirectToRoute('admin.user.index');
        }

        if ($user->isApproved()) {
            $this->addFlash(
                'info',
                'Ce compte est déjà validé.'
            );

            return $this->redirectToRoute('admin.user.index');
        }

        $user->setApproved(true);

        $entityManager->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Le compte de %s a bien été validé.',
                $user->getUsername()
            )
        );

        return $this->redirectToRoute('admin.user.index');
    }

    #[Route(
        '/{id}',
        name: 'delete',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function delete(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid(
            'delete-user-' . $user->getId(),
            $request->request->getString('_token')
        )) {
            $this->addFlash(
                'error',
                'Jeton CSRF invalide. Désactivation annulée.'
            );

            return $this->redirectToRoute('admin.user.index');
        }

        $user->deactivate();

        $entityManager->flush();

        $this->addFlash(
            'success',
            'Le compte de ' . $user->getUsername() . ' a bien été désactivé.'
        );

        return $this->redirectToRoute('admin.user.index');
    }
}

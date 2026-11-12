<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\UserController;
use App\Entity\User;
use App\Form\UserRolesType;
use App\Repository\UserRepository;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class UserControllerTest extends ControllerActionTestCase
{
    private UserController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new UserController();
        $this->wire($this->controller);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($this->controller);
        }
    }

    /** @dataProvider sorts */
    public function testIndexRestrictsAndNormalizesSorting(array $query, string $sort, string $direction): void
    {
        $users = [(new User())->setUsername('alice')];
        $repo = $this->createMock(UserRepository::class);
        $repo->expects(self::once())->method('findBy')->with([], [$sort => $direction])->willReturn($users);
        self::assertSame(200, $this->controller->index(Request::create('/', 'GET', $query), $repo)->getStatusCode());
        self::assertSame('admin/user/index.html.twig', $this->template);
        self::assertSame($users, $this->parameters['users']);
        self::assertSame($sort, $this->parameters['sort']);
        self::assertSame($direction, $this->parameters['direction']);
        self::assertSame(['ROLE_USER', 'ROLE_EDITOR', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], array_keys($this->parameters['available_roles']));
    }

    public static function sorts(): iterable
    {
        yield 'défaut' => [[], 'username', 'ASC'];
        yield 'email décroissant' => [['sort' => 'email', 'direction' => 'desc'], 'email', 'DESC'];
        yield 'identifiant' => [['sort' => 'id'], 'id', 'ASC'];
        yield 'champ interdit' => [['sort' => 'password', 'direction' => 'DESC'], 'username', 'DESC'];
        yield 'direction invalide' => [['sort' => 'email', 'direction' => 'arbitrary'], 'email', 'ASC'];
    }

    /** @dataProvider formStates */
    public function testEditFlushesOnlyAValidRolesForm(
        bool $submitted,
        bool $valid
    ): void {
        $admin = (new User())
            ->setUsername('admin')
            ->setRoles(['ROLE_ADMIN']);

        $this->identify($admin, 1);

        $user = (new User())
            ->setUsername('user')
            ->setRoles(['ROLE_USER']);

        $this->identify($user, 2);

        $authorizationChecker = $this->createMock(
            AuthorizationCheckerInterface::class
        );

        $authorizationChecker
            ->expects(self::once())
            ->method('isGranted')
            ->with('ROLE_SUPER_ADMIN', null)
            ->willReturn(false);

        $this->container->set(
            'security.authorization_checker',
            $authorizationChecker
        );

        $tokenStorage = $this->createMock(
            TokenStorageInterface::class
        );

        $token = new UsernamePasswordToken(
            $admin,
            'main',
            $admin->getRoles()
        );

        $tokenStorage
            ->method('getToken')
            ->willReturn($token);

        $this->container->set(
            'security.token_storage',
            $tokenStorage
        );

        $this->form(
            UserRolesType::class,
            $submitted,
            $valid,
            $user,
            static function (User $data, array $options): void {
                self::assertFalse(
                    $options['can_manage_super_admin']
                );
            }
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects($submitted && $valid ? self::once() : self::never())
            ->method('flush');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $response = $this->controller->edit(
            Request::create('/'),
            $user,
            $entityManager
        );

        if ($submitted && $valid) {
            self::assertTrue($response->isRedirect());

            self::assertSame(
                ['admin.user.index', []],
                $this->redirect
            );

            self::assertSame(
                ['Rôles modifiés avec succès.'],
                $this->session->getFlashBag()->get('success')
            );

            return;
        }

        self::assertSame(
            200,
            $response->getStatusCode()
        );

        self::assertSame(
            'admin/user/edit.html.twig',
            $this->template
        );

        self::assertSame(
            $user,
            $this->parameters['user']
        );

        self::assertSame(
            [],
            $this->session->getFlashBag()->all()
        );
    }

    /** @dataProvider deletionTokens */
    public function testDeleteRequiresTokenForThisUser(string $tokenId): void
    {
        $user = (new User())
            ->setUsername('user-to-deactivate');

        $this->identify($user, 42);

        $valid = 'delete-user-42' === $tokenId;

        $request = $this->tokenRequest(
            $tokenId,
            true
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        /*
     * Un soft delete ne doit jamais supprimer physiquement
     * l'utilisateur de la base.
     */
        $entityManager
            ->expects(self::never())
            ->method('remove');

        $entityManager
            ->expects($valid ? self::once() : self::never())
            ->method('flush');

        $response = $this->controller->delete(
            $request,
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertSame(
            ['admin.user.index', []],
            $this->redirect
        );

        if ($valid) {
            self::assertTrue($user->isDeleted());
            self::assertInstanceOf(
                \DateTimeImmutable::class,
                $user->getDeletedAt()
            );

            self::assertSame(
                ['success' => [
                    'Le compte de user-to-deactivate a bien été désactivé.',
                ]],
                $this->session->getFlashBag()->all()
            );

            return;
        }

        self::assertFalse($user->isDeleted());
        self::assertNull($user->getDeletedAt());

        self::assertSame(
            ['error' => [
                'Jeton CSRF invalide. Désactivation annulée.',
            ]],
            $this->session->getFlashBag()->all()
        );
    }

    public static function deletionTokens(): iterable
    {
        yield 'bon utilisateur' => ['delete-user-42'];
        yield 'autre utilisateur' => ['delete-user-99'];
    }

    public function testApproveVerifiedUser(): void
    {
        $user = (new User())
            ->setUsername('verified-user')
            ->setRoles(['ROLE_USER'])
            ->setVerified(true)
            ->setApproved(false);

        $this->identify($user, 42);

        $request = $this->tokenRequest(
            'approve-user-42',
            true
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('remove');

        $response = $this->controller->approve(
            $request,
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertSame(
            ['admin.user.index', []],
            $this->redirect
        );

        self::assertTrue($user->isApproved());

        /*
     * L'approbation ne doit pas modifier les rôles stockés.
     *
     * ROLE_VERIFIED est ajouté dynamiquement par User::getRoles()
     * parce que l'adresse e-mail est vérifiée.
     */
        self::assertSame(
            ['ROLE_USER', 'ROLE_VERIFIED'],
            array_values($user->getRoles())
        );

        self::assertSame(
            ['success' => ['Le compte de verified-user a bien été validé.']],
            $this->session->getFlashBag()->all()
        );
    }

    public function testApproveRefusesUnverifiedUser(): void
    {
        $user = (new User())
            ->setUsername('unverified-user')
            ->setRoles(['ROLE_USER'])
            ->setVerified(false)
            ->setApproved(false);

        $this->identify($user, 42);

        $request = $this->tokenRequest(
            'approve-user-42',
            true
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::never())
            ->method('flush');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('remove');

        $response = $this->controller->approve(
            $request,
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertSame(
            ['admin.user.index', []],
            $this->redirect
        );

        self::assertFalse($user->isApproved());

        self::assertSame(
            ['error' => [
                'Ce compte ne peut pas être validé tant que son adresse e-mail n’a pas été confirmée.',
            ]],
            $this->session->getFlashBag()->all()
        );
    }

    public function testApproveAlreadyApprovedUserDoesNothing(): void
    {
        $user = (new User())
            ->setUsername('approved-user')
            ->setRoles(['ROLE_USER'])
            ->setVerified(true)
            ->setApproved(true);

        $this->identify($user, 42);

        $request = $this->tokenRequest(
            'approve-user-42',
            true
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::never())
            ->method('flush');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('remove');

        $response = $this->controller->approve(
            $request,
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertSame(
            ['admin.user.index', []],
            $this->redirect
        );

        self::assertTrue($user->isApproved());

        self::assertSame(
            ['info' => ['Ce compte est déjà validé.']],
            $this->session->getFlashBag()->all()
        );
    }

    public function testApproveRejectsInvalidCsrfToken(): void
    {
        $user = (new User())
            ->setUsername('verified-user')
            ->setRoles(['ROLE_USER'])
            ->setVerified(true)
            ->setApproved(false);

        $this->identify($user, 42);

        $request = $this->tokenRequest(
            'approve-user-99',
            true
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::never())
            ->method('flush');

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('remove');

        $response = $this->controller->approve(
            $request,
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertSame(
            ['admin.user.index', []],
            $this->redirect
        );

        self::assertFalse($user->isApproved());

        self::assertSame(
            ['error' => [
                'Jeton CSRF invalide. Validation annulée.',
            ]],
            $this->session->getFlashBag()->all()
        );
    }

    public function testAdminCannotRemoveSuperAdminRoleFromExistingSuperAdmin(): void
    {
        $admin = (new User())
            ->setUsername('admin')
            ->setRoles(['ROLE_ADMIN']);

        $this->identify($admin, 1);

        $user = (new User())
            ->setUsername('super-admin')
            ->setRoles(['ROLE_SUPER_ADMIN']);

        $this->identify($user, 2);

        $authorizationChecker = $this->createMock(
            AuthorizationCheckerInterface::class
        );

        $authorizationChecker
            ->expects(self::once())
            ->method('isGranted')
            ->with('ROLE_SUPER_ADMIN', null)
            ->willReturn(false);

        $this->container->set(
            'security.authorization_checker',
            $authorizationChecker
        );

        $tokenStorage = $this->createMock(
            TokenStorageInterface::class
        );

        $token = new UsernamePasswordToken(
            $admin,
            'main',
            $admin->getRoles()
        );

        $tokenStorage
            ->method('getToken')
            ->willReturn($token);

        $this->container->set(
            'security.token_storage',
            $tokenStorage
        );

        $this->form(
            UserRolesType::class,
            true,
            true,
            $user,
            static function (User $data, array $options): void {
                self::assertFalse(
                    $options['can_manage_super_admin']
                );

                /*
             * Simule le résultat du formulaire :
             * ROLE_SUPER_ADMIN n'est pas proposé à un ADMIN.
             */
                $data->setRoles(['ROLE_USER']);
            }
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $response = $this->controller->edit(
            Request::create('/'),
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertContains(
            'ROLE_SUPER_ADMIN',
            $user->getRoles()
        );
    }

    public function testAdminCannotAddSuperAdminRoleToUser(): void
    {
        $admin = (new User())
            ->setUsername('admin')
            ->setRoles(['ROLE_ADMIN']);

        $this->identify($admin, 1);

        $user = (new User())
            ->setUsername('user')
            ->setRoles(['ROLE_USER']);

        $this->identify($user, 2);

        $authorizationChecker = $this->createMock(
            AuthorizationCheckerInterface::class
        );

        $authorizationChecker
            ->expects(self::once())
            ->method('isGranted')
            ->with('ROLE_SUPER_ADMIN', null)
            ->willReturn(false);

        $this->container->set(
            'security.authorization_checker',
            $authorizationChecker
        );

        $tokenStorage = $this->createMock(
            TokenStorageInterface::class
        );

        $token = new UsernamePasswordToken(
            $admin,
            'main',
            $admin->getRoles()
        );

        $tokenStorage
            ->method('getToken')
            ->willReturn($token);

        $this->container->set(
            'security.token_storage',
            $tokenStorage
        );

        $this->form(
            UserRolesType::class,
            true,
            true,
            $user,
            static function (User $data, array $options): void {
                self::assertFalse(
                    $options['can_manage_super_admin']
                );

                /*
             * Simule une valeur injectée malgré le formulaire.
             * Le contrôleur constitue ici la seconde barrière.
             */
                $data->setRoles([
                    'ROLE_USER',
                    'ROLE_SUPER_ADMIN',
                ]);
            }
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $response = $this->controller->edit(
            Request::create('/'),
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertNotContains(
            'ROLE_SUPER_ADMIN',
            $user->getRoles()
        );
    }

    public function testSuperAdminCanRemoveSuperAdminRoleFromAnotherUser(): void
    {
        $superAdmin = (new User())
            ->setUsername('super-admin-actor')
            ->setRoles(['ROLE_SUPER_ADMIN']);

        $this->identify($superAdmin, 1);

        $user = (new User())
            ->setUsername('super-admin-target')
            ->setRoles(['ROLE_SUPER_ADMIN']);

        $this->identify($user, 2);

        $authorizationChecker = $this->createMock(
            AuthorizationCheckerInterface::class
        );

        $authorizationChecker
            ->expects(self::once())
            ->method('isGranted')
            ->with('ROLE_SUPER_ADMIN', null)
            ->willReturn(true);

        $this->container->set(
            'security.authorization_checker',
            $authorizationChecker
        );

        $tokenStorage = $this->createMock(
            TokenStorageInterface::class
        );

        $token = new UsernamePasswordToken(
            $superAdmin,
            'main',
            $superAdmin->getRoles()
        );

        $tokenStorage
            ->method('getToken')
            ->willReturn($token);

        $this->container->set(
            'security.token_storage',
            $tokenStorage
        );

        $this->form(
            UserRolesType::class,
            true,
            true,
            $user,
            static function (User $data, array $options): void {
                self::assertTrue(
                    $options['can_manage_super_admin']
                );

                $data->setRoles(['ROLE_ADMIN']);
            }
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $response = $this->controller->edit(
            Request::create('/'),
            $user,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertNotContains(
            'ROLE_SUPER_ADMIN',
            $user->getRoles()
        );

        self::assertContains(
            'ROLE_ADMIN',
            $user->getRoles()
        );
    }

    public function testSuperAdminCannotRemoveOwnSuperAdminRole(): void
    {
        $superAdmin = (new User())
            ->setUsername('super-admin')
            ->setRoles(['ROLE_SUPER_ADMIN']);

        $this->identify($superAdmin, 1);

        $authorizationChecker = $this->createMock(
            AuthorizationCheckerInterface::class
        );

        $authorizationChecker
            ->expects(self::once())
            ->method('isGranted')
            ->with('ROLE_SUPER_ADMIN', null)
            ->willReturn(true);

        $this->container->set(
            'security.authorization_checker',
            $authorizationChecker
        );

        $tokenStorage = $this->createMock(
            TokenStorageInterface::class
        );

        $token = new UsernamePasswordToken(
            $superAdmin,
            'main',
            $superAdmin->getRoles()
        );

        $tokenStorage
            ->method('getToken')
            ->willReturn($token);

        $this->container->set(
            'security.token_storage',
            $tokenStorage
        );

        $this->form(
            UserRolesType::class,
            true,
            true,
            $superAdmin,
            static function (User $data, array $options): void {
                self::assertTrue(
                    $options['can_manage_super_admin']
                );

                $data->setRoles(['ROLE_ADMIN']);
            }
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::once())
            ->method('flush');

        $response = $this->controller->edit(
            Request::create('/'),
            $superAdmin,
            $entityManager
        );

        self::assertTrue($response->isRedirect());

        self::assertContains(
            'ROLE_SUPER_ADMIN',
            $superAdmin->getRoles()
        );

        self::assertContains(
            'ROLE_ADMIN',
            $superAdmin->getRoles()
        );
    }
}

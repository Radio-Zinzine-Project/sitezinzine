<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\UserRolesType;
use App\Tests\Support\FormIntegrationTestCase;

class UserRolesTypeTest extends FormIntegrationTestCase
{
    public function testAdminCanAssignRolesUpToAdmin(): void
    {
        $user = $this->user(['ROLE_EDITOR']);

        $form = $this->form(
            UserRolesType::class,
            $user,
            [
                'can_manage_super_admin' => false,
            ]
        );

        $form->submit([
            'roles' => ['ROLE_ADMIN'],
        ]);

        $this->assertValidForm($form);

        self::assertContains(
            'ROLE_ADMIN',
            $user->getRoles()
        );

        self::assertNotContains(
            'ROLE_EDITOR',
            $user->getRoles()
        );

        self::assertNotContains(
            'ROLE_SUPER_ADMIN',
            $user->getRoles()
        );

        self::assertTrue(
            $form->get('roles')
                ->getConfig()
                ->getOption('expanded')
        );
    }

    public function testSuperAdminCanAssignSuperAdminRole(): void
    {
        $user = $this->user(['ROLE_EDITOR']);

        $form = $this->form(
            UserRolesType::class,
            $user,
            [
                'can_manage_super_admin' => true,
            ]
        );

        $form->submit([
            'roles' => ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'],
        ]);

        $this->assertValidForm($form);

        self::assertContains(
            'ROLE_ADMIN',
            $user->getRoles()
        );

        self::assertContains(
            'ROLE_SUPER_ADMIN',
            $user->getRoles()
        );

        self::assertNotContains(
            'ROLE_EDITOR',
            $user->getRoles()
        );
    }
    public function testUnknownRoleIsRejected(): void
    {
        $user = $this->user();
        $form = $this->form(UserRolesType::class, $user);
        $form->submit(['roles' => ['ROLE_ROOT']]);
        self::assertFalse($form->isValid());
        self::assertNotContains('ROLE_ROOT', $user->getRoles());
    }
    public function testOmittedRolesClearElevatedPrivileges(): void
    {
        $user = $this->user(['ROLE_ADMIN']);
        $form = $this->form(UserRolesType::class, $user);
        $form->submit([]);
        $this->assertValidForm($form);
        self::assertSame(['ROLE_USER'], array_values($user->getRoles()));
    }

    public function testAdminCannotSubmitSuperAdminRole(): void
    {
        $user = $this->user(['ROLE_USER']);

        $form = $this->form(
            UserRolesType::class,
            $user,
            [
                'can_manage_super_admin' => false,
            ]
        );

        $form->submit([
            'roles' => ['ROLE_SUPER_ADMIN'],
        ]);

        self::assertFalse($form->isValid());

        self::assertNotContains(
            'ROLE_SUPER_ADMIN',
            $user->getRoles()
        );
    }
}

<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

class UserCheckerTest extends TestCase
{
    private UserChecker $userChecker;

    protected function setUp(): void
    {
        $this->userChecker = new UserChecker();
    }

    public function testUnverifiedAndUnapprovedUserCannotLogin(): void
    {
        $user = (new User())
            ->setVerified(false)
            ->setApproved(false);

        $this->expectException(
            CustomUserMessageAccountStatusException::class
        );

        $this->expectExceptionMessage(
            'Votre adresse e-mail n’a pas encore été confirmée.'
        );

        $this->userChecker->checkPreAuth($user);
    }

    public function testUnverifiedButApprovedUserCannotLogin(): void
    {
        $user = (new User())
            ->setVerified(false)
            ->setApproved(true);

        $this->expectException(
            CustomUserMessageAccountStatusException::class
        );

        $this->expectExceptionMessage(
            'Votre adresse e-mail n’a pas encore été confirmée.'
        );

        $this->userChecker->checkPreAuth($user);
    }

    public function testVerifiedButUnapprovedUserCannotLogin(): void
    {
        $user = (new User())
            ->setVerified(true)
            ->setApproved(false);

        $this->expectException(
            CustomUserMessageAccountStatusException::class
        );

        $this->expectExceptionMessage(
            'Votre compte est en attente de validation par Radio Zinzine.'
        );

        $this->userChecker->checkPreAuth($user);
    }

    public function testDeletedUserCannotLogin(): void
    {
        $user = (new User())
            ->setVerified(true)
            ->setApproved(true)
            ->deactivate();

        $this->expectException(
            CustomUserMessageAccountStatusException::class
        );

        $this->expectExceptionMessage(
            'Votre compte a été désactivé.'
        );

        $this->userChecker->checkPreAuth($user);
    }

    public function testVerifiedAndApprovedUserCanLogin(): void
    {
        $user = (new User())
            ->setVerified(true)
            ->setApproved(true);

        /*
         * Aucune exception ne doit être levée.
         */
        $this->userChecker->checkPreAuth($user);

        $this->addToAssertionCount(1);
    }

    public function testPostAuthenticationCheckDoesNotRejectValidUser(): void
    {
        $user = (new User())
            ->setVerified(true)
            ->setApproved(true);

        /*
         * Le contrôle métier est actuellement effectué avant
         * l'authentification. Le contrôle post-authentification
         * ne doit donc rien bloquer.
         */
        $this->userChecker->checkPostAuth($user);

        $this->addToAssertionCount(1);
    }
}

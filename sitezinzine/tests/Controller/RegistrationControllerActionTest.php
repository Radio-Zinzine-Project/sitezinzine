<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RegistrationController;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Complète les parcours d'inscription et de confirmation déjà testés via HTTP. */
class RegistrationControllerActionTest extends ControllerActionTestCase
{
    public function testInvalidRegistrationDoesNotPersistOrSendEmail(): void
    {
        $verifier = $this->createMock(EmailVerifier::class);

        $verifier
            ->expects(self::never())
            ->method('sendEmailConfirmation');

        $controller = new RegistrationController($verifier);
        $this->wire($controller);

        $this->form(
            RegistrationFormType::class,
            true,
            false
        );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::never())
            ->method('flush');

        $hasher = $this->createMock(
            UserPasswordHasherInterface::class
        );

        $hasher
            ->expects(self::never())
            ->method('hashPassword');

        $response = $controller->register(
            Request::create('/register', 'POST'),
            $hasher,
            $entityManager
        );

        self::assertSame(
            422,
            $response->getStatusCode()
        );

        self::assertSame(
            'registration/register.html.twig',
            $this->template
        );

        self::assertArrayHasKey(
            'registrationForm',
            $this->parameters
        );
    }

    public function testVerificationWithoutUserIdStopsBeforeValidatingLink(): void
    {
        $verifier = $this->createMock(EmailVerifier::class);

        $verifier
            ->expects(self::never())
            ->method('handleEmailConfirmation');

        $controller = new RegistrationController($verifier);
        $this->wire($controller);

        $userRepository = $this->createMock(
            UserRepository::class
        );

        $userRepository
            ->expects(self::never())
            ->method('find');

        $translator = $this->createMock(
            TranslatorInterface::class
        );

        $this->expectException(
            NotFoundHttpException::class
        );

        $controller->verifyUserEmail(
            Request::create('/verify/email'),
            $userRepository,
            $translator
        );
    }
}

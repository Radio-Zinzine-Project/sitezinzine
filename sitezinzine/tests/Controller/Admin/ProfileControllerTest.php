<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\ProfileController;
use App\Entity\User;
use App\Form\ProfileType;
use App\Form\ChangePasswordType;
use App\Security\EmailVerifier;
use App\Tests\Support\ControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ProfileControllerTest extends ControllerActionTestCase
{
    private ProfileController $controller;

    private EmailVerifier&MockObject $verifier;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = $this->createMock(EmailVerifier::class);

        $this->controller = new ProfileController($this->verifier);
        $this->wire($this->controller);

        $this->user = (new User())
            ->setUsername('alice')
            ->setEmail('old@example.com')
            ->setVerified(true);

        $storage = new TokenStorage();

        $storage->setToken(
            new UsernamePasswordToken(
                $this->user,
                'main',
                ['ROLE_USER']
            )
        );

        $this->container->set('security.token_storage', $storage);
    }

    /**
     * @dataProvider unchangedEmails
     */
    public function testEditDoesNotResendConfirmationForUnchangedOrPendingEmail(
        string $email
    ): void {
        $this->user->setPendingEmail('pending@example.com');

        $this->form(
            ProfileType::class,
            true,
            true,
            $this->user,
            fields: [
                'newEmail' => $email,
            ]
        );

        $em = $this->createMock(EntityManagerInterface::class);

        $em
            ->expects(self::once())
            ->method('flush');

        $this->verifier
            ->expects(self::never())
            ->method('sendEmailConfirmation');

        self::assertTrue(
            $this->controller
                ->edit(Request::create('/'), $em)
                ->isRedirect()
        );

        self::assertSame(
            'old@example.com',
            $this->user->getEmail()
        );

        self::assertSame(
            'pending@example.com',
            $this->user->getPendingEmail()
        );

        self::assertTrue(
            $this->user->isVerified()
        );

        self::assertSame(
            ['app_profile_edit', []],
            $this->redirect
        );

        self::assertSame(
            ['Profil mis à jour.'],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }

    public static function unchangedEmails(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'actuel' => ['old@example.com'];
        yield 'déjà en attente' => ['pending@example.com'];
    }

    public function testEditStoresNewEmailAsPendingAndKeepsCurrentEmailVerified(): void
    {
        $this->form(
            ProfileType::class,
            true,
            true,
            $this->user,
            prepare: static function ($user, array $options): void {
                self::assertSame(
                    ['data-turbo' => 'false'],
                    $options['attr']
                );
            },
            fields: [
                'newEmail' => ' new@example.com ',
            ]
        );

        $flushed = false;

        $em = $this->createMock(EntityManagerInterface::class);

        $em
            ->expects(self::once())
            ->method('flush')
            ->willReturnCallback(
                function () use (&$flushed): void {
                    self::assertSame(
                        'old@example.com',
                        $this->user->getEmail()
                    );

                    self::assertSame(
                        'new@example.com',
                        $this->user->getPendingEmail()
                    );

                    /*
                 * L'adresse actuelle reste l'adresse vérifiée
                 * tant que la nouvelle n'a pas été confirmée.
                 */
                    self::assertTrue(
                        $this->user->isVerified()
                    );

                    $flushed = true;
                }
            );

        $this->verifier
            ->expects(self::once())
            ->method('sendEmailConfirmation')
            ->with(
                'app_profile_verify_new_email',
                self::identicalTo($this->user),
                self::callback(
                    static function (TemplatedEmail $email) use (&$flushed): bool {
                        self::assertTrue($flushed);

                        self::assertSame(
                            'new@example.com',
                            $email->getTo()[0]->getAddress()
                        );

                        self::assertSame(
                            'Confirmez votre nouvelle adresse email',
                            $email->getSubject()
                        );

                        self::assertSame(
                            'admin/profile/confirm_new_email.html.twig',
                            $email->getHtmlTemplate()
                        );

                        /*
                     * L'expéditeur est désormais défini
                     * exclusivement par EmailVerifier.
                     */
                        self::assertSame(
                            [],
                            $email->getFrom()
                        );

                        return true;
                    }
                ),
                'new@example.com'
            );

        self::assertTrue(
            $this->controller
                ->edit(Request::create('/'), $em)
                ->isRedirect()
        );

        self::assertSame(
            'old@example.com',
            $this->user->getEmail()
        );

        self::assertSame(
            'new@example.com',
            $this->user->getPendingEmail()
        );

        self::assertTrue(
            $this->user->isVerified()
        );

        self::assertSame(
            ['app_profile_edit', []],
            $this->redirect
        );

        self::assertSame(
            [
                'Un email de confirmation a été envoyé à votre nouvelle adresse.',
            ],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }

    /**
     * @dataProvider unsubmittedOrInvalid
     */
    public function testEditDoesNotWriteOrSendForUnsubmittedOrInvalidForm(
        bool $submitted
    ): void {
        $this->form(
            ProfileType::class,
            $submitted,
            false,
            $this->user
        );

        $em = $this->createMock(EntityManagerInterface::class);

        $em
            ->expects(self::never())
            ->method('flush');

        $this->verifier
            ->expects(self::never())
            ->method('sendEmailConfirmation');

        self::assertSame(
            200,
            $this->controller
                ->edit(Request::create('/'), $em)
                ->getStatusCode()
        );

        self::assertSame(
            'admin/profile/edit.html.twig',
            $this->template
        );

        self::assertArrayHasKey(
            'form',
            $this->parameters
        );

        self::assertSame(
            [],
            $this->session
                ->getFlashBag()
                ->all()
        );
    }

    public static function unsubmittedOrInvalid(): iterable
    {
        yield 'affichage' => [false];
        yield 'invalide' => [true];
    }

    public function testVerifyValidatesExactPendingEmailBeforePromotingIt(): void
    {
        $this->authorize(true);

        $this->user
            ->setPendingEmail('pending@example.com')
            ->setVerified(true);

        $request = Request::create('/profil/verify-email');

        $this->verifier
            ->expects(self::once())
            ->method('handleEmailConfirmation')
            ->with(
                $request,
                self::identicalTo($this->user),
                'pending@example.com'
            )
            ->willReturnCallback(
                function (): void {
                    /*
                 * Tant que le lien n'est pas validé,
                 * l'ancienne adresse reste active et vérifiée.
                 */
                    self::assertSame(
                        'old@example.com',
                        $this->user->getEmail()
                    );

                    self::assertSame(
                        'pending@example.com',
                        $this->user->getPendingEmail()
                    );

                    self::assertTrue(
                        $this->user->isVerified()
                    );
                }
            );

        $em = $this->createMock(EntityManagerInterface::class);

        $em
            ->expects(self::once())
            ->method('flush')
            ->willReturnCallback(
                function (): void {
                    self::assertSame(
                        'pending@example.com',
                        $this->user->getEmail()
                    );

                    self::assertNull(
                        $this->user->getPendingEmail()
                    );

                    self::assertTrue(
                        $this->user->isVerified()
                    );
                }
            );

        self::assertTrue(
            $this->controller
                ->verifyNewEmail($request, $em)
                ->isRedirect()
        );

        self::assertSame(
            ['app_profile_edit', []],
            $this->redirect
        );

        self::assertSame(
            ['Votre nouvelle adresse email a été confirmée.'],
            $this->session
                ->getFlashBag()
                ->get('success')
        );
    }

    public function testVerifyRejectsRequestWhenNoEmailIsPending(): void
    {
        $this->authorize(true);

        $this->user
            ->setPendingEmail(null)
            ->setVerified(true);

        $request = Request::create('/profil/verify-email');

        $this->verifier
            ->expects(self::never())
            ->method('handleEmailConfirmation');

        $em = $this->createMock(EntityManagerInterface::class);

        $em
            ->expects(self::never())
            ->method('flush');

        $this->expectException(
            NotFoundHttpException::class
        );

        $this->controller->verifyNewEmail(
            $request,
            $em
        );
    }

    public function testVerifyRejectsInsufficientAuthentication(): void
    {
        $this->authorize(false);

        $this->verifier
            ->expects(self::never())
            ->method('handleEmailConfirmation');

        $em = $this->createMock(EntityManagerInterface::class);

        $em
            ->expects(self::never())
            ->method('flush');

        $this->expectException(
            AccessDeniedException::class
        );

        $this->controller->verifyNewEmail(
            Request::create('/'),
            $em
        );
    }

    public function testFailedVerificationDoesNotPromoteEmail(): void
    {
        $this->authorize(true);

        $this->user
            ->setPendingEmail('pending@example.com')
            ->setVerified(true);

        $request = Request::create('/profil/verify-email');

        $this->verifier
            ->expects(self::once())
            ->method('handleEmailConfirmation')
            ->with(
                $request,
                self::identicalTo($this->user),
                'pending@example.com'
            )
            ->willThrowException(
                new \RuntimeException('Invalid signature')
            );

        $em = $this->createMock(EntityManagerInterface::class);

        $em
            ->expects(self::never())
            ->method('flush');

        try {
            $this->controller->verifyNewEmail(
                $request,
                $em
            );

            self::fail(
                'La vérification doit échouer.'
            );
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'Invalid signature',
                $exception->getMessage()
            );
        }

        self::assertSame(
            'old@example.com',
            $this->user->getEmail()
        );

        self::assertSame(
            'pending@example.com',
            $this->user->getPendingEmail()
        );

        /*
     * L'échec de confirmation de la nouvelle adresse
     * ne remet pas en cause la vérification de l'adresse actuelle.
     */
        self::assertTrue(
            $this->user->isVerified()
        );

        self::assertSame(
            [],
            $this->session
                ->getFlashBag()
                ->all()
        );
    }

    public function testChangePasswordDisplaysForm(): void
    {
        $this->form(
            ChangePasswordType::class,
            false,
            false,
            expectedData: null,
            prepare: static function ($data, array $options): void {
                self::assertNull($data);

                self::assertSame(
                    ['data-turbo' => 'false'],
                    $options['attr']
                );
            }
        );

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('flush');

        $hasher = $this->createMock(
            UserPasswordHasherInterface::class
        );

        $hasher
            ->expects(self::never())
            ->method('isPasswordValid');

        $hasher
            ->expects(self::never())
            ->method('hashPassword');

        $response = $this->controller->changePassword(
            Request::create('/admin/profil/password'),
            $em,
            $hasher
        );

        self::assertSame(
            200,
            $response->getStatusCode()
        );

        self::assertSame(
            'admin/profile/change_password.html.twig',
            $this->template
        );

        self::assertArrayHasKey(
            'form',
            $this->parameters
        );

        self::assertSame(
            [],
            $this->session
                ->getFlashBag()
                ->all()
        );
    }

    public function testChangePasswordDoesNothingForInvalidForm(): void
    {
        $this->user->setPassword('existing-hash');

        $this->form(
            ChangePasswordType::class,
            true,
            false,
            fields: [
                'currentPassword' => 'ancien-mot-de-passe',
                'newPassword' => 'nouveau-mot-de-passe',
            ]
        );

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('flush');

        $hasher = $this->createMock(
            UserPasswordHasherInterface::class
        );

        $hasher
            ->expects(self::never())
            ->method('isPasswordValid');

        $hasher
            ->expects(self::never())
            ->method('hashPassword');

        $response = $this->controller->changePassword(
            Request::create(
                '/admin/profil/password',
                'POST'
            ),
            $em,
            $hasher
        );

        self::assertSame(
            200,
            $response->getStatusCode()
        );

        self::assertSame(
            'admin/profile/change_password.html.twig',
            $this->template
        );

        self::assertSame(
            'existing-hash',
            $this->user->getPassword()
        );

        self::assertSame(
            [],
            $this->session
                ->getFlashBag()
                ->all()
        );
    }

    public function testChangePasswordRejectsIncorrectCurrentPassword(): void
    {
        $this->user->setPassword('existing-hash');

        $this->form(
            ChangePasswordType::class,
            true,
            true,
            fields: [
                'currentPassword' => 'mauvais-mot-de-passe',
                'newPassword' => 'nouveau-mot-de-passe',
            ]
        );

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::never())
            ->method('flush');

        $hasher = $this->createMock(
            UserPasswordHasherInterface::class
        );

        $hasher
            ->expects(self::once())
            ->method('isPasswordValid')
            ->with(
                self::identicalTo($this->user),
                'mauvais-mot-de-passe'
            )
            ->willReturn(false);

        $hasher
            ->expects(self::never())
            ->method('hashPassword');

        $response = $this->controller->changePassword(
            Request::create(
                '/admin/profil/password',
                'POST'
            ),
            $em,
            $hasher
        );

        self::assertSame(
            200,
            $response->getStatusCode()
        );

        self::assertSame(
            'existing-hash',
            $this->user->getPassword()
        );

        self::assertSame(
            'admin/profile/change_password.html.twig',
            $this->template
        );

        self::assertSame(
            ['Le mot de passe actuel est incorrect.'],
            $this->session
                ->getFlashBag()
                ->get('error')
        );
    }

    public function testChangePasswordHashesNewPasswordAndKeepsUserLoggedIn(): void
    {
        $this->user->setPassword('existing-hash');

        $this->form(
            ChangePasswordType::class,
            true,
            true,
            fields: [
                'currentPassword' => 'ancien-mot-de-passe',
                'newPassword' => 'nouveau-mot-de-passe',
            ]
        );

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->expects(self::once())
            ->method('flush')
            ->willReturnCallback(
                function (): void {
                    self::assertSame(
                        'new-password-hash',
                        $this->user->getPassword()
                    );
                }
            );

        $hasher = $this->createMock(
            UserPasswordHasherInterface::class
        );

        $hasher
            ->expects(self::once())
            ->method('isPasswordValid')
            ->with(
                self::identicalTo($this->user),
                'ancien-mot-de-passe'
            )
            ->willReturn(true);

        $hasher
            ->expects(self::once())
            ->method('hashPassword')
            ->with(
                self::identicalTo($this->user),
                'nouveau-mot-de-passe'
            )
            ->willReturn('new-password-hash');

        $response = $this->controller->changePassword(
            Request::create(
                '/admin/profil/password',
                'POST'
            ),
            $em,
            $hasher
        );

        self::assertTrue(
            $response->isRedirect()
        );

        self::assertSame(
            'new-password-hash',
            $this->user->getPassword()
        );

        self::assertSame(
            ['app_profile_edit', []],
            $this->redirect
        );

        self::assertSame(
            ['Votre mot de passe a été modifié.'],
            $this->session
                ->getFlashBag()
                ->get('success')
        );

        /*
     * Le contrôleur ne remplace ni ne supprime le token :
     * l'utilisateur reste connecté après le changement.
     */
        $tokenStorage = $this->container->get(
            'security.token_storage'
        );

        self::assertNotNull(
            $tokenStorage->getToken()
        );

        self::assertSame(
            $this->user,
            $tokenStorage
                ->getToken()
                ->getUser()
        );
    }

    private function authorize(bool $allowed): void
    {
        $checker = $this->createMock(
            AuthorizationCheckerInterface::class
        );

        $checker
            ->expects(self::once())
            ->method('isGranted')
            ->with(
                'IS_AUTHENTICATED_FULLY',
                null
            )
            ->willReturn($allowed);

        $this->container->set(
            'security.authorization_checker',
            $checker
        );
    }
}

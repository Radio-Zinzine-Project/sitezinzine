<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ResetPasswordController;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Form\ForgotPasswordRequestType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use App\Tests\Support\PublicControllerActionTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ResetPasswordControllerTest extends PublicControllerActionTestCase
{
    /** @dataProvider requests */
    public function testRequestDoesNotRevealWhetherEmailExists(
        bool $submitted,
        bool $valid,
        bool $known
    ): void {
        $controller = new ResetPasswordController();
        $this->wire($controller);

        $this->unmappedForm(
            ForgotPasswordRequestType::class,
            $submitted,
            $valid,
            ['email' => 'listener@example.com']
        );

        $user = (new User())
            ->setEmail('listener@example.com');

        $users = $this->createMock(UserRepository::class);

        $users
            ->expects($submitted && $valid ? self::once() : self::never())
            ->method('findOneBy')
            ->with(['email' => 'listener@example.com'])
            ->willReturn($known ? $user : null);

        $em = $this->createMock(EntityManagerInterface::class);
        $mailer = $this->createMock(MailerInterface::class);

        $sends = $submitted && $valid && $known;

        $saved = null;
        $before = new \DateTimeImmutable('+1 hour');

        if ($sends) {
            $query = $this->createMock(Query::class);

            $query
                ->expects(self::once())
                ->method('setParameter')
                ->with('u', $user)
                ->willReturnSelf();

            $query
                ->expects(self::once())
                ->method('execute');

            $em
                ->expects(self::once())
                ->method('createQuery')
                ->with(
                    'DELETE FROM App\Entity\PasswordResetToken t WHERE t.user = :u'
                )
                ->willReturn($query);

            $em
                ->expects(self::once())
                ->method('persist')
                ->willReturnCallback(
                    static function (
                        PasswordResetToken $token
                    ) use (&$saved, $user, $before): void {
                        $saved = $token;

                        self::assertSame(
                            $user,
                            $token->getUser()
                        );

                        self::assertMatchesRegularExpression(
                            '/^[a-f0-9]{64}$/',
                            $token->getToken()
                        );

                        self::assertGreaterThanOrEqual(
                            $before,
                            $token->getExpiresAt()
                        );

                        self::assertLessThanOrEqual(
                            new \DateTimeImmutable('+1 hour'),
                            $token->getExpiresAt()
                        );
                    }
                );

            $router = $this->createMock(
                UrlGeneratorInterface::class
            );

            $router
                ->expects(self::once())
                ->method('generate')
                ->with(
                    'app_reset_password',
                    self::callback(
                        static function (array $params) use (&$saved): bool {
                            self::assertInstanceOf(
                                PasswordResetToken::class,
                                $saved
                            );

                            return $params === [
                                'token' => $saved->getToken(),
                            ];
                        }
                    ),
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
                ->willReturn(
                    'https://radio.example/reset'
                );

            $this->container->set(
                'router',
                $router
            );
        } else {
            $em
                ->expects(self::never())
                ->method('createQuery');

            $em
                ->expects(self::never())
                ->method('persist');
        }

        $em
            ->expects($sends ? self::once() : self::never())
            ->method('flush');

        $mailer
            ->expects($sends ? self::once() : self::never())
            ->method('send')
            ->with(
                self::callback(
                    function (Email $mail) use ($user): bool {
                        self::assertSame(
                            'noreply@radiozinzine.com',
                            $mail->getFrom()[0]->getAddress()
                        );

                        self::assertSame(
                            'listener@example.com',
                            $mail->getTo()[0]->getAddress()
                        );

                        self::assertSame(
                            'Réinitialisation de votre mot de passe',
                            $mail->getSubject()
                        );

                        self::assertSame(
                            'security/reset_password_email.html.twig',
                            $this->template
                        );

                        self::assertSame(
                            'https://radio.example/reset',
                            $this->parameters['resetUrl']
                        );

                        self::assertSame(
                            $user,
                            $this->parameters['user']
                        );

                        return true;
                    }
                )
            );

        $response = $controller->request(
            Request::create('/'),
            $users,
            $em,
            $mailer
        );

        self::assertSame(
            200,
            $response->getStatusCode()
        );

        self::assertSame(
            'security/forgot_password_request.html.twig',
            $this->template
        );

        self::assertSame(
            $submitted && $valid,
            $this->parameters['sent']
        );
    }


    public static function requests(): iterable
    {
        yield 'affichage' => [false, false, false];
        yield 'invalide' => [true, false, true];
        yield 'email inconnu' => [true, true, false];
        yield 'email connu' => [true, true, true];
    }

    /** @dataProvider invalidTokens */
    public function testInvalidOrExpiredTokenCannotChangePassword(bool $expired): void
    {
        $controller = new ResetPasswordController();
        $this->wire($controller);
        $token = $expired ? new PasswordResetToken(new User(), 'token', new \DateTimeImmutable('-1 minute')) : null;
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('findOneBy')->with(['token' => 'token'])->willReturn($token);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(PasswordResetToken::class)->willReturn($repo);
        $em->expects($expired ? self::once() : self::never())->method('remove')->with($token);
        $em->expects($expired ? self::once() : self::never())->method('flush');
        $em->expects(self::never())->method('persist');
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');
        self::assertTrue($controller->reset('token', Request::create('/'), $em, $hasher)->isRedirect());
        self::assertSame(['app_forgot_password_request', []], $this->redirect);
        self::assertSame(['Lien invalide ou expiré.'], $this->session->getFlashBag()->get('error'));
    }

    public static function invalidTokens(): iterable
    {
        yield 'inconnu' => [false];
        yield 'expiré' => [true];
    }

    /** @dataProvider resets */
    public function testResetOnlyConsumesTokenAfterValidMatchingPasswords(
        bool $submitted,
        bool $valid,
        string $confirmation
    ): void {
        $controller = new ResetPasswordController();
        $this->wire($controller);

        $this->unmappedForm(
            ResetPasswordType::class,
            $submitted,
            $valid,
            [
                'password' => 'NewPassword123!',
                'passwordConfirm' => $confirmation,
            ]
        );

        $user = (new User())
            ->setPassword('old-hash');

        $token = new PasswordResetToken(
            $user,
            'token',
            new \DateTimeImmutable('+1 hour')
        );

        $repo = $this->createMock(
            EntityRepository::class
        );

        $repo
            ->method('findOneBy')
            ->with(['token' => 'token'])
            ->willReturn($token);

        $em = $this->createMock(
            EntityManagerInterface::class
        );

        $em
            ->method('getRepository')
            ->with(PasswordResetToken::class)
            ->willReturn($repo);

        $success =
            $submitted
            && $valid
            && $confirmation === 'NewPassword123!';

        /*
     * Le User provient de Doctrine via PasswordResetToken.
     * Il est donc déjà géré par l'EntityManager :
     * reset() ne doit plus appeler persist($user).
     */
        $em
            ->expects(self::never())
            ->method('persist');

        $em
            ->expects($success ? self::once() : self::never())
            ->method('remove')
            ->with($token);

        $em
            ->expects($success ? self::once() : self::never())
            ->method('flush');

        $hasher = $this->createMock(
            UserPasswordHasherInterface::class
        );

        $hasher
            ->expects($success ? self::once() : self::never())
            ->method('hashPassword')
            ->with(
                $user,
                'NewPassword123!'
            )
            ->willReturn('new-hash');

        $response = $controller->reset(
            'token',
            Request::create('/'),
            $em,
            $hasher
        );

        self::assertSame(
            $success ? 'new-hash' : 'old-hash',
            $user->getPassword()
        );

        if ($submitted && $valid) {
            self::assertTrue(
                $response->isRedirect()
            );

            self::assertSame(
                $success
                    ? ['app_login', []]
                    : [
                        'app_reset_password',
                        ['token' => 'token'],
                    ],
                $this->redirect
            );

            self::assertCount(
                1,
                $this->session
                    ->getFlashBag()
                    ->get(
                        $success
                            ? 'success'
                            : 'error'
                    )
            );

            return;
        }

        self::assertSame(
            200,
            $response->getStatusCode()
        );

        self::assertSame(
            'security/reset_password.html.twig',
            $this->template
        );

        self::assertSame(
            'token',
            $this->parameters['token']
        );
    }

    public static function resets(): iterable
    {
        yield 'affichage' => [false, false, ''];
        yield 'invalide' => [true, false, 'NewPassword123!'];
        yield 'confirmation différente' => [true, true, 'different'];
        yield 'succès' => [true, true, 'NewPassword123!'];
    }
}

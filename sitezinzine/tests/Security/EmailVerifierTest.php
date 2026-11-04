<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use SymfonyCasts\Bundle\VerifyEmail\Model\VerifyEmailSignatureComponents;
use SymfonyCasts\Bundle\VerifyEmail\Exception\ExpiredSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\InvalidSignatureException;
use SymfonyCasts\Bundle\VerifyEmail\Exception\WrongEmailVerifyException;

class EmailVerifierTest extends TestCase
{
    private function user(): User
    {
        $user = (new User())->setEmail('current@example.com')->setVerified(false);
        (new \ReflectionProperty($user, 'id'))->setValue($user, 42);
        return $user;
    }

/** @dataProvider addresses */
public function testSendSignsExpectedAddressAndPreservesTemplateContext(
    ?string $override,
    string $expected
): void {
    $user = $this->user();

    $helper = $this->createMock(
        RequestAwareVerifyEmailHelperInterface::class
    );

    $created = new \DateTimeImmutable(
        '2026-09-01 12:00:00 UTC'
    );

    $signature = new VerifyEmailSignatureComponents(
        $created->modify('+1 hour'),
        'https://radio.example/verify?signed=abc',
        $created->getTimestamp()
    );

    $helper
        ->expects(self::once())
        ->method('generateSignature')
        ->with(
            'verify_route',
            '42',
            $expected
        )
        ->willReturn($signature);

    $email = (new TemplatedEmail())
        ->to($expected)
        ->subject('Confirmation')
        ->htmlTemplate(
            'registration/confirmation_email.html.twig'
        )
        ->context([
            'username' => 'Alice',
            'signedUrl' => 'obsolete',
        ]);

    $mailer = $this->createMock(
        MailerInterface::class
    );

    $mailer
        ->expects(self::once())
        ->method('send')
        ->with(
            self::callback(
                static function (
                    TemplatedEmail $sent
                ) use ($email, $expected): bool {
                    self::assertSame($email, $sent);

                    self::assertSame(
                        $expected,
                        $sent->getTo()[0]->getAddress()
                    );

                    self::assertCount(
                        1,
                        $sent->getFrom()
                    );

                    self::assertSame(
                        'noreply@radiozinzine.com',
                        $sent->getFrom()[0]->getAddress()
                    );

                    self::assertSame(
                        'Radio Zinzine',
                        $sent->getFrom()[0]->getName()
                    );

                    self::assertSame(
                        'Confirmation',
                        $sent->getSubject()
                    );

                    self::assertSame(
                        'registration/confirmation_email.html.twig',
                        $sent->getHtmlTemplate()
                    );

                    self::assertSame(
                        'Alice',
                        $sent->getContext()['username']
                    );

                    self::assertSame(
                        'https://radio.example/verify?signed=abc',
                        $sent->getContext()['signedUrl']
                    );

                    self::assertSame(
                        '%count% hour|%count% hours',
                        $sent->getContext()['expiresAtMessageKey']
                    );

                    self::assertSame(
                        ['%count%' => 1],
                        $sent->getContext()['expiresAtMessageData']
                    );

                    return true;
                }
            )
        );

    $em = $this->createMock(
        EntityManagerInterface::class
    );

    $em
        ->expects(self::never())
        ->method('persist');

    $em
        ->expects(self::never())
        ->method('flush');

    (new EmailVerifier(
        $helper,
        $mailer,
        $em
    ))->sendEmailConfirmation(
        'verify_route',
        $user,
        $email,
        $override
    );

    self::assertFalse(
        $user->isVerified()
    );

    self::assertSame(
        'current@example.com',
        $user->getEmail()
    );
}

    public static function addresses(): iterable
    {
        yield 'adresse actuelle par défaut' => [null, 'current@example.com'];
        yield 'nouvelle adresse explicite' => ['new@example.com', 'new@example.com'];
    }

    /** @dataProvider addresses */
    public function testValidLinkMarksUserAsVerifiedAndFlushes(
        ?string $override,
        string $expected
    ): void {
        $user = $this->user();

        $request = Request::create(
            'https://radio.example/verify?signature=abc'
        );

        $helper = $this->createMock(
            RequestAwareVerifyEmailHelperInterface::class
        );

        $validated = false;

        $helper
            ->expects(self::once())
            ->method('validateEmailConfirmationFromRequest')
            ->with(
                $request,
                '42',
                $expected
            )
            ->willReturnCallback(
                static function () use ($user, &$validated): void {
                    self::assertFalse($user->isVerified());
                    $validated = true;
                }
            );

        $entityManager = $this->createMock(
            EntityManagerInterface::class
        );

        $entityManager
            ->expects(self::never())
            ->method('persist');

        $entityManager
            ->expects(self::once())
            ->method('flush')
            ->willReturnCallback(
                static function () use ($user, &$validated): void {
                    self::assertTrue($validated);
                    self::assertTrue($user->isVerified());
                }
            );

        $mailer = $this->createMock(
            MailerInterface::class
        );

        $mailer
            ->expects(self::never())
            ->method('send');

        (new EmailVerifier(
            $helper,
            $mailer,
            $entityManager
        ))->handleEmailConfirmation(
            $request,
            $user,
            $override
        );

        self::assertTrue($user->isVerified());

        self::assertSame(
            'current@example.com',
            $user->getEmail()
        );
    }

    /** @dataProvider rejectedLinks */
    public function testRejectedLinkDoesNotVerifyOrPersistUser(\Exception $exception): void
    {
        $user = $this->user();
        $request = Request::create('https://radio.example/verify');
        $helper = $this->createMock(RequestAwareVerifyEmailHelperInterface::class);
        $helper->expects(self::once())->method('validateEmailConfirmationFromRequest')->with($request, '42', 'new@example.com')->willThrowException($exception);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        try {
            (new EmailVerifier($helper, $mailer, $em))->handleEmailConfirmation($request, $user, 'new@example.com');
            self::fail('Le lien refusé doit propager son exception.');
        } catch (\Exception $caught) {
            self::assertSame($exception, $caught);
        }
        self::assertFalse($user->isVerified());
        self::assertSame('current@example.com', $user->getEmail());
    }

    public static function rejectedLinks(): iterable
    {
        yield 'signature invalide' => [new InvalidSignatureException()];
        yield 'lien expiré' => [new ExpiredSignatureException()];
        yield 'adresse différente' => [new WrongEmailVerifyException()];
    }

    public function testMailTransportFailureIsNotSilentlyIgnored(): void
    {
        $user = $this->user();
        $helper = $this->createMock(RequestAwareVerifyEmailHelperInterface::class);
        $now = new \DateTimeImmutable();
        $helper->method('generateSignature')->willReturn(new VerifyEmailSignatureComponents($now->modify('+1 hour'), 'https://radio.example/verify', $now->getTimestamp()));
        $exception = new TransportException('Mail unavailable');
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException($exception);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $this->expectExceptionObject($exception);
        (new EmailVerifier($helper, $mailer, $em))->sendEmailConfirmation('verify_route', $user, (new TemplatedEmail())->to('current@example.com'));
    }
}

/** La version installée déclare cette méthode uniquement dans son PHPDoc. */
interface RequestAwareVerifyEmailHelperInterface extends VerifyEmailHelperInterface
{
    public function validateEmailConfirmationFromRequest(Request $request, string $userId, string $userEmail): void;
}

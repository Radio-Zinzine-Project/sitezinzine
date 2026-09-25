<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\EmailVerifier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;


class RegistrationControllerTest extends WebTestCase
{
    public function testRegisterPageIsAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="registration_form"]');
    }

    public function testSuccessfulRegistrationFlow(): void
    {
        $client = static::createClient();

        $crawler = $client->request(
            'GET',
            '/register'
        );

        $username = 'testuser_' . uniqid();
        $email = 'test_' . uniqid() . '@example.com';

        $form = $crawler
            ->filter('form[name="registration_form"]')
            ->form([
                'registration_form[username]' => $username,
                'registration_form[email]' => $email,
                'registration_form[plainPassword]' => 'TestPassword123!',
                'registration_form[agreeTerms]' => true,
            ]);

        $client->submit($form);

        $this->assertResponseRedirects('/login');

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $user = $entityManager
            ->getRepository(User::class)
            ->findOneBy([
                'username' => $username,
            ]);

        $this->assertInstanceOf(
            User::class,
            $user
        );

        $this->assertSame(
            $email,
            $user->getEmail()
        );

        $this->assertFalse(
            $user->isVerified()
        );

        $this->assertFalse(
            $user->isApproved()
        );

        $this->assertSame(
            ['ROLE_USER'],
            array_values($user->getRoles())
        );

        $this->assertNotSame(
            'TestPassword123!',
            $user->getPassword()
        );
    }

    public function testEmailVerificationWithoutUserIdReturnsNotFound(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/verify/email'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmailVerificationWithUnknownUserReturnsNotFound(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/verify/email?id=999999999'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmailVerificationFailsGracefully(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $user = new User();

        $user
            ->setUsername('verifyuser_' . uniqid())
            ->setEmail('verify_' . uniqid() . '@example.com')
            ->setPassword('fakehashedpassword')
            ->setVerified(false)
            ->setApproved(false);

        $entityManager->persist($user);
        $entityManager->flush();

        $client->request(
            'GET',
            '/verify/email?id=' . $user->getId() . '&badToken=true'
        );

        $this->assertResponseRedirects('/login');

        $entityManager->refresh($user);

        /*
     * Une signature invalide ne doit surtout pas valider
     * l'adresse e-mail ni approuver le compte.
     */
        $this->assertFalse($user->isVerified());
        $this->assertFalse($user->isApproved());
    }

    public function testEmailVerificationSucceeds(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $user = new User();

        $user
            ->setUsername('verifieduser_' . uniqid())
            ->setEmail('verified_' . uniqid() . '@example.com')
            ->setPassword('fakehashedpassword')
            ->setVerified(false)
            ->setApproved(false);

        $entityManager->persist($user);
        $entityManager->flush();

        $emailVerifier = $this->createMock(
            \App\Security\EmailVerifier::class
        );

        $emailVerifier
            ->expects($this->once())
            ->method('handleEmailConfirmation')
            ->with(
                $this->isInstanceOf(
                    \Symfony\Component\HttpFoundation\Request::class
                ),
                $this->callback(
                    static fn(User $verifiedUser): bool =>
                    $verifiedUser->getId() === $user->getId()
                )
            )
            ->willReturnCallback(
                static function (
                    \Symfony\Component\HttpFoundation\Request $request,
                    User $verifiedUser
                ): void {
                    /*
                 * Le fonctionnement interne de EmailVerifier est testé
                 * séparément dans EmailVerifierTest.
                 *
                 * Ici on reproduit uniquement son effet lorsque
                 * la confirmation réussit.
                 */
                    $verifiedUser->setVerified(true);
                }
            );

        static::getContainer()->set(
            \App\Security\EmailVerifier::class,
            $emailVerifier
        );

        $client->request(
            'GET',
            '/verify/email?id=' . $user->getId()
        );

        $this->assertResponseRedirects('/login');

        /*
     * La confirmation de l'adresse e-mail est réussie.
     */
        $this->assertTrue($user->isVerified());

        /*
     * Mais la confirmation de l'adresse e-mail ne vaut pas
     * approbation du compte.
     *
     * Cette seconde étape reste à effectuer par Radio Zinzine.
     */
        $this->assertFalse($user->isApproved());
    }

    // TEST À AJOUTER : testResendVerificationPageIsAccessible()

    public function testResendVerificationPageIsAccessible(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/verify/email/resend'
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(
            'form[action="/verify/email/resend"]'
        );
        $this->assertSelectorExists(
            'input[name="username"]'
        );
        $this->assertSelectorExists(
            'input[name="_token"]'
        );
    }

    public function testLoginPageContainsResendVerificationLink(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/login'
        );

        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists(
            'a[href="/verify/email/resend"]'
        );
    }

    public function testUnverifiedUserCanRequestNewVerificationEmail(): void
    {
        $client = static::createClient();

        /*
     * Ce scénario nécessite deux requêtes successives :
     * GET du formulaire puis POST.
     *
     * On conserve le même kernel afin que le service EmailVerifier
     * remplacé dans le container de test reste le même pendant
     * l'ensemble du scénario.
     */
        $client->disableReboot();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $username = 'resend_' . uniqid();

        $user = (new User())
            ->setUsername($username)
            ->setEmail('resend_' . uniqid() . '@example.com')
            ->setPassword('fakehashedpassword')
            ->setVerified(false)
            ->setApproved(false);

        $entityManager->persist($user);
        $entityManager->flush();

        $emailVerifier = $this->createMock(
            EmailVerifier::class
        );

        $emailVerifier
            ->expects($this->once())
            ->method('sendEmailConfirmation')
            ->with(
                'app_verify_email',
                $this->callback(
                    static fn(User $sentUser): bool =>
                    $sentUser->getId() === $user->getId()
                ),
                $this->callback(
                    static function ($email) use ($user): bool {
                        self::assertSame(
                            $user->getEmail(),
                            $email->getTo()[0]->getAddress()
                        );

                        self::assertSame(
                            'Confirmez votre e-mail, s’il vous plaît.',
                            $email->getSubject()
                        );

                        self::assertSame(
                            'registration/confirmation_email.html.twig',
                            $email->getHtmlTemplate()
                        );

                        return true;
                    }
                )
            );

        static::getContainer()->set(
            EmailVerifier::class,
            $emailVerifier
        );

        $crawler = $client->request(
            'GET',
            '/verify/email/resend'
        );

        $form = $crawler
            ->filter('form[action="/verify/email/resend"]')
            ->form([
                'username' => $username,
            ]);

        $client->submit($form);

        $this->assertResponseRedirects('/login');

        $client->followRedirect();

        $this->assertSelectorTextContains(
            '.alert-success',
            'Si ce compte existe et que son adresse e-mail n’est pas encore confirmée, un nouveau lien de confirmation vient d’être envoyé.'
        );
    }

    public function testVerifiedUserDoesNotReceiveAnotherVerificationEmail(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $username = 'already_verified_' . uniqid();

        $user = (new User())
            ->setUsername($username)
            ->setEmail('verified_' . uniqid() . '@example.com')
            ->setPassword('fakehashedpassword')
            ->setVerified(true)
            ->setApproved(false);

        $entityManager->persist($user);
        $entityManager->flush();

        $emailVerifier = $this->createMock(
            EmailVerifier::class
        );

        $emailVerifier
            ->expects($this->never())
            ->method('sendEmailConfirmation');

        static::getContainer()->set(
            EmailVerifier::class,
            $emailVerifier
        );

        $crawler = $client->request(
            'GET',
            '/verify/email/resend'
        );

        $form = $crawler
            ->filter('form[action="/verify/email/resend"]')
            ->form([
                'username' => $username,
            ]);

        $client->submit($form);

        $this->assertResponseRedirects('/login');

        $client->followRedirect();

        $this->assertSelectorTextContains(
            '.alert-success',
            'Si ce compte existe et que son adresse e-mail n’est pas encore confirmée, un nouveau lien de confirmation vient d’être envoyé.'
        );
    }

    public function testUnknownUserGetsSameNeutralResponseWithoutSendingEmail(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $username = 'unknown_' . uniqid();

        $emailVerifier = $this->createMock(
            EmailVerifier::class
        );

        $emailVerifier
            ->expects($this->never())
            ->method('sendEmailConfirmation');

        static::getContainer()->set(
            EmailVerifier::class,
            $emailVerifier
        );

        $crawler = $client->request(
            'GET',
            '/verify/email/resend'
        );

        $form = $crawler
            ->filter('form[action="/verify/email/resend"]')
            ->form([
                'username' => $username,
            ]);

        $client->submit($form);

        $this->assertResponseRedirects('/login');

        $client->followRedirect();

        $this->assertSelectorTextContains(
            '.alert-success',
            'Si ce compte existe et que son adresse e-mail n’est pas encore confirmée, un nouveau lien de confirmation vient d’être envoyé.'
        );
    }

    public function testInvalidCsrfTokenDoesNotSendVerificationEmail(): void
    {
        $client = static::createClient();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $username = 'csrf_resend_' . uniqid();

        $user = (new User())
            ->setUsername($username)
            ->setEmail('csrf_' . uniqid() . '@example.com')
            ->setPassword('fakehashedpassword')
            ->setVerified(false)
            ->setApproved(false);

        $entityManager->persist($user);
        $entityManager->flush();

        $emailVerifier = $this->createMock(
            EmailVerifier::class
        );

        $emailVerifier
            ->expects($this->never())
            ->method('sendEmailConfirmation');

        static::getContainer()->set(
            EmailVerifier::class,
            $emailVerifier
        );

        $client->request(
            'POST',
            '/verify/email/resend',
            [
                'username' => $username,
                '_token' => 'invalid-token',
            ]
        );

        $this->assertResponseRedirects(
            '/verify/email/resend'
        );

        $client->followRedirect();

        $this->assertSelectorTextContains(
            '.alert-error',
            'Jeton CSRF invalide. Veuillez réessayer.'
        );
    }

    public function testResendVerificationEmailIsRateLimited(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $username = 'rate_limited_' . uniqid();

        $user = (new User())
            ->setUsername($username)
            ->setEmail('rate_limited_' . uniqid() . '@example.com')
            ->setPassword('fakehashedpassword')
            ->setVerified(false)
            ->setApproved(false);

        $entityManager->persist($user);
        $entityManager->flush();

        $emailVerifier = $this->createMock(
            EmailVerifier::class
        );

        /*
     * La configuration autorise trois demandes.
     *
     * La quatrième doit être bloquée par le Rate Limiter
     * avant tout nouvel envoi d'e-mail.
     */
        $emailVerifier
            ->expects($this->exactly(3))
            ->method('sendEmailConfirmation');

        static::getContainer()->set(
            EmailVerifier::class,
            $emailVerifier
        );

        for ($requestNumber = 1; $requestNumber <= 4; ++$requestNumber) {
            $crawler = $client->request(
                'GET',
                '/verify/email/resend'
            );

            $form = $crawler
                ->filter('form[action="/verify/email/resend"]')
                ->form([
                    'username' => $username,
                ]);

            $client->submit($form);

            if ($requestNumber <= 3) {
                $this->assertResponseRedirects('/login');
            } else {
                $this->assertResponseRedirects(
                    '/verify/email/resend'
                );
            }
        }

        $client->followRedirect();

        $this->assertSelectorTextContains(
            '.alert-error',
            'Trop de demandes ont été effectuées. Veuillez patienter avant de réessayer.'
        );
    }
}

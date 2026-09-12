<?php

namespace App\Tests\Controller;

use App\Entity\User;
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
        $crawler = $client->request('GET', '/register');

        $form = $crawler->filter('form[name="registration_form"]')->form([
            'registration_form[username]' => 'testuser_' . uniqid(),
            'registration_form[email]' => 'test_' . uniqid() . '@example.com',
            'registration_form[plainPassword]' => 'TestPassword123!',
            'registration_form[agreeTerms]' => true, // Important : le champ est requis
        ]);

        $client->submit($form);
        $this->assertResponseRedirects();
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
            ->setPassword('fakehashedpassword');

        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);

        $client->request('GET', '/verify/email?badToken=true');

        $this->assertResponseRedirects('/login');

        $client->followRedirect();

        $this->assertSelectorExists('.alert');
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
            ->setPassword('fakehashedpassword');

        $entityManager->persist($user);
        $entityManager->flush();

        $emailVerifier = $this->createMock(\App\Security\EmailVerifier::class);

        $emailVerifier
            ->expects($this->once())
            ->method('handleEmailConfirmation');

        static::getContainer()->set(
            \App\Security\EmailVerifier::class,
            $emailVerifier
        );

        $client->loginUser($user);

        $client->request('GET', '/verify/email');

        $this->assertResponseRedirects('/admin/emission/');
    }
}

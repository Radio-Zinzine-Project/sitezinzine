<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTestCase;

final class AuthenticationTest extends FunctionalTestCase
{
    public function testVisitorCanOpenTheLoginForm(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/login"][method="post"] input[name="_username"]');
        self::assertSelectorExists('input[type="password"][name="_password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testCorrectCredentialsRedirectToAdminAndAuthenticateTheUser(): void
    {
        $user = $this->createUser();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $user->getUsername(),
            '_password' => 'Test-password-42!',
        ]));
        self::assertResponseRedirects('http://localhost/admin/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($user->getUserIdentifier(), static::getContainer()->get('security.token_storage')->getToken()?->getUserIdentifier());
    }

    public function testWrongPasswordShowsAnErrorAndLeavesAdminProtected(): void
    {
        $user = $this->createUser();
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $user->getUsername(),
            '_password' => 'incorrect-password',
        ]));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $message = static::getContainer()->get('translator')->trans('Invalid credentials.', [], 'security');
        self::assertSelectorTextContains('.bodylogin .alert', $message);
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
    }

    public function testAnonymousAdminAccessRedirectsToLogin(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
    }

    public function testLogoutRemovesAccessToAdmin(): void
    {
        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/admin/');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
    }

    public function testRoleUserCanAccessAdmin(): void
    {
        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/admin/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/emission/"]');
    }

    public function testUnverifiedAndUnapprovedUserCannotLogin(): void
    {
        $user = $this->createUser();

        $user
            ->setVerified(false)
            ->setApproved(false);

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/login');

        $this->client->submit(
            $crawler->selectButton('Se connecter')->form([
                '_username' => $user->getUsername(),
                '_password' => 'Test-password-42!',
            ])
        );

        self::assertResponseRedirects('/login');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains(
            '.bodylogin .alert',
            'Votre adresse e-mail n’a pas encore été confirmée.'
        );

        self::assertNull(
            static::getContainer()
                ->get('security.token_storage')
                ->getToken()?->getUser()
        );

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testUnverifiedButApprovedUserCannotLogin(): void
    {
        $user = $this->createUser();

        $user
            ->setVerified(false)
            ->setApproved(true);

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/login');

        $this->client->submit(
            $crawler->selectButton('Se connecter')->form([
                '_username' => $user->getUsername(),
                '_password' => 'Test-password-42!',
            ])
        );

        self::assertResponseRedirects('/login');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains(
            '.bodylogin .alert',
            'Votre adresse e-mail n’a pas encore été confirmée.'
        );

        self::assertNull(
            static::getContainer()
                ->get('security.token_storage')
                ->getToken()?->getUser()
        );

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testVerifiedButUnapprovedUserCannotLogin(): void
    {
        $user = $this->createUser();

        $user
            ->setVerified(true)
            ->setApproved(false);

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/login');

        $this->client->submit(
            $crawler->selectButton('Se connecter')->form([
                '_username' => $user->getUsername(),
                '_password' => 'Test-password-42!',
            ])
        );

        self::assertResponseRedirects('/login');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains(
            '.bodylogin .alert',
            'Votre compte est en attente de validation par Radio Zinzine.'
        );

        self::assertNull(
            static::getContainer()
                ->get('security.token_storage')
                ->getToken()?->getUser()
        );

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testDeletedUserCannotLogin(): void
    {
        $user = $this->createUser();

        $user->deactivate();
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/login');

        $this->client->submit(
            $crawler->selectButton('Se connecter')->form([
                '_username' => $user->getUsername(),
                '_password' => 'Test-password-42!',
            ])
        );

        self::assertResponseRedirects('/login');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains(
            '.bodylogin .alert',
            'Votre compte a été désactivé.'
        );

        self::assertNull(
            static::getContainer()
                ->get('security.token_storage')
                ->getToken()?->getUser()
        );

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }
}

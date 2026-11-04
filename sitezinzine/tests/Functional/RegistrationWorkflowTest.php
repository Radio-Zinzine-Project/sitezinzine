<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class RegistrationWorkflowTest extends FunctionalTestCase
{
public function testRegistrationCreatesPendingAccountWithoutLoggingUserIn(): void
{
    $username = 'registration' . bin2hex(random_bytes(6));
    $email = $username . '@example.test';

    $crawler = $this->client->request(
        'GET',
        '/register'
    );

    self::assertResponseIsSuccessful();

    $form = $crawler
        ->selectButton('S\'inscrire')
        ->form();

    $values = $form->getPhpValues();

    $values['registration_form']['username'] = $username;
    $values['registration_form']['email'] = $email;
    $values['registration_form']['plainPassword'] = 'Test-password-42!';
    $values['registration_form']['agreeTerms'] = '1';

    $this->client->request(
        'POST',
        '/register',
        $values
    );

    /*
     * Après l'inscription, l'utilisateur est renvoyé
     * vers la connexion et n'est pas connecté
     * automatiquement.
     */
    self::assertResponseRedirects('/login');

    $this->entityManager->clear();

    $user = $this->entityManager
        ->getRepository(User::class)
        ->findOneBy([
            'username' => $username,
        ]);

    self::assertNotNull($user);

    /*
     * Une nouvelle inscription doit obligatoirement
     * commencer dans ces deux états.
     */
    self::assertFalse($user->isVerified());
    self::assertFalse($user->isApproved());

    /*
     * On suit la redirection vers /login.
     */
    $this->client->followRedirect();

    self::assertResponseIsSuccessful();

    /*
     * L'inscription ne doit pas avoir authentifié
     * automatiquement le nouveau compte.
     */
    self::assertNull(
        static::getContainer()
            ->get('security.token_storage')
            ->getToken()?->getUser()
    );
}

public function testUnverifiedUserCannotLogin(): void
{
    $password = 'Test-password-42!';

    $user = $this->createUser(
        'ROLE_USER',
        $password
    );

    $user
        ->setVerified(false)
        ->setApproved(false);

    $username = $user->getUsername();

    $this->entityManager->flush();

    $crawler = $this->client->request(
        'GET',
        '/login'
    );

    self::assertResponseIsSuccessful();

    $form = $crawler
        ->selectButton('Se connecter')
        ->form();

    $values = $form->getPhpValues();

    $values['_username'] = $username;
    $values['_password'] = $password;

    $this->client->request(
        'POST',
        '/login',
        $values
    );

    /*
     * L'authentification échoue et le firewall
     * renvoie vers la page de connexion.
     */
    self::assertResponseRedirects('/login');

    $crawler = $this->client->followRedirect();

    self::assertResponseIsSuccessful();

    /*
     * Le message provient directement du UserChecker.
     */
    self::assertStringContainsString(
        'Votre adresse e-mail n’a pas encore été confirmée.',
        $crawler->filter('body')->text()
    );

    /*
     * Aucun utilisateur ne doit être authentifié.
     */
    self::assertNull(
        static::getContainer()
            ->get('security.token_storage')
            ->getToken()?->getUser()
    );
}

public function testVerifiedButUnapprovedUserCannotLogin(): void
{
    $password = 'Test-password-42!';

    $user = $this->createUser(
        'ROLE_USER',
        $password
    );

    $user
        ->setVerified(true)
        ->setApproved(false);

    $username = $user->getUsername();

    $this->entityManager->flush();

    $crawler = $this->client->request(
        'GET',
        '/login'
    );

    self::assertResponseIsSuccessful();

    $form = $crawler
        ->selectButton('Se connecter')
        ->form();

    $values = $form->getPhpValues();

    $values['_username'] = $username;
    $values['_password'] = $password;

    $this->client->request(
        'POST',
        '/login',
        $values
    );

    /*
     * L'adresse e-mail est confirmée, mais Radio Zinzine
     * n'a pas encore validé le compte.
     */
    self::assertResponseRedirects('/login');

    $crawler = $this->client->followRedirect();

    self::assertResponseIsSuccessful();

    self::assertStringContainsString(
        'Votre compte est en attente de validation par Radio Zinzine.',
        $crawler->filter('body')->text()
    );

    /*
     * Le compte ne doit pas être authentifié.
     */
    self::assertNull(
        static::getContainer()
            ->get('security.token_storage')
            ->getToken()?->getUser()
    );
}

public function testVerifiedAndApprovedUserCanLogin(): void
{
    $password = 'Test-password-42!';

    $user = $this->createUser(
        'ROLE_USER',
        $password
    );

    $user
        ->setVerified(true)
        ->setApproved(true);

    $username = $user->getUsername();
    $id = $user->getId();

    $this->entityManager->flush();

    $crawler = $this->client->request(
        'GET',
        '/login'
    );

    self::assertResponseIsSuccessful();

    $form = $crawler
        ->selectButton('Se connecter')
        ->form();

    $values = $form->getPhpValues();

    $values['_username'] = $username;
    $values['_password'] = $password;

    $this->client->request(
        'POST',
        '/login',
        $values
    );

    /*
     * La connexion a réussi.
     * La route admin.index correspond à /admin/.
     */
    self::assertResponseRedirects('/admin/');

    $this->client->followRedirect();

    self::assertResponseIsSuccessful();

    /*
     * Le token de sécurité doit maintenant contenir
     * l'utilisateur qui vient de se connecter.
     */
    $authenticatedUser = static::getContainer()
        ->get('security.token_storage')
        ->getToken()?->getUser();

    self::assertInstanceOf(
        User::class,
        $authenticatedUser
    );

    self::assertSame(
        $id,
        $authenticatedUser->getId()
    );
}

public function testValidVerificationLinkVerifiesEmailButDoesNotApproveAccount(): void
{
    $user = $this->createUser();

    $user
        ->setVerified(false)
        ->setApproved(false);

    $this->entityManager->flush();

    $id = $user->getId();
    $email = $user->getEmail();

    self::assertNotNull($id);
    self::assertNotNull($email);

    /*
     * On génère la même signature que celle utilisée
     * par EmailVerifier lors de l'envoi du mail.
     */
    $verifyEmailHelper = static::getContainer()
        ->get(VerifyEmailHelperInterface::class);

    $signature = $verifyEmailHelper->generateSignature(
        'app_verify_email',
        (string) $id,
        $email,
        [
            'id' => $id,
        ]
    );

    /*
     * L'utilisateur n'est pas connecté lorsqu'il clique
     * sur le lien reçu par e-mail.
     */
    self::assertNull(
        static::getContainer()
            ->get('security.token_storage')
            ->getToken()?->getUser()
    );

    $this->client->request(
        'GET',
        $signature->getSignedUrl()
    );

    /*
     * Une confirmation réussie renvoie vers la connexion.
     */
    self::assertResponseRedirects('/login');

    $this->entityManager->clear();

    $reloadedUser = $this->entityManager->find(
        User::class,
        $id
    );

    self::assertNotNull($reloadedUser);

    /*
     * Le lien confirme uniquement la propriété
     * de l'adresse e-mail.
     */
    self::assertTrue(
        $reloadedUser->isVerified()
    );

    /*
     * La validation administrative reste indépendante.
     */
    self::assertFalse(
        $reloadedUser->isApproved()
    );

    /*
     * La confirmation de l'e-mail ne doit pas connecter
     * automatiquement le compte.
     */
    $this->client->followRedirect();

    self::assertResponseIsSuccessful();

    self::assertNull(
        static::getContainer()
            ->get('security.token_storage')
            ->getToken()?->getUser()
    );
}

public function testInvalidVerificationLinkDoesNotVerifyEmail(): void
{
    $user = $this->createUser();

    $user
        ->setVerified(false)
        ->setApproved(false);

    $this->entityManager->flush();

    $id = $user->getId();

    self::assertNotNull($id);

    /*
     * On appelle volontairement la route avec un id valide,
     * mais sans les paramètres constituant une signature
     * VerifyEmail valide.
     */
    $this->client->request(
        'GET',
        '/verify/email?id=' . $id
    );

    /*
     * RegistrationController intercepte l'exception
     * VerifyEmail et renvoie vers la connexion.
     */
    self::assertResponseRedirects('/login');

    $this->entityManager->clear();

    $reloadedUser = $this->entityManager->find(
        User::class,
        $id
    );

    self::assertNotNull($reloadedUser);

    /*
     * Une requête non signée ne doit surtout pas
     * confirmer l'adresse e-mail.
     */
    self::assertFalse(
        $reloadedUser->isVerified()
    );

    self::assertFalse(
        $reloadedUser->isApproved()
    );

    /*
     * La tentative ne doit pas non plus authentifier
     * l'utilisateur.
     */
    $this->client->followRedirect();

    self::assertResponseIsSuccessful();

    self::assertNull(
        static::getContainer()
            ->get('security.token_storage')
            ->getToken()?->getUser()
    );
}
}
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class ProfileWorkflowTest extends FunctionalTestCase
{
    public function testUserCanRequestAndConfirmNewEmailAddress(): void
    {
        $user = $this->createUser();

        $id = $user->getId();
        $currentEmail = $user->getEmail();
        $newEmail = 'new-email-' . bin2hex(random_bytes(6)) . '@example.test';

        self::assertNotNull($id);
        self::assertNotNull($currentEmail);
        self::assertTrue($user->isVerified());
        self::assertTrue($user->isApproved());
        self::assertNull($user->getPendingEmail());

        $this->client->loginUser($user);

        /*
         * On charge le véritable formulaire du profil.
         */
        $crawler = $this->client->request(
            'GET',
            '/admin/profil'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Enregistrer')
            ->form();

        $values = $form->getPhpValues();

        /*
         * On conserve le pseudo actuel et on demande
         * le changement d'adresse e-mail.
         */
        $values['profile']['pseudo'] = $user->getPseudo();
        $values['profile']['newEmail'] = $newEmail;

        $this->client->request(
            'POST',
            '/admin/profil',
            $values
        );

        self::assertResponseRedirects('/admin/profil');

        /*
         * On recharge réellement le User depuis la BDD.
         */
        $this->entityManager->clear();

        $pendingUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($pendingUser);

        /*
         * Tant que la nouvelle adresse n'est pas confirmée,
         * l'adresse actuelle doit rester inchangée et vérifiée.
         */
        self::assertSame(
            $currentEmail,
            $pendingUser->getEmail()
        );

        self::assertSame(
            $newEmail,
            $pendingUser->getPendingEmail()
        );

        self::assertTrue(
            $pendingUser->isVerified()
        );

        /*
         * La confirmation de la nouvelle adresse exige
         * un utilisateur pleinement authentifié.
         *
         * Après clear(), on recharge donc le User puis
         * on rétablit explicitement sa session.
         */
        $this->client->loginUser($pendingUser);

        /*
         * On génère la même signature que celle envoyée
         * par EmailVerifier pour pendingEmail.
         */
        $verifyEmailHelper = static::getContainer()
            ->get(VerifyEmailHelperInterface::class);

        $signature = $verifyEmailHelper->generateSignature(
            'app_profile_verify_new_email',
            (string) $id,
            $newEmail,
            [
                'id' => $id,
            ]
        );

        $this->client->request(
            'GET',
            $signature->getSignedUrl()
        );

        self::assertResponseRedirects('/admin/profil');

        /*
         * Nouvelle lecture depuis la BDD après confirmation.
         */
        $this->entityManager->clear();

        $confirmedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($confirmedUser);

        /*
         * pendingEmail est maintenant promu comme véritable
         * adresse du compte.
         */
        self::assertSame(
            $newEmail,
            $confirmedUser->getEmail()
        );

        self::assertNull(
            $confirmedUser->getPendingEmail()
        );

        self::assertTrue(
            $confirmedUser->isVerified()
        );

        /*
         * La confirmation d'une nouvelle adresse ne doit
         * pas modifier la validation administrative.
         */
        self::assertTrue(
            $confirmedUser->isApproved()
        );
    }

    public function testUserCanChangePasswordAndRemainAuthenticated(): void
    {


        $currentPassword = 'Test-password-42!';
        $newPassword = 'New-password-84!';

        $user = $this->createUser(
            'ROLE_USER',
            $currentPassword
        );

        $id = $user->getId();

        self::assertNotNull($id);

        $this->client->loginUser($user);

        /*
     * On utilise le véritable formulaire Symfony afin
     * de tester également le CSRF et RepeatedType.
     */
        $crawler = $this->client->request(
            'GET',
            '/admin/profil/password'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Modifier mon mot de passe')
            ->form();

        $values = $form->getPhpValues();

        $values['change_password']['currentPassword'] = $currentPassword;
        $values['change_password']['newPassword']['first'] = $newPassword;
        $values['change_password']['newPassword']['second'] = $newPassword;

        $this->client->request(
            'POST',
            '/admin/profil/password',
            $values
        );

        self::assertResponseRedirects('/admin/profil');

        /*
     * Le changement de mot de passe ne doit pas
     * déconnecter l'utilisateur.
     */
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();

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

        /*
     * On recharge ensuite le compte depuis la BDD
     * pour vérifier réellement le hash enregistré.
     */
        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);

        $passwordHasher = static::getContainer()
            ->get(UserPasswordHasherInterface::class);

        self::assertTrue(
            $passwordHasher->isPasswordValid(
                $reloadedUser,
                $newPassword
            )
        );

        self::assertFalse(
            $passwordHasher->isPasswordValid(
                $reloadedUser,
                $currentPassword
            )
        );
    }

    public function testWrongCurrentPasswordDoesNotChangePassword(): void
    {


        $currentPassword = 'Test-password-42!';
        $newPassword = 'New-password-84!';

        $user = $this->createUser(
            'ROLE_USER',
            $currentPassword
        );

        $id = $user->getId();

        self::assertNotNull($id);

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/profil/password'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Modifier mon mot de passe')
            ->form();

        $values = $form->getPhpValues();

        $values['change_password']['currentPassword'] = 'Wrong-password-42!';
        $values['change_password']['newPassword']['first'] = $newPassword;
        $values['change_password']['newPassword']['second'] = $newPassword;

        $crawler = $this->client->request(
            'POST',
            '/admin/profil/password',
            $values
        );

        self::assertResponseIsSuccessful();

        /*
     * Le contrôleur doit rester sur le formulaire et afficher
     * l'erreur lorsque le mot de passe actuel est incorrect.
     */
        self::assertStringContainsString(
            'Le mot de passe actuel est incorrect.',
            $crawler->filter('body')->text()
        );

        /*
     * On recharge le compte depuis la BDD afin de vérifier
     * qu'aucune modification du mot de passe n'a été enregistrée.
     */
        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);

        $passwordHasher = static::getContainer()
            ->get(UserPasswordHasherInterface::class);

        self::assertTrue(
            $passwordHasher->isPasswordValid(
                $reloadedUser,
                $currentPassword
            )
        );

        self::assertFalse(
            $passwordHasher->isPasswordValid(
                $reloadedUser,
                $newPassword
            )
        );
    }
}

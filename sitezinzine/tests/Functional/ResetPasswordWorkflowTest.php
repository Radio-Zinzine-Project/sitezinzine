<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResetPasswordWorkflowTest extends FunctionalTestCase
{
    public function testUserCanRequestAndUsePasswordResetLink(): void
    {


        $currentPassword = 'Current-password-42!';
        $newPassword = 'New-password-84!';

        $user = $this->createUser(
            'ROLE_USER',
            $currentPassword
        );

        $userId = $user->getId();
        $email = $user->getEmail();

        self::assertNotNull($userId);
        self::assertNotNull($email);

        /*
         * 1. Demande de réinitialisation en tant que visiteur.
         */
        $crawler = $this->client->request(
            'GET',
            '/reset-password'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter('form')
            ->form();

        $values = $form->getPhpValues();

        $values['forgot_password_request']['email'] = $email;

        $crawler = $this->client->request(
            'POST',
            '/reset-password',
            $values
        );

        self::assertResponseIsSuccessful();

        /*
         * 2. Un token doit maintenant exister en base
         * pour cet utilisateur.
         */
        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $userId
        );

        self::assertNotNull($reloadedUser);

        $resetToken = $this->entityManager
            ->getRepository(PasswordResetToken::class)
            ->findOneBy([
                'user' => $reloadedUser,
            ]);

        self::assertInstanceOf(
            PasswordResetToken::class,
            $resetToken
        );

        self::assertFalse(
            $resetToken->isExpired()
        );

        $token = $resetToken->getToken();

        self::assertNotSame('', $token);

        /*
         * 3. Le lien reçu permet d'accéder au formulaire
         * de définition du nouveau mot de passe.
         */
        $crawler = $this->client->request(
            'GET',
            '/reset-password/' . $token
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter('form')
            ->form();

        $values = $form->getPhpValues();

        $values['reset_password']['password'] = $newPassword;
        $values['reset_password']['passwordConfirm'] = $newPassword;

        $this->client->request(
            'POST',
            '/reset-password/' . $token,
            $values
        );

        /*
         * 4. Une réinitialisation réussie renvoie vers
         * la page de connexion.
         */
        self::assertResponseRedirects('/login');

        /*
         * 5. Le token doit avoir été consommé.
         */
        $this->entityManager->clear();

        $consumedToken = $this->entityManager
            ->getRepository(PasswordResetToken::class)
            ->findOneBy([
                'token' => $token,
            ]);

        self::assertNull($consumedToken);

        /*
         * 6. Le nouveau mot de passe doit être enregistré
         * et l'ancien ne doit plus être valide.
         */
        $reloadedUser = $this->entityManager->find(
            User::class,
            $userId
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

        /*
         * 7. Vérification fonctionnelle finale :
         * le nouveau mot de passe permet réellement
         * de se connecter.
         */
        $this->client->request(
            'GET',
            '/login'
        );

        $this->client->submitForm(
            'Se connecter',
            [
                '_username' => $reloadedUser->getUserIdentifier(),
                '_password' => $newPassword,
            ]
        );

        self::assertResponseRedirects('/admin/');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
    }
}
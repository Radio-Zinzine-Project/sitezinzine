<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @param string|null $emailToVerify
     * L'adresse e-mail qui doit être signée/validée.
     * Si null, on utilise l'adresse actuelle du User.
     */
    public function sendEmailConfirmation(
        string $verifyEmailRouteName,
        User $user,
        TemplatedEmail $email,
        ?string $emailToVerify = null
    ): void {
        $emailToVerify = $emailToVerify ?? $user->getEmail();

        if ($user->getId() === null) {
            throw new \LogicException(
                'Le compte doit être enregistré avant l’envoi de l’e-mail de confirmation.'
            );
        }

        if ($emailToVerify === null) {
            throw new \LogicException(
                'Aucune adresse e-mail à vérifier n’est définie.'
            );
        }

        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            (string) $user->getId(),
            $emailToVerify,
            [
                'id' => $user->getId(),
            ]
        );

        $context = $email->getContext();
        $context['signedUrl'] = $signatureComponents->getSignedUrl();
        $context['expiresAtMessageKey'] = $signatureComponents->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signatureComponents->getExpirationMessageData();

        $email
            ->from('mc.glasson@free.fr')
            ->context($context);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function handleEmailConfirmation(
        Request $request,
        User $user,
        ?string $emailToVerify = null
    ): void {
        $emailToVerify = $emailToVerify ?? $user->getEmail();

        if ($user->getId() === null) {
            throw new \LogicException(
                'Impossible de vérifier un compte qui n’est pas enregistré.'
            );
        }

        if ($emailToVerify === null) {
            throw new \LogicException(
                'Aucune adresse e-mail à vérifier n’est définie.'
            );
        }

        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $user->getId(),
            $emailToVerify
        );

        $user->setVerified(true);

        $this->entityManager->flush();
    }
}
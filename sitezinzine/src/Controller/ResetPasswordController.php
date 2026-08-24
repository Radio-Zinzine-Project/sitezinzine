<?php

namespace App\Controller;

use App\Entity\PasswordResetToken;
use App\Form\ForgotPasswordRequestType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ResetPasswordController extends AbstractController
{
    #[Route('/reset-password', name: 'app_forgot_password_request')]
    public function request(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        $form = $this->createForm(ForgotPasswordRequestType::class);
        $form->handleRequest($request);

        $sent = false;

        if ($form->isSubmitted() && $form->isValid()) {
            $emailValue = (string) $form->get('email')->getData();

            $user = $users->findOneBy(['email' => $emailValue]);

            if ($user) {
                $em->createQuery('DELETE FROM App\Entity\PasswordResetToken t WHERE t.user = :u')
                    ->setParameter('u', $user)
                    ->execute();

                $token = bin2hex(random_bytes(32));
                $expiresAt = new \DateTimeImmutable('+1 hour');

                $reset = new PasswordResetToken($user, $token, $expiresAt);
                $em->persist($reset);
                $em->flush();

                // ✅ URL absolue générée par Symfony (PAS de concat à la main)
                $absoluteResetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $mail = (new Email())
                    ->from('no-reply@radiozinzine.com')
                    ->to($user->getEmail())
                    ->subject('Réinitialisation de votre mot de passe')
                    ->html($this->renderView('security/reset_password_email.html.twig', [
                        'resetUrl' => $absoluteResetUrl,
                        'expiresAt' => $expiresAt,
                        'user' => $user,
                    ]));

                $mailer->send($mail);
            }

            $sent = true;
        }

        return $this->render('security/forgot_password_request.html.twig', [
            'requestForm' => $form->createView(),
            'sent' => $sent,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function reset(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $repo = $em->getRepository(PasswordResetToken::class);
        /** @var PasswordResetToken|null $reset */
        $reset = $repo->findOneBy(['token' => $token]);

        if (!$reset || $reset->isExpired()) {
            if ($reset) {
                $em->remove($reset);
                $em->flush();
            }
            $this->addFlash('error', 'Lien invalide ou expiré.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = (string) $form->get('password')->getData();
            $passwordConfirm = (string) $form->get('passwordConfirm')->getData();

            if ($password !== $passwordConfirm) {
                $this->addFlash('error', 'Les mots de passe ne correspondent pas.');
                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            $user = $reset->getUser();
            $user->setPassword($hasher->hashPassword($user, $password));

            $em->persist($user);
            $em->remove($reset);
            $em->flush();

            $this->addFlash('success', 'Mot de passe modifié. Vous pouvez vous connecter.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'resetForm' => $form->createView(),
            'token' => $token,
        ]);
    }
}

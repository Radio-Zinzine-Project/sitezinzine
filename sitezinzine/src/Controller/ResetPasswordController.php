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
    /**
     * Demande de réinitialisation du mot de passe.
     *
     * La réponse reste volontairement neutre :
     * on affiche le même résultat qu'un compte corresponde ou non
     * à l'adresse saisie afin de ne pas révéler les comptes existants.
     */
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

            $user = $users->findOneBy([
                'email' => $emailValue,
            ]);

            if ($user) {
                /*
                 * Un utilisateur ne conserve qu'un seul token
                 * de réinitialisation actif.
                 *
                 * Une nouvelle demande invalide donc les anciens tokens.
                 */
                $em->createQuery(
                    'DELETE FROM App\Entity\PasswordResetToken t WHERE t.user = :u'
                )
                    ->setParameter('u', $user)
                    ->execute();

                /*
                 * Token aléatoire cryptographiquement sûr.
                 */
                $token = bin2hex(random_bytes(32));

                /*
                 * Le lien de réinitialisation reste valable pendant 1 heure.
                 *
                 * Cette durée est indépendante de VerifyEmailBundle :
                 * les liens de confirmation d'adresse e-mail sont désormais
                 * configurés séparément avec une durée de 24 heures.
                 */
                $expiresAt = new \DateTimeImmutable('+1 hour');

                $reset = new PasswordResetToken(
                    $user,
                    $token,
                    $expiresAt
                );

                $em->persist($reset);
                $em->flush();

                /*
                 * Génération d'une URL absolue afin que le lien contenu
                 * dans l'e-mail fonctionne indépendamment du client mail.
                 */
                $absoluteResetUrl = $this->generateUrl(
                    'app_reset_password',
                    [
                        'token' => $token,
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                /*
                 * L'adresse d'expédition doit correspondre à la boîte SMTP
                 * réellement utilisée par Radio Zinzine.
                 */
                $mail = (new Email())
                    ->from('noreply@radiozinzine.com')
                    ->to($user->getEmail())
                    ->subject('Réinitialisation de votre mot de passe')
                    ->html(
                        $this->renderView(
                            'security/reset_password_email.html.twig',
                            [
                                'resetUrl' => $absoluteResetUrl,
                                'expiresAt' => $expiresAt,
                                'user' => $user,
                            ]
                        )
                    );

                $mailer->send($mail);
            }

            /*
             * Toujours true après une demande valide,
             * même lorsqu'aucun utilisateur n'a été trouvé.
             *
             * Cela évite l'énumération des comptes.
             */
            $sent = true;
        }

        return $this->render(
            'security/forgot_password_request.html.twig',
            [
                'requestForm' => $form->createView(),
                'sent' => $sent,
            ]
        );
    }

    /**
     * Utilisation d'un token de réinitialisation.
     *
     * Le token doit :
     * - exister ;
     * - ne pas être expiré.
     *
     * Après utilisation, il est supprimé et ne peut donc pas
     * servir une seconde fois.
     */
    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function reset(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $repo = $em->getRepository(PasswordResetToken::class);

        /** @var PasswordResetToken|null $reset */
        $reset = $repo->findOneBy([
            'token' => $token,
        ]);

        /*
         * Token absent ou expiré.
         *
         * Lorsqu'un token expiré existe encore en base,
         * on le supprime immédiatement.
         */
        if (!$reset || $reset->isExpired()) {
            if ($reset) {
                $em->remove($reset);
                $em->flush();
            }

            $this->addFlash(
                'error',
                'Lien invalide ou expiré.'
            );

            return $this->redirectToRoute(
                'app_forgot_password_request'
            );
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = (string) $form
                ->get('password')
                ->getData();

            $passwordConfirm = (string) $form
                ->get('passwordConfirm')
                ->getData();

            /*
             * Sécurité supplémentaire :
             * même si le formulaire effectue déjà sa validation,
             * on refuse explicitement deux mots de passe différents.
             */
            if ($password !== $passwordConfirm) {
                $this->addFlash(
                    'error',
                    'Les mots de passe ne correspondent pas.'
                );

                return $this->redirectToRoute(
                    'app_reset_password',
                    [
                        'token' => $token,
                    ]
                );
            }

            $user = $reset->getUser();

            /*
             * Le mot de passe n'est jamais stocké en clair.
             */
            $user->setPassword(
                $hasher->hashPassword(
                    $user,
                    $password
                )
            );

            /*
             * Le User provient de Doctrine via PasswordResetToken :
             * il est déjà géré par l'EntityManager.
             *
             * Aucun persist($user) supplémentaire n'est nécessaire.
             */
            $em->remove($reset);
            $em->flush();

            /*
             * Après utilisation, le token n'existe plus :
             * le lien ne peut donc pas être réutilisé.
             */
            $this->addFlash(
                'success',
                'Mot de passe modifié. Vous pouvez vous connecter.'
            );

            return $this->redirectToRoute(
                'app_login'
            );
        }

        return $this->render(
            'security/reset_password.html.twig',
            [
                'resetForm' => $form->createView(),
                'token' => $token,
            ]
        );
    }
}
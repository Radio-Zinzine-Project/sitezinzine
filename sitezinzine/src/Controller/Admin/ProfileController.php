<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\ProfileType;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EmailVerifier $emailVerifier
    ) {}

    #[Route('/admin/profil', name: 'app_profile_edit')]
    public function edit(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(
            ProfileType::class,
            $user,
            [
                'attr' => [
                    'data-turbo' => 'false',
                ],
            ]
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = trim(
                (string) $form
                    ->get('newEmail')
                    ->getData()
            );

            if (
                $newEmail !== ''
                && $newEmail !== $user->getEmail()
                && $newEmail !== $user->getPendingEmail()
            ) {
                $user->setPendingEmail($newEmail);
                $user->setVerified(false);

                $em->flush();

                $this->emailVerifier->sendEmailConfirmation(
                    'app_profile_verify_new_email',
                    $user,
                    (new TemplatedEmail())
                        ->from(
                            new Address(
                                'drelin04@hotmail.fr',
                                'Support'
                            )
                        )
                        ->to($newEmail)
                        ->subject(
                            'Confirmez votre nouvelle adresse email'
                        )
                        ->htmlTemplate(
                            'profile/confirm_new_email.html.twig'
                        ),
                    $newEmail
                );

                $this->addFlash(
                    'success',
                    'Un email de confirmation a été envoyé à votre nouvelle adresse.'
                );

                return $this->redirectToRoute(
                    'app_profile_edit'
                );
            }

            $em->flush();

            $this->addFlash(
                'success',
                'Profil mis à jour.'
            );

            return $this->redirectToRoute(
                'app_profile_edit'
            );
        }

        return $this->render(
            'admin/profile/edit.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    #[Route(
        '/profil/verify-email',
        name: 'app_profile_verify_new_email'
    )]
    public function verifyNewEmail(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted(
            'IS_AUTHENTICATED_FULLY'
        );

        /** @var User $user */
        $user = $this->getUser();

        $pendingEmail = $user->getPendingEmail();

        if ($pendingEmail === null || $pendingEmail === '') {
            throw $this->createNotFoundException(
                'Aucune nouvelle adresse email en attente de confirmation.'
            );
        }

        $this->emailVerifier->handleEmailConfirmation(
            $request,
            $user,
            $pendingEmail
        );

        $user->setEmail($pendingEmail);
        $user->setPendingEmail(null);
        $user->setVerified(true);

        $em->flush();

        $this->addFlash(
            'success',
            'Votre nouvelle adresse email a été confirmée.'
        );

        return $this->redirectToRoute(
            'app_profile_edit'
        );
    }
}
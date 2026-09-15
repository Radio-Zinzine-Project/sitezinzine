<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private EmailVerifier $emailVerifier
    ) {
    }

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        $user = new User();

        $form = $this->createForm(
            RegistrationFormType::class,
            $user
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $user->setVerified(false);
            $user->setApproved(false);

            $entityManager->persist($user);
            $entityManager->flush();

            $this->emailVerifier->sendEmailConfirmation(
                'app_verify_email',
                $user,
                (new TemplatedEmail())
                    ->from(
                        new Address(
                            'drelin04@hotmail.fr',
                            'Support'
                        )
                    )
                    ->to($user->getEmail())
                    ->subject(
                        'Confirmez votre e-mail, s’il vous plaît.'
                    )
                    ->htmlTemplate(
                        'registration/confirmation_email.html.twig'
                    )
            );

            $this->addFlash(
                'success',
                'Votre compte a été créé. Consultez votre boîte mail pour confirmer votre adresse e-mail.'
            );

            return $this->redirectToRoute('app_login');
        }

        return $this->render(
            'registration/register.html.twig',
            [
                'registrationForm' => $form,
            ]
        );
    }

    #[Route(
        '/verify/email',
        name: 'app_verify_email',
        methods: ['GET']
    )]
    public function verifyUserEmail(
        Request $request,
        UserRepository $userRepository,
        TranslatorInterface $translator
    ): Response {
        $userId = $request->query->getInt('id');

        if ($userId <= 0) {
            throw $this->createNotFoundException(
                'Compte utilisateur introuvable.'
            );
        }

        $user = $userRepository->find($userId);

        if (!$user instanceof User) {
            throw $this->createNotFoundException(
                'Compte utilisateur introuvable.'
            );
        }

        try {
            $this->emailVerifier->handleEmailConfirmation(
                $request,
                $user
            );
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash(
                'verify_email_error',
                $translator->trans(
                    $exception->getReason(),
                    [],
                    'VerifyEmailBundle'
                )
            );

            return $this->redirectToRoute('app_login');
        }

        $this->addFlash(
            'success',
            'Votre adresse e-mail a bien été confirmée. Votre compte est maintenant en attente de validation par Radio Zinzine.'
        );

        return $this->redirectToRoute('app_login');
    }
}
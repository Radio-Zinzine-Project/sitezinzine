<?php

namespace App\Controller\Admin;

use App\Entity\Evenement;
use App\Form\EvenementType;
use App\Repository\EvenementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/evenement', name: 'admin.evenement.')]
#[IsGranted('ROLE_EDITOR')]
class EvenementController extends AbstractController
{
    #[Route(
        '/',
        name: 'index',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS]
    )]
    public function index(
        EvenementRepository $evenementRepository
    ): Response {
        $evenements = $evenementRepository->findAllDesc();

        return $this->render(
            '/admin/evenement/index.html.twig',
            [
                'evenements' => $evenements,
            ]
        );
    }

    #[Route('/create', name: 'create')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        Security $security
    ): Response {
        $evenement = new Evenement();
        $user = $security->getUser();

        $form = $this->createForm(EvenementType::class, $evenement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $evenement->setUpdateAt(new \DateTime());
            $evenement->setSoftDelete(false);
            $evenement->setValid(false);
            $evenement->setUser($user);

            $em->persist($evenement);
            $em->flush();

            $this->addFlash('success', 'L\'évènement a été créé !');

            return $this->redirectToRoute('admin.evenement.index');
        }

        return $this->render('admin/evenement/create.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}/edit',
        name: 'edit',
        methods: ['GET', 'POST'],
        requirements: ['id' => Requirement::DIGITS]
    )]
    public function edit(
        Evenement $evenement,
        Request $request,
        EntityManagerInterface $em,
        Security $security
    ): Response {
        $form = $this->createForm(EvenementType::class, $evenement, [
            'show_valid' => true,
        ]);

        $form->handleRequest($request);

        $user = $security->getUser();

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$evenement->getUser()) {
                $evenement->setUser($user);
            }

            $evenement->setUpdateAt(new \DateTime());

            $em->flush();

            $this->addFlash(
                'success',
                'L\'évènement a bien été modifié'
            );

            return $this->redirectToRoute(
                'admin.evenement.index'
            );
        }

        return $this->render(
            'admin/evenement/edit.html.twig',
            [
                'evenement' => $evenement,
                'form' => $form,
            ]
        );
    }

    #[Route(
        '/{id}',
        name: 'show',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS]
    )]
    public function show(
        Evenement $evenement
    ): Response {
        return $this->render(
            'admin/evenement/show.html.twig',
            [
                'evenement' => $evenement,
            ]
        );
    }

    #[Route(
        '/{id}',
        name: 'softDelete',
        methods: ['DELETE'],
        requirements: ['id' => Requirement::DIGITS]
    )]
    public function remove(
        Evenement $evenement,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid(
            'delete_evenement_' . $evenement->getId(),
            $request->request->get('_token')
        )) {
            throw $this->createAccessDeniedException(
                'Jeton CSRF invalide.'
            );
        }

        $evenement->setSoftDelete(true);

        $em->flush();

        $this->addFlash(
            'success',
            'L\'évènement a bien été supprimé'
        );

        return $this->redirectToRoute(
            'admin.evenement.index'
        );
    }

    #[Route(
        '/{id}/valid',
        name: 'valid',
        methods: ['POST'],
        requirements: ['id' => Requirement::DIGITS]
    )]
    public function valid(
        Evenement $evenement,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid(
            'valid_evenement_' . $evenement->getId(),
            $request->request->get('_token')
        )) {
            throw $this->createAccessDeniedException(
                'Jeton CSRF invalide.'
            );
        }

        $evenement->setValid(true);

        $em->flush();

        $this->addFlash(
            'success',
            'L\'évènement a bien été validé'
        );

        return $this->redirectToRoute(
            'admin.evenement.index'
        );
    }

    #[Route(
        '/{id}/unvalid',
        name: 'unvalid',
        methods: ['POST'],
        requirements: ['id' => Requirement::DIGITS]
    )]
    public function unvalid(
        Evenement $evenement,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isCsrfTokenValid(
            'unvalid_evenement_' . $evenement->getId(),
            $request->request->get('_token')
        )) {
            throw $this->createAccessDeniedException(
                'Jeton CSRF invalide.'
            );
        }

        $evenement->setValid(false);

        $em->flush();

        $this->addFlash(
            'success',
            'L\'évènement a bien été dé-validé'
        );

        return $this->redirectToRoute(
            'admin.evenement.index'
        );
    }
}
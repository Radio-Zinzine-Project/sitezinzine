<?php

namespace App\Controller\Admin;

use App\Entity\InviteOldAnimateur;
use App\Form\InviteOldAnimateurType;
use App\Repository\InviteOldAnimateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route("/admin/InviteOldAnimateur", name: 'admin.InviteOldAnimateur.')]
#[IsGranted("ROLE_USER")]
class InviteOldAnimateurController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        InviteOldAnimateurRepository $repo
    ): Response {
        $initiale = strtoupper(
            trim((string) $request->query->get('initiale', ''))
        );

        $type = trim(
            (string) $request->query->get('type', '')
        );

        $inviteOldAnimateurs = $repo->findFiltered(
            initiale: $initiale,
            type: $type
        );

        return $this->render('admin/InviteOldAnimateur/index.html.twig', [
            'InviteOldAnimateurs' => $inviteOldAnimateurs,
            'initiale' => $initiale,
            'type' => $type,
            'alphabet' => range('A', 'Z'),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(
        InviteOldAnimateur $inviteOldAnimateur,
        Request $request,
        InviteOldAnimateurRepository $inviteOldAnimateurRepository
    ): Response {
        $page = $request->query->getInt('page', 1);

        $initiale = strtoupper(
            trim((string) $request->query->get('initiale', ''))
        );

        $emissions = $inviteOldAnimateurRepository->paginateEmissions(
            inviteOldAnimateur: $inviteOldAnimateur,
            page: $page,
            initiale: $initiale
        );

        return $this->render('admin/InviteOldAnimateur/show.html.twig', [
            'InviteOldAnimateur' => $inviteOldAnimateur,
            'emissions' => $emissions,
            'initiale' => $initiale,
            'alphabet' => range('A', 'Z'),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    public function edit(
        InviteOldAnimateur $inviteOldAnimateur,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $form = $this->createForm(InviteOldAnimateurType::class, $inviteOldAnimateur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash('success', 'L\'invité·e a bien été modifié·e.');
            return $this->redirectToRoute('admin.InviteOldAnimateur.index');
        }

        return $this->render('admin/InviteOldAnimateur/edit.html.twig', [
            'InviteOldAnimateur' => $inviteOldAnimateur,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $inviteOldAnimateur = new InviteOldAnimateur();

        $form = $this->createForm(InviteOldAnimateurType::class, $inviteOldAnimateur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($inviteOldAnimateur);
            $em->flush();

            $this->addFlash('success', 'L\'invité·e a été créé·e !');
            return $this->redirectToRoute('admin.InviteOldAnimateur.index');
        }

        return $this->render('admin/InviteOldAnimateur/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => Requirement::DIGITS])]
    public function remove(InviteOldAnimateur $inviteOldAnimateur, EntityManagerInterface $em): Response
    {
        $em->remove($inviteOldAnimateur);
        $em->flush();

        $this->addFlash('success', 'L\'invité·e a bien été supprimé·e.');
        return $this->redirectToRoute('admin.InviteOldAnimateur.index');
    }
}

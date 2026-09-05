<?php

namespace App\Controller\Admin;

use App\Entity\Editeur;
use App\Repository\EditeurRepository;
use App\Form\EditeurType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route("/admin/editeur", name: 'admin.editeur.')]
#[IsGranted("ROLE_ADMIN")]
#[IsGranted("ROLE_SUPER_ADMIN")]
class EditeurController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(Request $request, EditeurRepository $editeurRepository): Response
    {
        $editeur = $editeurRepository->findAll();
        return $this->render('admin/editeur/index.html.twig', [
            'editeurs' => $editeur
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(
        Editeur $editeur,
        Request $request,
        EditeurRepository $editeurRepository
    ): Response {
        $page = $request->query->getInt('page', 1);

        $initiale = strtoupper(
            trim((string) $request->query->get('initiale', ''))
        );

        $emissions = $editeurRepository->paginateEmissions(
            editeur: $editeur,
            page: $page,
            initiale: $initiale
        );

        return $this->render('admin/editeur/show.html.twig', [
            'editeur' => $editeur,
            'emissions' => $emissions,
            'initiale' => $initiale,
            'alphabet' => range('A', 'Z'),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    public function edit(Editeur $editeur, Request $request, EntityManagerInterface $em)
    {
        $form = $this->createForm(EditeurType::class, $editeur);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $editeur->setUpdateAt(new \DateTime());
            $em->flush();
            $this->addFlash('success', 'L\'éditeur a bien été modifié');
            return $this->redirectToRoute('admin.editeur.index');
        }
        return $this->render('admin/editeur/edit.html.twig', [
            'editeur' => $editeur,
            'form' => $form
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request, EntityManagerInterface $em)
    {
        $editeur = new Editeur();
        $form = $this->createForm(EditeurType::class, $editeur);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $editeur->setUpdateAt(new \DateTime());
            $em->persist($editeur);
            $em->flush();
            $this->addFlash('success', 'L\'éditeur a été crée !');
            return $this->redirectToRoute('admin.editeur.index');
        }
        return $this->render('admin/editeur/create.html.twig', [
            'form' => $form
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => Requirement::DIGITS])]
    public function remove(Editeur $editeur, EntityManagerInterface $em)
    {
        $em->remove($editeur);
        $em->flush();
        $this->addFlash('success', 'L\'editeur a bien été supprimé');
        return $this->redirectToRoute('admin.editeur.index');
    }
}

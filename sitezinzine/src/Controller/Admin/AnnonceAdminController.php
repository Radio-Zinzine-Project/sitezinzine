<?php

namespace App\Controller\Admin;

use App\Entity\Annonce;
use App\Form\AnnonceType;
use App\Repository\AnnonceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/admin/annonce', name: 'admin.annonce.')]
#[IsGranted('ROLE_EDITOR')]
class AnnonceAdminController extends AbstractController
{
    

#[Route('/', name: 'index', methods: ['GET'])]
public function index(
    Request $request,
    AnnonceRepository $annonceRepository,
    PaginatorInterface $paginator
): Response {
    $query = $annonceRepository->findAllDescQuery();

    $annonces = $paginator->paginate(
        $query,
        $request->query->getInt('page', 1),
        20
    );

    return $this->render('/admin/annonce/index.html.twig', [
        'annonces' => $annonces,
    ]);
}

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    public function edit(Annonce $annonce, Request $request, EntityManagerInterface $em)
    {
        $form = $this->createForm(AnnonceType::class, $annonce, [
            'show_valid' => true, // Montrer le champ valid
            
        ]);
      
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
              // ✅ Récupérer la valeur du champ "Autre type"
        $autreType = $form->get('autreType')->getData();

        // ✅ Si "Autre" est sélectionné et que le champ "Autre type" est rempli, on l'enregistre
        if ($annonce->getType() === 'autre' && !empty($autreType)) {
            $annonce->setType($autreType);
        }
            $annonce->setUpdateAt(new \DateTime());
            $em->flush();
            $this->addFlash('success', 'L\'annonce a bien été modifié');
            return $this->redirectToRoute('admin.annonce.index');
        }
        return $this->render('admin/annonce/edit.html.twig', [
            'annonce' => $annonce,
            'form' => $form
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(Annonce $annonce, int $id, AnnonceRepository $annonceRepository)
    {
        $annonce = $annonceRepository->find($id);
        return $this->render('admin/annonce/show.html.twig', [
            'annonce' => $annonce,
            
        ]);
    }

    #[Route('/{id}', name: 'softDelete', methods: ['DELETE'], requirements: ['id' => Requirement::DIGITS])]
    public function remove(Annonce $annonce, EntityManagerInterface $em)
    {
        $annonce->setSoftDelete(true);

        $em->flush();
        $this->addFlash('success', 'L\'annonce a bien été supprimé');
        return $this->redirectToRoute('admin.annonce.index');
    }

    #[Route('/{id}/valid', name: 'valid', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    public function valid(Annonce $annonce, EntityManagerInterface $em)
    {
        $annonce->setValid(true);

        $em->flush();
        $this->addFlash('success', 'L\'annonce a bien été validé');
        return $this->redirectToRoute('admin.annonce.index');
    }

    #[Route('/{id}/unvalid', name: 'unvalid', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    public function unvalid(Annonce $annonce, EntityManagerInterface $em)
    {
        $annonce->setValid(false);

        $em->flush();
        $this->addFlash('success', 'L\'annonce a bien été dé-validé');
        return $this->redirectToRoute('admin.annonce.index');
    }
    
}


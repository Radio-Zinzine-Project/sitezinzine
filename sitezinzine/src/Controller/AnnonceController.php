<?php

namespace App\Controller;

use App\Entity\Annonce;
use App\Form\AnnonceType;
use Knp\Component\Pager\PaginatorInterface;
use App\Repository\AnnonceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;


#[Route("/annonce", name: 'annonce.')]
class AnnonceController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(
        AnnonceRepository $annonceRepository,
        PaginatorInterface $paginator,
        Request $request
    ): Response {
        $query = $annonceRepository->findUpcomingAnnoncesQuery();

        $annonces = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            12
        );

        return $this->render('/home/annonces.html.twig', [
            'annonces' => $annonces,
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $annonce = new Annonce();

        $form = $this->createForm(AnnonceType::class, $annonce, [
            'show_valid' => false,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $autreType = trim((string) $form->get('autreType')->getData());

            if ($annonce->getType() === '__autre__') {
                if ($autreType !== '') {
                    $annonce->setType($autreType);
                } else {
                    $annonce->setType('Autre');
                }
            }

            $annonce->setUpdateAt(new \DateTime());
            $annonce->setValid(false);
            $annonce->setSoftDelete(false);

            $em->persist($annonce);
            $em->flush();

            $this->addFlash(
                'success',
                'L\'annonce a bien été créée, il faudra attendre sa validation pour qu\'elle s\'affiche'
            );

            return $this->redirectToRoute('annonce.index');
        }

        return $this->render('/home/annoncesCreate.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]

    public function annonceShow(Annonce $annonce, int $id, AnnonceRepository $annonceRepository)
    {
        $annonce = $annonceRepository->find($id);
        return $this->render('home/annonceShow.html.twig', [
            'annonce' => $annonce,

        ]);
    }
}

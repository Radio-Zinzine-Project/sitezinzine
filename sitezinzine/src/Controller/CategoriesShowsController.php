<?php

namespace App\Controller;

use App\Repository\CategoriesRepository;
use App\Repository\EmissionRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

#[Route("/categorie", name: 'categorie.')]
class CategoriesShowsController extends AbstractController
{
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(
        int $id,
        CategoriesRepository $categoriesRepository,
        EmissionRepository $emissionRepository,
        Request $request,
        PaginatorInterface $paginator
    ): Response {
        $categorie = $categoriesRepository->find($id);

        if (!$categorie) {
            throw $this->createNotFoundException('Catégorie introuvable');
        }

        $qb = $emissionRepository->createLatestByCategoryQueryBuilder($id);

        $emissions = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            20
        );

        return $this->render('/home/showCat.html.twig', [
            'categorie' => $categorie,
            'emissions' => $emissions,
        ]);
    }
}

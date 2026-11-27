<?php

namespace App\Controller\Admin;

use App\Entity\CategorieTagImage;
use App\Form\CategorieTagImageType;
use App\Repository\CategorieTagImageRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route('/admin/categorieTagImage', name: 'admin_categorieTagImage_')]
final class CategorieTagImageController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(CategorieTagImageRepository $categorieTagImageRepository): Response
    {
        return $this->render('admin/categorieTagImage/index.html.twig', [
            'categorieTagImages' => $categorieTagImageRepository->findAllOrdered(),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $categorieTagImage = new CategorieTagImage();
        $form = $this->createForm(CategorieTagImageType::class, $categorieTagImage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em->persist($categorieTagImage);
                $em->flush();

                $this->addFlash('success', 'L’image annuelle a bien été créée.');

                return $this->redirectToRoute('admin_categorieTagImage_index');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Une image existe déjà pour cette catégorie et cette année.');
            }
        }

        return $this->render('admin/categorieTagImage/create.html.twig', [
            'form' => $form->createView(),
            'categorieTagImage' => $categorieTagImage,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(CategorieTagImage $categorieTagImage, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CategorieTagImageType::class, $categorieTagImage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em->flush();

                $this->addFlash('success', 'L’image annuelle a bien été mise à jour.');

                return $this->redirectToRoute('admin_categorieTagImage_index');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Une image existe déjà pour cette catégorie et cette année.');
            }
        }

        return $this->render('admin/categorieTagImage/edit.html.twig', [
            'form' => $form->createView(),
            'categorieTagImage' => $categorieTagImage,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(CategorieTagImage $categorieTagImage, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete_categorie_tag_image_' . $categorieTagImage->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('admin_categorieTagImage_index');
        }

        $em->remove($categorieTagImage);
        $em->flush();

        $this->addFlash('success', 'L’image annuelle a bien été supprimée.');

        return $this->redirectToRoute('admin_categorieTagImage_index');
    }
}

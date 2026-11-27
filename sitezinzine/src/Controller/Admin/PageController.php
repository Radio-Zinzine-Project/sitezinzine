<?php

namespace App\Controller\Admin;

use App\Entity\Page;
use App\Form\PageType;
use App\Repository\PageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/page', name: 'admin.page.')]
class PageController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(PageRepository $pageRepository): Response
    {
        $pages = $pageRepository->findAll();

        return $this->render('admin/page/index.html.twig', [
            'pages' => $pages,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $page = new Page();

        $form = $this->createForm(PageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $page->setUpdatedAt(new \DateTime());

            $em->persist($page);
            $em->flush();

            $this->addFlash('success', 'La page a bien été créée.');

            return $this->redirectToRoute('admin.page.index');
        }

        return $this->render('admin/page/create.html.twig', [
            'page' => $page,
            'form' => $form->createView(),
        ]);
    }


    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Page $page, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(PageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            if ($form->has('deleteMainImage') && $form->get('deleteMainImage')->getData()) {

                // on supprime la référence fichier
                $page->setMainImageFile(null);
                $page->setMainImageName(null);
            }
            $page->setUpdatedAt(new \DateTime());

            $em->flush();

            $this->addFlash('success', 'La page a bien été mise à jour.');

            return $this->redirectToRoute('admin.page.index');
        }

        return $this->render('admin/page/edit.html.twig', [
            'page' => $page,
            'form' => $form->createView(),
        ]);
    }
}

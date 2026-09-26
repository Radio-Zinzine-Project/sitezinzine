<?php

namespace App\Controller\Admin;

use App\Entity\SiteImage;
use App\Form\SiteImageType;
use App\Repository\SiteImageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/site-image', name: 'admin_siteImage_')]
final class SiteImageController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(
        SiteImageRepository $siteImageRepository
    ): Response {
        return $this->render('admin/siteImage/index.html.twig', [
            'siteImages' => $siteImageRepository->findBy(
                [],
                ['label' => 'ASC']
            ),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $siteImage = new SiteImage();

        $form = $this->createForm(
            SiteImageType::class,
            $siteImage
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($siteImage);
            $em->flush();

            $this->addFlash(
                'success',
                'L’image du site a bien été créée.'
            );

            return $this->redirectToRoute(
                'admin_siteImage_index'
            );
        }

        return $this->render('admin/siteImage/create.html.twig', [
            'form' => $form->createView(),
            'siteImage' => $siteImage,
        ]);
    }

    #[Route(
        '/{id}/edit',
        name: 'edit',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+']
    )]
    public function edit(
        SiteImage $siteImage,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $form = $this->createForm(
            SiteImageType::class,
            $siteImage
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $this->addFlash(
                'success',
                'L’image du site a bien été mise à jour.'
            );

            return $this->redirectToRoute(
                'admin_siteImage_index'
            );
        }

        return $this->render('admin/siteImage/edit.html.twig', [
            'form' => $form->createView(),
            'siteImage' => $siteImage,
        ]);
    }
}

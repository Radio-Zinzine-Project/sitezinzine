<?php


namespace App\Controller\Admin;

use App\Entity\Theme;
use App\Form\ThemeType;
use App\Repository\ThemeRepository;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Requirement\Requirement;
use Doctrine\ORM\EntityManagerInterface;

#[Route("/admin/theme", name: 'admin.theme.')]
#[IsGranted("ROLE_ADMIN")]
#[IsGranted("ROLE_SUPER_ADMIN")]
class ThemeController extends AbstractController
{
    #[Route("/", name: 'index')]
    public function index(Request $request, ThemeRepository $themesRepository): Response
    {
        $theme = $themesRepository->findAll();
        return $this->render('admin/theme/index.html.twig', [
            'themes' => $theme
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(
        Theme $theme,
        Request $request,
        ThemeRepository $themeRepository
    ): Response {
        $page = $request->query->getInt('page', 1);

        $initiale = strtoupper(
            trim((string) $request->query->get('initiale', ''))
        );

        $emissions = $themeRepository->paginateEmissions(
            theme: $theme,
            page: $page,
            initiale: $initiale
        );

        return $this->render('admin/theme/show.html.twig', [
            'theme' => $theme,
            'emissions' => $emissions,
            'initiale' => $initiale,
            'alphabet' => range('A', 'Z'),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    public function edit(Theme $theme, Request $request, EntityManagerInterface $em)
    {
        $form = $this->createForm(ThemeType::class, $theme);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $theme->setUpdatedAt(new \DateTime());
            $em->flush();
            $this->addFlash('success', 'Le thème a bien été modifié');
            return $this->redirectToRoute('admin.theme.index');
        }
        return $this->render('admin/theme/edit.html.twig', [
            'theme' => $theme,
            'form' => $form
        ]);
    }

    #[Route('/create', name: 'create')]
    public function create(Request $request, EntityManagerInterface $em)
    {
        $theme = new Theme();
        $form = $this->createForm(ThemeType::class, $theme);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTime();
            $theme
                ->setUpdatedAt($now);
            $em->persist($theme);
            $em->flush();
            $this->addFlash('success', 'Le thème a été crée !');
            return $this->redirectToRoute('admin.theme.index');
        }
        return $this->render('admin/theme/create.html.twig', [
            'form' => $form
        ]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => Requirement::DIGITS])]
    public function remove(Theme $theme, EntityManagerInterface $em)
    {
        $em->remove($theme);
        $em->flush();
        $this->addFlash('success', 'Le thème a bien été supprimée');
        return $this->redirectToRoute('admin.theme.index');
    }
}

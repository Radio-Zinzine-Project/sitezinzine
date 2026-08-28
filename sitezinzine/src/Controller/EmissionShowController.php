<?php

namespace App\Controller;

use App\Entity\Emission;
use App\Form\EmissionSearchType;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\EmissionRepository;
use App\Repository\ThemeRepository;
use App\Repository\DiffusionRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;



#[Route("/emission", name: 'emission.')]
class EmissionShowController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(Request $request, EmissionRepository $emissionRepository, Security $security): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = 25;

        $pagination = $emissionRepository->paginateEmissions($page, '', $this->getUser(), $security);


        return $this->render('/home/emissions.html.twig', [
            'pagination' => $pagination, // pour knp_pagination_render
            'emissions' => $pagination->getItems(), // pour le partial
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(
        Emission $emission,
        EmissionRepository $emissionRepository,
        ThemeRepository $themeRepository,
        DiffusionRepository $diffusionRepository,
        PaginatorInterface $paginator,
        Request $request
    ): Response {
        $theme = $emission->getTheme();
        $themeGroups = $emissionRepository->getThemeGroups();

        if (!$theme) {
            throw $this->createNotFoundException('Le thème de cette émission n\'existe pas.');
        }

        $currentThemeId = $theme->getId();

        $groupKey = null;
        foreach ($themeGroups as $key => $ids) {
            if (in_array($currentThemeId, $ids)) {
                $groupKey = $key;
                break;
            }
        }

        $relatedThemeIds = $themeGroups[$groupKey] ?? [];

        // Pour les boutons
        $themesInGroup = $themeRepository->findBy(['id' => $relatedThemeIds]);

        // Liste des émissions liées avec pagination
        $relatedEmissions = $emissionRepository->paginateEmissionsByThemeGroup(
            $relatedThemeIds,
            $request->query->getInt('page', 1)
        );

        // Dernières diffusions publiées de l'émission
        $latestDiffusions = $diffusionRepository->findLatestByEmission(
            $emission,
            6
        );

        return $this->render('/home/show.html.twig', [
            'emission' => $emission,
            'theme' => $theme,
            'themesInGroup' => $themesInGroup,
            'relatedEmissions' => $relatedEmissions,
            'latestDiffusions' => $latestDiffusions,
        ]);
    }


    #[Route('/recherche', name: 'recherche')]
    public function search(Request $request, EmissionRepository $emissionRepository): Response
    {
        $form = $this->createForm(EmissionSearchType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $criteria = $form->getData();
            $page = $request->query->getInt('page', 1);
        } else {
            $criteria = [];
            $page = $request->query->getInt('page', 1);
        }

        $emissions = $emissionRepository->findBySearch($criteria, $page);

        foreach ($emissions as $emission) {
            $lastDate = $emissionRepository->findLastDiffusionDate($emission->getId());
            if ($lastDate) {
                $emission->setLastDiffusion($lastDate);
            }
        }

        return $this->render('home/recherche.html.twig', [
            'form' => $form->createView(),
            'emissions' => $emissions,
            'searchTerm' => $form->get('titre')->getData(),
        ]);
    }
}

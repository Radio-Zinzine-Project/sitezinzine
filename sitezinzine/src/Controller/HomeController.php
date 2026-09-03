<?php

namespace App\Controller;


use App\Repository\EmissionRepository;
use App\Repository\EvenementRepository;
use App\Repository\PageRepository;
use App\Entity\Evenement;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Service\PublicScheduleBuilder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route("/", name: "home")]
    public function index(
        EmissionRepository $emissionRepository,
        EvenementRepository $evenementRepository
    ): Response {
        $timezone = new \DateTimeZone('Europe/Paris');

        $date = new \DateTimeImmutable('today', $timezone);
        $now = new \DateTimeImmutable('now', $timezone);

        $programData = $emissionRepository->findProgramForDate($date, $now);

        // Fallback DEV si aucune programmation aujourd'hui
        if (empty($programData['items'])) {
            $fixedDate = new \DateTimeImmutable('2026-03-07', $timezone);

            $fakeNow = $fixedDate->setTime(
                (int) $now->format('H'),
                (int) $now->format('i'),
                (int) $now->format('s')
            );

            $programData = $emissionRepository->findProgramForDate(
                $fixedDate,
                $fakeNow
            );
        }

        return $this->render('home/index.html.twig', [
            'lastEmissions' => $programData['items'],
            'activeIndex' => $programData['activeIndex'],
            'lastEmissionsByTheme' => $emissionRepository->lastEmissionsByGroupTheme(''),
            'evenements' => $evenementRepository->findLatestPublicEvenements(3),
        ]);
    }

    #[Route('/{id}', name: 'showEvenement', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function showEvenement(Evenement $evenement): Response
    {


        return $this->render('/home/showEvenement.html.twig', [
            'evenement' => $evenement,

        ]);
    }

    #[Route('/radio', name: 'radio')]
    public function radio(PageRepository $pageRepository): Response
    {
        // adapte le slug si tu l'as appelé autrement dans l’admin
        $page = $pageRepository->findOneBy(['slug' => 'radio']);

        if (!$page) {
            throw $this->createNotFoundException('Page "radio" introuvable.');
        }

        return $this->render('home/radio.html.twig', [
            'page' => $page,
        ]);
    }

    #[Route('/programme', name: 'programme')]
    public function programme(
        PublicScheduleBuilder $publicScheduleBuilder
    ): Response {
        $today = new \DateTimeImmutable('today');

        $dayOfWeek = (int) $today->format('N');

        // Notre semaine radio commence le mardi.
        $daysSinceTuesday = ($dayOfWeek + 5) % 7;

        $startOfWeek = $today->modify(sprintf('-%d days', $daysSinceTuesday));

        $programme = $publicScheduleBuilder->build($startOfWeek);

        return $this->render('home/programme.html.twig', [
            'programme' => $programme,
        ]);
    }

    #[Route("/infos", name: "infos")]
    function infos(): Response
    {

        return $this->render('home/infos.html.twig');
    }

    #[Route("/zone", name: "zone")]
    function zone(PageRepository $pageRepository): Response
    {

        $page = $pageRepository->findOneBy(['slug' => 'zone']);

        if (!$page) {
            throw $this->createNotFoundException('Page "zone" introuvable.');
        }

        return $this->render('home/zoneecoute.html.twig', [
            'page' => $page,
        ]);
    }
    #[Route("/aide", name: "aide")]
    function aide(PageRepository $pageRepository): Response
    {

        $page = $pageRepository->findOneBy(['slug' => 'aide']);

        if (!$page) {
            throw $this->createNotFoundException('Page "aide" introuvable.');
        }

        return $this->render('home/aide.html.twig', [
            'page' => $page,
        ]);
    }
    #[Route("/amis", name: "amis")]
    function amis(PageRepository $pageRepository): Response
    {

        $page = $pageRepository->findOneBy(['slug' => 'amis']);

        if (!$page) {
            throw $this->createNotFoundException('Page "amis" introuvable.');
        }

        return $this->render('home/amis.html.twig', [
            'page' => $page,
        ]);
    }

    #[Route("/mentions", name: "mentions")]
    function mentions(PageRepository $pageRepository): Response
    {

        $page = $pageRepository->findOneBy(['slug' => 'mentions']);

        if (!$page) {
            throw $this->createNotFoundException('Page "mentions" introuvable.');
        }

        return $this->render('home/mentions.html.twig', [
            'page' => $page,
        ]);
    }

    #[Route("/contacts", name: "contacts")]
    function contacts(PageRepository $pageRepository): Response
    {

        $page = $pageRepository->findOneBy(['slug' => 'contacts']);

        if (!$page) {
            throw $this->createNotFoundException('Page "contacts" introuvable.');
        }

        return $this->render('home/contacts.html.twig', [
            'page' => $page,
        ]);
    }

    #[Route("/don", name: "don")]
    function don(PageRepository $pageRepository): Response
    {
        // adapte le slug si tu l'as appelé autrement dans l’admin
        $page = $pageRepository->findOneBy(['slug' => 'don']);

        if (!$page) {
            throw $this->createNotFoundException('Page "don" introuvable.');
        }

        return $this->render('home/don.html.twig', [
            'page' => $page,
        ]);
    }

    #[Route("/newsletter", name: "newsletter")]
    function newsletter(PageRepository $pageRepository): Response
    {

        $page = $pageRepository->findOneBy(['slug' => 'newsletter']);

        if (!$page) {
            throw $this->createNotFoundException('Page "newsletter" introuvable.');
        }

        return $this->render('home/newsletter.html.twig', [
            'page' => $page,
        ]);
    }
}

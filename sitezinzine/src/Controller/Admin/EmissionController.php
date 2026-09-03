<?php

namespace App\Controller\Admin;

use App\Entity\Emission;

use App\Form\EmissionType;
use App\Repository\EmissionRepository;
use App\Repository\DiffusionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use App\Form\EmissionSearchType;
use App\Controller\Traits\ReturnToTrait;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Knp\Component\Pager\PaginatorInterface;
use Vich\UploaderBundle\Storage\StorageInterface;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;
use App\Entity\User;
use App\Service\Mp3Processor;




#[Route('/admin/emission', name: 'admin.emission.')]
#[IsGranted('ROLE_USER')]
class EmissionController extends AbstractController
{
    use ReturnToTrait;

    #[Route('/', name: 'index')]
    public function index(Request $request, EmissionRepository $repository, Security $security, SessionInterface $session): Response
    {
        $page = $request->query->getInt('page', 1);
        $limit = 25;

        // Stockage de la page courante dans la session
        $session->set('previous_page_emission', $page);

        $user = $security->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPER_ADMIN');

        $emissions = $repository->paginateEmissionsAdmin($page, '', $user, $isAdmin);
        $maxPage = (int) ceil($emissions->getTotalItemCount() / $limit);

        return $this->render('admin/emission/index.html.twig', [
            'emissions' => $emissions,
            'maxPage' => $maxPage,
        ]);
    }


    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(
        Emission $emission,
        EmissionRepository $emissionRepository,
        DiffusionRepository $diffusionRepository,
        PaginatorInterface $paginator,
        Request $request
    ): Response {

        $emissions = null;

        if ($emission->getCategorie() !== null) {
            $query = $emissionRepository->createQueryBuilder('e')
                ->where('e.categorie = :categorie')
                ->andWhere('e != :current')
                ->setParameter('categorie', $emission->getCategorie())
                ->setParameter('current', $emission)
                ->orderBy('e.datepub', 'DESC')
                ->getQuery();

            $emissions = $paginator->paginate(
                $query,
                $request->query->getInt('page', 1),
                10
            );
        }

        $diffusions = $diffusionRepository->findAllByEmission($emission);

        return $this->render('admin/emission/show.html.twig', [
            'emission' => $emission,
            'emissions' => $emissions,
            'diffusions' => $diffusions,
        ]);
    }


    #[Route('/create', name: 'create')]
    public function create(Request $request, EntityManagerInterface $em, Security $security): Response
    {
        $emission = new Emission();

        /** @var User|null $user */
        $user = $security->getUser();

        $form = $this->createForm(EmissionType::class, $emission, [
            'current_user_identifier' => $user?->getUserIdentifier(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTime();

            // Si ref est vide, on met le username du user connecté
            if (empty($emission->getRef()) && $user) {
                $emission->setRef($user->getUserIdentifier());
            }

            // Si aucun user n'est sélectionné, on ajoute le user connecté par défaut
            if ($user && $emission->getUsers()->isEmpty()) {
                $emission->addUser($user);
            }

            $emission
                ->setDatepub($now)
                ->setUpdatedat($now);

            $em->persist($emission);
            $em->flush();

            $this->addFlash('success', 'L\'émission a été créée !');

            return $this->redirectToRoute('admin.emission.index');
        }

        return $this->render('admin/emission/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }


    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    public function edit(
        Request $request,
        Emission $emission,
        EntityManagerInterface $em,
        Security $security,
        SessionInterface $session,
        UrlGeneratorInterface $urlGenerator,
        StorageInterface $storage,
        PropertyMappingFactory $mappingFactory,
        Mp3Processor $mp3Processor
    ): Response {
        $user = $security->getUser();

        // Vérifie si l'utilisateur est admin/super_admin ou lié à l'émission
        if (
            !$this->isGranted('ROLE_ADMIN') &&
            !$this->isGranted('ROLE_SUPER_ADMIN') &&
            (!$user || !$emission->getUsers()->contains($user))
        ) {
            throw $this->createAccessDeniedException('Vous n\'avez pas les droits pour modifier cette émission.');
        }

        // Enregistre returnTo si présent
        $this->storeReturnTo($request, $session);

        // Création et gestion du formulaire
        $form = $this->createForm(EmissionType::class, $emission, [
            'current_user_identifier' => $user?->getUserIdentifier(),
            'with_mp3' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Checkbox "Marquer comme complétée" (visible si l'émission est en attente de finalisation)
            if (
                $emission->isPendingCompletion()
                && $form->has('markAsCompleted')
                && $form->get('markAsCompleted')->getData()
            ) {
                $emission->markAsCompleted();
            }

            // ✅ suppression image si demandée
            if ($request->request->getBoolean('delete_thumbnail')) {

                $mappings = $mappingFactory->fromObject($emission);
                $thumbnailMapping = null;

                foreach ($mappings as $m) {
                    if (method_exists($m, 'getPropertyName') && $m->getPropertyName() === 'thumbnailFile') {
                        $thumbnailMapping = $m;
                        break;
                    }
                }

                if (null !== $thumbnailMapping) {
                    $storage->remove($emission, $thumbnailMapping);
                }

                $emission->setThumbnail(null);
            }

            // ✅ gestion MP3
            $deleteMp3 = $form->has('deleteMp3') ? (bool) $form->get('deleteMp3')->getData() : false;
            $newMp3Uploaded = $form->has('thumbnailFileMp3') && $form->get('thumbnailFileMp3')->getData() !== null;

            // Si suppression demandée sans nouvel upload
            if ($deleteMp3 && !$newMp3Uploaded) {
                $mp3Processor->delete($emission);
            }

            $emission->setUpdatedat(new \DateTime());
            $em->flush();

            // Si un nouveau MP3 est envoyé, on le traite après flush
            if ($newMp3Uploaded) {
                $mp3Processor->process($emission);
                $emission->setUpdatedat(new \DateTime());
                $em->flush();
            }

            $this->addFlash('success', 'L\'émission a bien été modifiée.');

            return $this->redirectToReturnTo($session, $urlGenerator, 'admin.emission.index', [
                'page' => $session->get('previous_page_emission', 1),
            ]);
        }

        return $this->render('admin/emission/edit.html.twig', [
            'emission' => $emission,
            'formEmission' => $form->createView(),
        ]);
    }


    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => Requirement::DIGITS])]
    public function delete(Request $request, Emission $emission, EntityManagerInterface $em): Response
    {
        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('delete' . $emission->getId(), $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('admin.emission.index');
        }

        $emission->softDelete();
        $em->flush();

        $this->addFlash('success', 'L\'émission a bien été supprimée.');

        $returnTo = $request->query->get('returnTo');

        if ($returnTo) {
            return $this->redirect($returnTo);
        }

        return $this->redirectToRoute('admin.emission.index');
    }

    #[Route('/rechercheadmin', name: 'rechercheadmin')]
    public function search(
        Request $request,
        EmissionRepository $emissionRepository
    ): Response {
        $form = $this->createForm(EmissionSearchType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $criteria = $form->getData();
        } else {
            $criteria = [];
        }

        $page = $request->query->getInt('page', 1);

        $initiale = strtoupper(
            trim((string) $request->query->get('initiale', ''))
        );

        $emissions = $emissionRepository->findBySearchAdmin(
            $criteria,
            $page,
            $initiale
        );

        foreach ($emissions as $emission) {
            $lastDate = $emissionRepository->findLastDiffusionDate(
                $emission->getId()
            );

            if ($lastDate) {
                $emission->setLastDiffusion($lastDate);
            }
        }

        return $this->render('admin/recherche.html.twig', [
            'form' => $form->createView(),
            'emissions' => $emissions,
            'searchTerm' => $form->get('titre')->getData(),
            'initiale' => $initiale,
            'alphabet' => range('A', 'Z'),
        ]);
    }


    #[Route('/{id}/delete-mp3', name: 'delete_mp3', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteMp3(
        Emission $emission,
        Request $request,
        EntityManagerInterface $em,
        Mp3Processor $mp3Processor
    ): Response {
        if (!$this->isCsrfTokenValid('delete_mp3_' . $emission->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('admin.emission.edit', ['id' => $emission->getId()]);
        }

        if (!$emission->getThumbnailMp3()) {
            $this->addFlash('error', 'Aucun fichier MP3 à supprimer.');

            return $this->redirectToRoute('admin.emission.edit', ['id' => $emission->getId()]);
        }

        $mp3Processor->delete($emission);
        $em->flush();

        $this->addFlash('success', 'Le fichier MP3 a bien été supprimé.');

        return $this->redirectToRoute('admin.emission.edit', ['id' => $emission->getId()]);
    }

    #[Route('/{id}/mark-completed', name: 'mark_completed', methods: ['POST'], requirements: ['id' => Requirement::DIGITS])]
    public function markCompleted(
        Request $request,
        Emission $emission,
        EntityManagerInterface $em,
        Security $security
    ): Response {
        $user = $security->getUser();

        if (
            !$this->isGranted('ROLE_ADMIN') &&
            !$this->isGranted('ROLE_SUPER_ADMIN') &&
            (!$user || !$emission->getUsers()->contains($user))
        ) {
            throw $this->createAccessDeniedException('Vous n’avez pas accès à cette émission.');
        }

        if (!$this->isCsrfTokenValid('mark_completed_' . $emission->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $emission->markAsCompleted();
        $em->flush();

        $this->addFlash('success', 'L’émission a été retirée de la liste des fiches à finaliser.');

        return $this->redirectToRoute('admin.index');
    }
}

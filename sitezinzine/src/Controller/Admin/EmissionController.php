<?php

namespace App\Controller\Admin;

use SortDirection;

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
use App\Entity\Categories;
use App\Repository\CategoriesRepository;
use App\Service\EmissionUserChoicesProvider;
use Symfony\Component\HttpFoundation\JsonResponse;




#[Route('/admin/emission', name: 'admin.emission.')]
#[IsGranted('ROLE_USER')]
class EmissionController extends AbstractController
{
    use ReturnToTrait;

    #[Route('/', name: 'index')]
    public function index(
        Request $request,
        EmissionRepository $repository,
        Security $security,
        SessionInterface $session
    ): Response {
        $page = $request->query->getInt('page', 1);

        // Stockage de la page courante dans la session
        $session->set('previous_page_emission', $page);

        $user = $security->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        // Recherche par titre
        $search = trim((string) $request->query->get('q', ''));

        // Catégorie
        $categoryId = $request->query->filter(
            'categorie',
            null,
            FILTER_VALIDATE_INT,
            ['flags' => FILTER_NULL_ON_FAILURE]
        );

        $categoryId = $categoryId && $categoryId > 0 ? $categoryId : null;

        // Thème
        $themeId = $request->query->filter(
            'theme',
            null,
            FILTER_VALIDATE_INT,
            ['flags' => FILTER_NULL_ON_FAILURE]
        );

        $themeId = $themeId && $themeId > 0 ? $themeId : null;

        // État : toutes / à finaliser / sans diffusion
        $status = (string) $request->query->get('status', '');

        // Navigation alphabétique
        $initiale = strtoupper(
            trim((string) $request->query->get('initiale', ''))
        );

        $emissions = $repository->paginateEmissionsAdmin(
            page: $page,
            user: $user,
            search: $search,
            categoryId: $categoryId,
            themeId: $themeId,
            status: $status,
            initiale: $initiale
        );

        return $this->render('admin/emission/index.html.twig', [
            'emissions' => $emissions,

            'search' => $search,
            'selectedCategory' => $categoryId,
            'selectedTheme' => $themeId,
            'status' => $status,

            'initiale' => $initiale,
            'alphabet' => range('A', 'Z'),

            'categories' => $repository->findCategoriesForUser($user),
            'themes' => $repository->findThemesForUser($user),
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
                ->orderBy('e.datepub', SortDirection::Descending)
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
    public function create(
        Request $request,
        EntityManagerInterface $em,
        Security $security
    ): Response {
        $emission = new Emission();

        /** @var User|null $user */
        $user = $security->getUser();

        $form = $this->createForm(EmissionType::class, $emission, [
            'current_user_identifier' => $user?->getUserIdentifier(),
            'current_user' => $user,

            /*
         * ADMIN et SUPER_ADMIN peuvent sélectionner
         * toutes les catégories actives/non supprimées.
         */
            'can_manage_all_categories' => $this->isGranted('ROLE_ADMIN'),

            /*
         * Seul SUPER_ADMIN peut associer librement
         * n'importe quel utilisateur à une émission.
         *
         * USER / EDITOR / ADMIN restent limités aux utilisateurs
         * actuellement rattachés à la catégorie sélectionnée.
         */
            'can_manage_all_users' => $this->isGranted('ROLE_SUPER_ADMIN'),
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $now = new \DateTime();

            /*
         * Le champ ref reste conservé temporairement pour les anciennes
         * données. Lors d'une création, s'il est vide, on mémorise
         * l'identifiant de la personne ayant créé la fiche.
         *
         * Cette information est indépendante de Emission.users :
         * ref = créateur/trice de la fiche
         * users = personnes associées à l'émission
         */
            if (empty($emission->getRef())) {
                $emission->setRef($user?->getUserIdentifier() ?? '');
            }

            $emission
                ->setDatepub($now)
                ->setUpdatedat($now);

            $em->persist($emission);
            $em->flush();

            $this->addFlash(
                'success',
                'L\'émission a été créée !'
            );

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
            'current_user' => $user,
            'can_manage_all_categories' => $this->isGranted('ROLE_ADMIN'),
            'can_manage_all_users' => $this->isGranted('ROLE_SUPER_ADMIN'),
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
                    if (
                        method_exists($m, 'getFilePropertyName')
                        && $m->getFilePropertyName() === 'thumbnailFile'
                    ) {
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

    #[Route(
        '/users-for-category/{id}',
        name: 'users_for_category',
        methods: ['GET'],
        requirements: ['id' => Requirement::DIGITS]
    )]
    public function usersForCategory(
        int $id,
        Request $request,
        Security $security,
        CategoriesRepository $categoriesRepository,
        EmissionRepository $emissionRepository,
        EmissionUserChoicesProvider $userChoicesProvider
    ): JsonResponse {
        /** @var User|null $user */
        $user = $security->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Vous devez être connecté·e pour accéder à cette ressource.'
            );
        }

        $categorie = $categoriesRepository->find($id);

        if (!$categorie instanceof Categories) {
            throw $this->createNotFoundException(
                'Cette catégorie n’existe pas.'
            );
        }

        /*
     * emissionId est envoyé uniquement lorsque le formulaire
     * correspond à l'édition d'une émission existante.
     *
     * En création, il est absent.
     */
        $emissionId = $request->query->getInt('emissionId');

        $emission = null;

        if ($emissionId > 0) {
            $emission = $emissionRepository->find($emissionId);

            if (!$emission instanceof Emission) {
                throw $this->createNotFoundException(
                    'Cette émission n’existe pas.'
                );
            }

            /*
         * Même règle que pour edit() :
         *
         * - ADMIN et SUPER_ADMIN peuvent modifier toutes les émissions ;
         * - USER et EDITOR uniquement celles auxquelles ils sont associés.
         */
            if (
                !$this->isGranted('ROLE_ADMIN')
                && !$emission->getUsers()->contains($user)
            ) {
                throw $this->createAccessDeniedException(
                    'Vous n’avez pas les droits pour modifier cette émission.'
                );
            }
        }

        /*
     * En édition, la catégorie actuellement enregistrée sur l'émission
     * reste autorisée même si elle est devenue inactive, supprimée
     * ou si le user n'y est plus rattaché.
     *
     * C'est la même règle que celle appliquée dans EmissionType.
     */
        $isCurrentEmissionCategory = (
            $emission instanceof Emission
            && $emission->getCategorie()?->getId() === $categorie->getId()
        );

        if (!$isCurrentEmissionCategory) {
            /*
         * Une nouvelle catégorie sélectionnée doit toujours être
         * active et non supprimée.
         */
            if (
                !$categorie->isActive()
                || $categorie->isSoftDelete()
            ) {
                throw $this->createAccessDeniedException(
                    'Cette catégorie ne peut pas être utilisée.'
                );
            }

            /*
         * ADMIN et SUPER_ADMIN peuvent utiliser toutes les catégories
         * actives/non supprimées.
         *
         * USER et EDITOR doivent actuellement appartenir à la catégorie.
         */
            if (
                !$this->isGranted('ROLE_ADMIN')
                && !$categorie->getUsers()->contains($user)
            ) {
                throw $this->createAccessDeniedException(
                    'Vous n’avez pas accès à cette catégorie.'
                );
            }
        }

        /*
     * Pour une création, on utilise une émission temporaire vide.
     * Elle permet au provider d'utiliser exactement la même logique
     * que le formulaire sans inventer une seconde règle métier.
     */
        $contextEmission = $emission ?? new Emission();

        $canManageAllUsers = $this->isGranted('ROLE_SUPER_ADMIN');

        $choices = $userChoicesProvider->getChoices(
            $categorie,
            $contextEmission,
            $canManageAllUsers
        );

        /*
     * En édition :
     * seuls les utilisateurs déjà associés à l'émission
     * sont sélectionnés.
     *
     * On ne rattache donc jamais automatiquement les nouveaux membres
     * actuels de la catégorie à une ancienne émission.
     */
        $selectedUserIds = [];

        if ($emission instanceof Emission) {
            foreach ($emission->getUsers() as $emissionUser) {
                if (
                    $emissionUser instanceof User
                    && $emissionUser->getId() !== null
                ) {
                    $selectedUserIds[] = $emissionUser->getId();
                }
            }
        } elseif ($this->isGranted('ROLE_ADMIN')) {
            /*
         * Création ADMIN / SUPER_ADMIN :
         * tous les utilisateurs actuellement rattachés à la catégorie
         * sont sélectionnés par défaut.
         *
         * Pour SUPER_ADMIN, les autres utilisateurs restent visibles
         * mais ne sont pas sélectionnés automatiquement.
         */
            foreach ($categorie->getUsers() as $categoryUser) {
                if (
                    $categoryUser instanceof User
                    && $categoryUser->getId() !== null
                ) {
                    $selectedUserIds[] = $categoryUser->getId();
                }
            }
        } elseif (
            $userChoicesProvider->isCurrentCategoryUser(
                $user,
                $categorie
            )
            && $user->getId() !== null
        ) {
            /*
         * Création USER / EDITOR :
         * le user connecté est sélectionné par défaut uniquement
         * s'il appartient actuellement à la catégorie.
         */
            $selectedUserIds[] = $user->getId();
        }

        $selectedUserIds = array_values(
            array_unique($selectedUserIds)
        );

        $users = [];

        foreach ($choices as $choice) {
            if (!$choice instanceof User || $choice->getId() === null) {
                continue;
            }

            $users[] = [
                'id' => $choice->getId(),
                'label' => $userChoicesProvider->getLabel(
                    $choice,
                    $categorie,
                    $contextEmission
                ),
                'group' => $userChoicesProvider->getGroupLabel(
                    $choice,
                    $categorie,
                    $contextEmission
                ),
                'status' => $userChoicesProvider->getStatus(
                    $choice,
                    $categorie,
                    $contextEmission
                ),
                'selected' => in_array(
                    $choice->getId(),
                    $selectedUserIds,
                    true
                ),
            ];
        }

        return $this->json([
            'categoryId' => $categorie->getId(),
            'emissionId' => $emission?->getId(),
            'users' => $users,
        ]);
    }
}

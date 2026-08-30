<?php

namespace App\Controller\Admin;

use App\Entity\Categories;
use App\Form\CategorieType;
use App\Repository\CategoriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\SecurityBundle\Security;
use Vich\UploaderBundle\Mapping\PropertyMappingFactory;
use Vich\UploaderBundle\Storage\StorageInterface;
use App\Repository\EmissionRepository;
use Knp\Component\Pager\PaginatorInterface;




#[Route("/admin/categorie", name: 'admin.categorie.')]
#[IsGranted("ROLE_USER")]
class CategorieController extends AbstractController
{
    #[Route(name: 'index')]
    public function index(
        Request $request,
        CategoriesRepository $categoriesRepository,
        SessionInterface $session,
        Security $security
    ): Response {
        $page = $request->query->getInt('page', 1);
        $limit = 10;

        $initiale = strtoupper(
            trim((string) $request->query->get('initiale', ''))
        );

        $session->set('previous_page', $page);

        $user = $security->getUser();

        $categorie = $categoriesRepository->paginateCategoriesWithCount(
            $page,
            $limit,
            $user,
            $initiale
        );

        return $this->render('admin/categorie/index.html.twig', [
            'categories' => $categorie,
            'initiale' => $initiale,
            'alphabet' => range('A', 'Z'),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => Requirement::DIGITS])]
    public function show(
        Categories $categorie,
        Request $request,
        EmissionRepository $emissionRepository,
        PaginatorInterface $paginator
    ): Response {
        if ($categorie->isSoftDelete()) {
            $this->addFlash('warning', 'Cette catégorie a été supprimée.');
            return $this->redirectToRoute('admin.categorie.index');
        }

        $page  = $request->query->getInt('page', 1);
        $limit = 12;

        $qb = $emissionRepository->createQueryBuilder('e')
            ->andWhere('e.categorie = :categorie')
            ->setParameter('categorie', $categorie)
            ->orderBy('e.datepub', 'DESC');

        $emissions = $paginator->paginate($qb, $page, $limit);

        return $this->render('admin/categorie/show.html.twig', [
            'categorie' => $categorie,
            'emissions' => $emissions, // ✅ KNP PaginationInterface
        ]);
    }




    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => Requirement::DIGITS])]
    public function edit(
        Categories $categorie,
        Request $request,
        EntityManagerInterface $em,
        SessionInterface $session,
        StorageInterface $storage,
        PropertyMappingFactory $mappingFactory
    ): Response {
        // 1) Catégorie supprimée => pas modifiable
        if ($categorie->isSoftDelete()) {
            $this->addFlash('warning', 'Impossible de modifier une catégorie supprimée.');
            return $this->redirectToRoute('admin.categorie.index');
        }

        // 2) Droits : admin/super_admin OK, sinon il faut appartenir à categorie.users
        $user = $this->getUser();
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_SUPER_ADMIN');

        if (!$isAdmin && (!$user || !$categorie->getUsers()->contains($user))) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas modifier cette catégorie.');
        }

        // 3) Gestion du returnTo
        $returnTo = $request->query->get('returnTo');
        if (is_string($returnTo) && $returnTo !== '') {
            $session->set('return_to_url', $returnTo);
        }

        // 4) Verrou serveur du slug : seuls les super-admin peuvent le modifier
        $originalSlug = $categorie->getSlug();

        // 5) Form
        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // 🔒 Empêche toute modification du slug si pas super-admin
            if (!$this->isGranted('ROLE_SUPER_ADMIN')) {
                $categorie->setSlug($originalSlug);
            }

            // ✅ suppression image si demandée (Vich)
            if ($request->request->getBoolean('delete_thumbnail')) {
                $mappings = $mappingFactory->fromObject($categorie);

                foreach ($mappings as $m) {
                    if (method_exists($m, 'getPropertyName') && $m->getPropertyName() === 'thumbnailFile') {
                        $storage->remove($categorie, $m);
                        break;
                    }
                }

                // Nettoyage du nom de fichier en BDD
                $categorie->setThumbnail(null);
            }

            $categorie->setUpdatedAt(new \DateTime());
            $em->flush();

            $this->addFlash('success', 'La catégorie a bien été modifiée.');

            // Redirection prioritaire : returnTo
            if ($session->has('return_to_url')) {
                $url = $session->get('return_to_url');
                $session->remove('return_to_url');
                return $this->redirect($url);
            }

            // Sinon retour à l’index + page précédente
            $previousPage = $session->get('previous_page', 1);
            return $this->redirectToRoute('admin.categorie.index', ['page' => $previousPage]);
        }

        return $this->render('admin/categorie/edit.html.twig', [
            'categorie' => $categorie,
            'form' => $form->createView(),
        ]);
    }



    #[Route('/create', name: 'create', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        Security $security,
        SessionInterface $session
    ): Response {
        $categorie = new Categories();

        // Gestion "returnTo" comme dans edit
        $returnTo = $request->query->get('returnTo');
        if ($returnTo) {
            $session->set('return_to_url', $returnTo);
        }

        $form = $this->createForm(CategorieType::class, $categorie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $categorie->setSoftDelete(false);
            $categorie->setUpdatedAt(new \DateTime());

            // ✅ si aucun user sélectionné dans le form, on met l'utilisateur courant par défaut
            $user = $security->getUser();
            if ($categorie->getUsers()->isEmpty() && $user) {
                $categorie->addUser($user);
            }

            $em->persist($categorie);
            $em->flush();

            $this->addFlash('success', 'La catégorie a été créée !');

            // Retour prioritaire : returnTo (comme edit)
            if ($session->has('return_to_url')) {
                $url = $session->get('return_to_url');
                $session->remove('return_to_url');
                return $this->redirect($url);
            }

            // Sinon retour index + page précédente si connue
            $previousPage = $session->get('previous_page', 1);
            return $this->redirectToRoute('admin.categorie.index', ['page' => $previousPage]);
        }

        return $this->render('admin/categorie/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'softDelete', methods: ['DELETE'], requirements: ['id' => Requirement::DIGITS])]
    public function remove(Request $request, Categories $categorie, EntityManagerInterface $em): Response
    {
        // ✅ admin/super_admin OK, sinon il faut être dans categorie.users
        if (
            !$this->isGranted('ROLE_ADMIN') &&
            !$this->isGranted('ROLE_SUPER_ADMIN') &&
            !$categorie->getUsers()->contains($this->getUser())
        ) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas supprimer cette catégorie.');
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $categorie->getId(), $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('admin.categorie.index');
        }

        $categorie->setSoftDelete(true);
        $categorie->setUpdatedAt(new \DateTime());
        $em->flush();

        $this->addFlash('success', 'La catégorie a bien été supprimée');

        $returnTo = $request->query->get('returnTo');
        if ($returnTo) {
            return $this->redirect($returnTo);
        }

        return $this->redirectToRoute('admin.categorie.index');
    }
}

<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\User;
use App\Entity\InviteOldAnimateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CategorieControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        /*
     * Important :
     * plusieurs tests effectuent plusieurs requêtes HTTP
     * (GET du formulaire puis POST/DELETE).
     *
     * Sans disableReboot(), Symfony redémarre le kernel entre
     * les requêtes et notre transaction de test peut être perdue.
     */
        $this->client->disableReboot();

        $this->entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
        } finally {
            try {
                parent::tearDown();
            } finally {
                unset($this->client, $this->entityManager);
            }
        }
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/admin/categorie');

        $this->assertResponseRedirects();
    }

    public function testIndexIsAccessibleForUser(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/categorie');

        $this->assertResponseIsSuccessful();
    }

    public function testIndexAcceptsInitialFilter(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Alpha ' . uniqid()
        );

        $categorie->addUser($user);

        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie',
            [
                'initiale' => 'a',
            ]
        );

        $this->assertResponseIsSuccessful();
    }

    public function testIndexAcceptsPageParameter(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie',
            [
                'page' => 2,
            ]
        );

        $this->assertResponseIsSuccessful();
    }

    public function testShowCategory(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie show ' . uniqid()
        );

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId()
        );

        $this->assertResponseIsSuccessful();
    }

    public function testShowCategoryAcceptsPageParameter(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie pagination ' . uniqid()
        );

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId(),
            [
                'page' => 2,
            ]
        );

        $this->assertResponseIsSuccessful();
    }

    public function testShowDeletedCategoryRedirectsToIndex(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie supprimée ' . uniqid(),
            softDelete: true
        );

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId()
        );

        $this->assertResponseRedirects('/admin/categorie');
    }

    public function testEditPageIsAccessibleForAdmin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie edit admin ' . uniqid()
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId() . '/edit'
        );

        $this->assertResponseIsSuccessful();
    }

    public function testEditPageIsAccessibleForCategoryOwner(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie propriétaire ' . uniqid()
        );

        $categorie->addUser($user);

        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId() . '/edit'
        );

        $this->assertResponseIsSuccessful();
    }

    public function testEditDeniedForUnrelatedUser(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie interdite ' . uniqid()
        );

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId() . '/edit'
        );

        $this->assertResponseRedirects('/access-denied');
    }

    public function testEditDeletedCategoryRedirectsToIndex(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie supprimée edit ' . uniqid(),
            softDelete: true
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId() . '/edit'
        );

        $this->assertResponseRedirects('/admin/categorie');
    }

    public function testEditCategoryAsAdmin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $editeur = $this->createEditeur();

        $categorie = $this->createCategorie(
            titre: 'Catégorie avant modification ' . uniqid(),
            editeur: $editeur
        );

        $categorie->addUser($admin);

        $this->entityManager->flush();

        $categorieId = $categorie->getId();
        $originalSlug = $categorie->getSlug();
        $newTitle = 'Catégorie modifiée ' . uniqid();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/' . $categorieId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form();

        $form['categorie[titre]'] = $newTitle;

        if ($form->has('categorie[duree]')) {
            $form['categorie[duree]'] = 60;
        }

        if ($form->has('categorie[descriptif]')) {
            $form['categorie[descriptif]'] = 'Description modifiée';
        }

        if ($form->has('categorie[editeur]')) {
            $form['categorie[editeur]'] = (string) $editeur->getId();
        }

        if ($form->has('categorie[active]')) {
            $form['categorie[active]'] = true;
        }

        if ($form->has('categorie[users]')) {
            $form['categorie[users]'] = [(string) $admin->getId()];
        }

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $updated = $entityManager
            ->getRepository(Categories::class)
            ->find($categorieId);

        $this->assertNotNull($updated);
        $this->assertSame($newTitle, $updated->getTitre());
        $this->assertSame($originalSlug, $updated->getSlug());
    }

    public function testEditPreservesSlugForNormalUser(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $editeur = $this->createEditeur();

        $categorie = $this->createCategorie(
            titre: 'Catégorie slug user ' . uniqid(),
            editeur: $editeur
        );

        $categorie->addUser($user);

        $this->entityManager->flush();

        $categorieId = $categorie->getId();
        $originalSlug = $categorie->getSlug();

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/' . $categorieId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form();

        $form['categorie[titre]'] = $categorie->getTitre();

        if ($form->has('categorie[slug]')) {
            $form['categorie[slug]'] = $this->uniqueCategorySlug();
        }

        if ($form->has('categorie[duree]')) {
            $form['categorie[duree]'] = 60;
        }

        if ($form->has('categorie[descriptif]')) {
            $form['categorie[descriptif]'] = 'Description de test';
        }

        if ($form->has('categorie[editeur]')) {
            $form['categorie[editeur]'] = (string) $editeur->getId();
        }

        if ($form->has('categorie[active]')) {
            $form['categorie[active]'] = true;
        }

        if ($form->has('categorie[users]')) {
            $form['categorie[users]'] = [(string) $user->getId()];
        }

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $updated = $entityManager
            ->getRepository(Categories::class)
            ->find($categorieId);

        $this->assertNotNull($updated);
        $this->assertSame($originalSlug, $updated->getSlug());
    }

    public function testEditSuperAdminCanChangeSlug(): void
    {
        $superAdmin = $this->createUser(['ROLE_SUPER_ADMIN']);
        $editeur = $this->createEditeur();

        $categorie = $this->createCategorie(
            titre: 'Catégorie superadmin ' . uniqid(),
            editeur: $editeur
        );

        $categorie->addUser($superAdmin);

        $this->entityManager->flush();

        $categorieId = $categorie->getId();
        $newSlug = $this->uniqueCategorySlug();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/' . $categorieId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form();

        $form['categorie[titre]'] = $categorie->getTitre();

        if ($form->has('categorie[slug]')) {
            $form['categorie[slug]'] = $newSlug;
        }

        if ($form->has('categorie[duree]')) {
            $form['categorie[duree]'] = 60;
        }

        if ($form->has('categorie[descriptif]')) {
            $form['categorie[descriptif]'] = 'Description superadmin';
        }

        if ($form->has('categorie[editeur]')) {
            $form['categorie[editeur]'] = (string) $editeur->getId();
        }

        if ($form->has('categorie[active]')) {
            $form['categorie[active]'] = true;
        }

        if ($form->has('categorie[users]')) {
            $form['categorie[users]'] = [(string) $superAdmin->getId()];
        }

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $updated = $entityManager
            ->getRepository(Categories::class)
            ->find($categorieId);

        $this->assertNotNull($updated);
        $this->assertSame($newSlug, $updated->getSlug());
    }

    public function testEditRedirectsToReturnTo(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $editeur = $this->createEditeur();

        $categorie = $this->createCategorie(
            titre: 'Catégorie returnTo ' . uniqid(),
            editeur: $editeur
        );

        $categorie->addUser($admin);

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/' . $categorie->getId() . '/edit',
            [
                'returnTo' => '/admin/emission/',
            ]
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Sauvegarder')
            ->form();

        $form['categorie[titre]'] = $categorie->getTitre();

        if ($form->has('categorie[duree]')) {
            $form['categorie[duree]'] = 60;
        }

        if ($form->has('categorie[descriptif]')) {
            $form['categorie[descriptif]'] = 'Description returnTo';
        }

        if ($form->has('categorie[editeur]')) {
            $form['categorie[editeur]'] = (string) $editeur->getId();
        }

        if ($form->has('categorie[active]')) {
            $form['categorie[active]'] = true;
        }

        if ($form->has('categorie[users]')) {
            $form['categorie[users]'] = [(string) $admin->getId()];
        }

        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/emission/');
    }

    public function testCreateRequiresAdmin(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/categorie/create'
        );

        $this->assertResponseRedirects('/access-denied');
    }

    public function testCreatePageIsAccessibleForAdmin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/categorie/create'
        );

        $this->assertResponseIsSuccessful();
    }

    public function testCreateCategory(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $editeur = $this->createEditeur();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/create'
        );

        $this->assertResponseIsSuccessful();

        $titre = 'Nouvelle catégorie ' . uniqid();
        $slug = $this->uniqueCategorySlug();

        $form = $crawler
            ->filter('form[name="categorie"]')
            ->form();

        $form['categorie[titre]'] = $titre;

        if ($form->has('categorie[slug]')) {
            $form['categorie[slug]'] = $slug;
        }

        if ($form->has('categorie[duree]')) {
            $form['categorie[duree]'] = 60;
        }

        if ($form->has('categorie[descriptif]')) {
            $form['categorie[descriptif]'] =
                'Description nouvelle catégorie';
        }

        if ($form->has('categorie[editeur]')) {
            $form['categorie[editeur]'] =
                (string) $editeur->getId();
        }

        if ($form->has('categorie[active]')) {
            $form['categorie[active]'] = true;
        }

        if ($form->has('categorie[users]')) {
            $form['categorie[users]'] = [
                (string) $admin->getId(),
            ];
        }

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $created = $entityManager
            ->getRepository(Categories::class)
            ->findOneBy([
                'titre' => $titre,
            ]);

        $this->assertNotNull($created);
        $this->assertFalse($created->isSoftDelete());
        $this->assertTrue($created->isActive());
        $this->assertSame(60, $created->getDuree());

        $this->assertSame(
            'Description nouvelle catégorie',
            $created->getDescriptif()
        );

        $this->assertSame(
            $editeur->getId(),
            $created->getEditeur()?->getId()
        );
    }

    public function testCreateRedirectsToReturnTo(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $editeur = $this->createEditeur();

        $this->client->loginUser($admin);

        $returnTo = '/admin/emission/';

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/create?returnTo=' . urlencode($returnTo)
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter('form[name="categorie"]');

        $this->assertCount(
            1,
            $formNode,
            'Le formulaire catégorie doit être présent sur la page de création.'
        );

        $form = $formNode->form();

        $form['categorie[titre]'] =
            'Catégorie create return ' . uniqid();

        if ($form->has('categorie[slug]')) {
            $form['categorie[slug]'] =
                $this->uniqueCategorySlug();
        }

        if ($form->has('categorie[duree]')) {
            $form['categorie[duree]'] = 60;
        }

        if ($form->has('categorie[descriptif]')) {
            $form['categorie[descriptif]'] =
                'Description create return';
        }

        if ($form->has('categorie[editeur]')) {
            $form['categorie[editeur]'] =
                (string) $editeur->getId();
        }

        if ($form->has('categorie[active]')) {
            $form['categorie[active]'] = true;
        }

        if ($form->has('categorie[users]')) {
            $form['categorie[users]'] = [
                (string) $admin->getId(),
            ];
        }

        $this->client->submit($form);

        $this->assertResponseRedirects($returnTo);
    }

    public function testRemoveCategoryAsAdmin(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie delete admin ' . uniqid()
        );

        $categorieId = $categorie->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie'
        );

        $this->assertResponseIsSuccessful();

        $token = $this->getDeleteTokenFromCrawler(
            $crawler,
            $categorieId
        );

        $this->client->request(
            'DELETE',
            '/admin/categorie/' . $categorieId,
            [
                '_token' => $token,
            ]
        );

        $this->assertResponseRedirects('/admin/categorie');

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $deleted = $entityManager
            ->getRepository(Categories::class)
            ->find($categorieId);

        $this->assertNotNull($deleted);
        $this->assertTrue($deleted->isSoftDelete());
        $this->assertNotNull($deleted->getUpdatedAt());
    }

    public function testRemoveCategoryAsOwner(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie delete owner ' . uniqid()
        );

        $categorie->addUser($user);

        $this->entityManager->flush();

        $categorieId = $categorie->getId();

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie'
        );

        $this->assertResponseIsSuccessful();

        $token = $this->getDeleteTokenFromCrawler(
            $crawler,
            $categorieId
        );

        $this->client->request(
            'DELETE',
            '/admin/categorie/' . $categorieId,
            [
                '_token' => $token,
            ]
        );

        $this->assertResponseRedirects('/admin/categorie');

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $deleted = $entityManager
            ->getRepository(Categories::class)
            ->find($categorieId);

        $this->assertNotNull($deleted);
        $this->assertTrue($deleted->isSoftDelete());
    }

    public function testRemoveDeniedForUnrelatedUser(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie delete interdit ' . uniqid()
        );

        $this->client->loginUser($user);

        $this->client->request(
            'DELETE',
            '/admin/categorie/' . $categorie->getId(),
            [
                '_token' => 'anything',
            ]
        );

        $this->assertResponseRedirects('/access-denied');
    }

    public function testRemoveWithInvalidCsrfDoesNotDeleteCategory(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie mauvais csrf ' . uniqid()
        );

        $categorieId = $categorie->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'DELETE',
            '/admin/categorie/' . $categorieId,
            [
                '_token' => 'invalid-token',
            ]
        );

        $this->assertResponseRedirects('/admin/categorie');

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $categorie = $entityManager
            ->getRepository(Categories::class)
            ->find($categorieId);

        $this->assertNotNull($categorie);
        $this->assertFalse($categorie->isSoftDelete());
    }

    public function testRemoveRedirectsToReturnTo(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $categorie = $this->createCategorie(
            titre: 'Catégorie delete return ' . uniqid()
        );

        $categorieId = $categorie->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie'
        );

        $this->assertResponseIsSuccessful();

        $token = $this->getDeleteTokenFromCrawler(
            $crawler,
            $categorieId
        );

        $returnTo = '/admin/emission/';

        $this->client->request(
            'DELETE',
            '/admin/categorie/' . $categorieId
                . '?returnTo=' . urlencode($returnTo),
            [
                '_token' => $token,
            ]
        );

        $this->assertResponseRedirects($returnTo);
    }

    public function testCreateAddsCurrentUserWhenOnlyOldAnimatorIsSelected(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $editeur = $this->createEditeur();

        $oldAnimator = new InviteOldAnimateur();
        $oldAnimator
            ->setFirstName('Ancien')
            ->setLastName('Animateur ' . uniqid())
            ->setAncienanimateur(true);

        $this->entityManager->persist($oldAnimator);
        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/create'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter('form[name="categorie"]');

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $titre = 'Catégorie ancien animateur ' . uniqid();

        $form['categorie[titre]'] = $titre;
        $form['categorie[slug]'] = $this->uniqueCategorySlug();
        $form['categorie[editeur]'] = (string) $editeur->getId();
        $form['categorie[duree]'] = 60;
        $form['categorie[descriptif]'] = 'Description de test';
        $form['categorie[active]'] = true;

        /*
     * Aucun User sélectionné.
     * L'ancien animateur suffit pour satisfaire
     * validateAtLeastOneOwner().
     */
        $form['categorie[users]'] = [];

        $form['categorie[inviteOldAnimateurs]'] = [
            (string) $oldAnimator->getId(),
        ];

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $created = $entityManager
            ->getRepository(Categories::class)
            ->findOneBy([
                'titre' => $titre,
            ]);

        $this->assertNotNull($created);

        /*
     * Le formulaire n'avait aucun User.
     * C'est donc le contrôleur qui doit avoir ajouté
     * automatiquement l'utilisateur connecté.
     */
        $this->assertCount(1, $created->getUsers());

        $this->assertTrue(
            $created->getUsers()->exists(
                static fn($key, User $user): bool =>
                $user->getId() === $admin->getId()
            )
        );

        $this->assertCount(
            1,
            $created->getInviteOldAnimateurs()
        );

        $this->assertTrue(
            $created->getInviteOldAnimateurs()->exists(
                static fn($key, InviteOldAnimateur $invite): bool =>
                $invite->getId() === $oldAnimator->getId()
            )
        );
    }

    public function testEditCanDeleteThumbnail(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $editeur = $this->createEditeur();

        $categorie = $this->createCategorie(
            titre: 'Catégorie thumbnail ' . uniqid(),
            editeur: $editeur
        );

        $categorie
            ->addUser($admin)
            ->setThumbnail('ancienne-image.jpg');

        $this->entityManager->flush();

        $categorieId = $categorie->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorie/' . $categorieId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter('form[name="categorie"]');

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        /*
     * On conserve les données nécessaires
     * pour que le formulaire reste valide.
     */
        $form['categorie[titre]'] = $categorie->getTitre();
        $form['categorie[editeur]'] = (string) $editeur->getId();
        $form['categorie[duree]'] = 60;
        $form['categorie[descriptif]'] = 'Description de test';
        $form['categorie[active]'] = true;
        $form['categorie[users]'] = [
            (string) $admin->getId(),
        ];

        /*
     * Le slug est disabled en édition pour ROLE_ADMIN,
     * donc on ne le soumet pas.
     */

        $values = $form->getPhpValues();

        /*
     * delete_thumbnail n'appartient pas au CategorieType.
     * Le contrôleur le lit directement à la racine
     * de $request->request.
     */
        $values['delete_thumbnail'] = '1';

        $this->client->request(
            'POST',
            $form->getUri(),
            $values
        );

        $this->assertResponseRedirects();

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $updated = $entityManager
            ->getRepository(Categories::class)
            ->find($categorieId);

        $this->assertNotNull($updated);

        /*
     * C'est le résultat fonctionnel du bloc testé.
     */
        $this->assertNull($updated->getThumbnail());
    }

    private function createCategorie(
        ?string $titre = null,
        bool $softDelete = false,
        ?Editeur $editeur = null
    ): Categories {
        $categorie = new Categories();

        $categorie
            ->setTitre(
                $titre ?? 'Catégorie ' . uniqid()
            )
            ->setSlug(
                $this->uniqueCategorySlug()
            )
            ->setEditeur(
                $editeur ?? $this->createEditeur()
            )
            ->setDuree(60)
            ->setDescriptif('Description de test')
            ->setActive(true)
            ->setSoftDelete($softDelete)
            ->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($categorie);
        $this->entityManager->flush();

        return $categorie;
    }

    private function createEditeur(): Editeur
    {
        $editeur = new Editeur();

        $editeur
            ->setName('Éditeur test ' . uniqid())
            ->setMail(
                'editeur_' . uniqid() . '@example.com'
            )
            ->setPhone('0102030405')
            ->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);
        $this->entityManager->flush();

        return $editeur;
    }

    private function createUser(
        array $roles = ['ROLE_USER']
    ): User {
        $user = new User();

        $user
            ->setUsername(
                'user_' . uniqid()
            )
            ->setEmail(
                'user_' . uniqid() . '@example.com'
            )
            ->setPassword('fakehashedpassword')
            ->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function uniqueCategorySlug(): string
    {
        static $counter = 0;

        $counter++;

        $seed = crc32(
            uniqid((string) $counter, true)
        );

        $seed = abs($seed);

        $first = intdiv($seed, 26 * 26) % 26;
        $second = intdiv($seed, 26) % 26;
        $third = $seed % 26;

        return chr(65 + $first)
            . chr(65 + $second)
            . chr(65 + $third);
    }

    private function getDeleteTokenFromCrawler(
        \Symfony\Component\DomCrawler\Crawler $crawler,
        int $categorieId
    ): string {
        $expectedAction = '/admin/categorie/' . $categorieId;

        $form = $crawler
            ->filter('form')
            ->reduce(
                static function (
                    \Symfony\Component\DomCrawler\Crawler $node
                ) use ($expectedAction): bool {
                    $action = $node->attr('action');

                    if ($action === null) {
                        return false;
                    }

                    return str_contains(
                        $action,
                        $expectedAction
                    );
                }
            );

        $this->assertGreaterThan(
            0,
            $form->count(),
            sprintf(
                'Aucun formulaire de suppression trouvé pour la catégorie %d.',
                $categorieId
            )
        );

        $tokenInput = $form
            ->first()
            ->filter('input[name="_token"]');

        $this->assertGreaterThan(
            0,
            $tokenInput->count(),
            sprintf(
                'Aucun token CSRF trouvé pour la catégorie %d.',
                $categorieId
            )
        );

        $token = $tokenInput->attr('value');

        $this->assertNotNull($token);
        $this->assertNotSame('', $token);

        return $token;
    }
}

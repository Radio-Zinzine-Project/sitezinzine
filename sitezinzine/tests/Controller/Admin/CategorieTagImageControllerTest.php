<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Categories;
use App\Entity\CategorieTagImage;
use App\Entity\Editeur;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class CategorieTagImageControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (
            isset($this->entityManager)
            && $this->entityManager->getConnection()->isTransactionActive()
        ) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    /*
     * ============================================================
     * INDEX
     * ============================================================
     */

    public function testIndexIsAccessible(): void
    {
        $admin = $this->createAdmin();
        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/categorieTagImage/'
        );

        $this->assertResponseIsSuccessful();
    }

    public function testIndexDisplaysCategorieTagImages(): void
    {
        $admin = $this->createAdmin();
        $categorie = $this->createCategorie();

        $tagImage = $this->createCategorieTagImage(
            $categorie,
            2026
        );

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorieTagImage/'
        );

        $this->assertResponseIsSuccessful();

        $body = $crawler->filter('body')->text();

        $this->assertStringContainsString(
            $categorie->getTitre(),
            $body
        );

        $this->assertStringContainsString(
            (string) $tagImage->getAnnee(),
            $body
        );
    }

    /*
     * ============================================================
     * CREATE
     * ============================================================
     */

    public function testCreatePageIsAccessible(): void
    {
        $admin = $this->createAdmin();
        $this->createCategorie();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorieTagImage/create'
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter(
                'form[name="categorie_tag_image"]'
            )
        );
    }

    public function testCreateCategorieTagImage(): void
    {
        $admin = $this->createAdmin();
        $categorie = $this->createCategorie();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorieTagImage/create'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="categorie_tag_image"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $form['categorie_tag_image[categorie]'] =
            (string) $categorie->getId();

        $form['categorie_tag_image[annee]'] = 2026;

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/categorieTagImage/'
        );

        $created = $this->entityManager
            ->getRepository(CategorieTagImage::class)
            ->findOneBy([
                'categorie' => $categorie,
                'annee' => 2026,
            ]);

        $this->assertNotNull($created);

        $this->assertSame(
            2026,
            $created->getAnnee()
        );

        $this->assertSame(
            $categorie->getId(),
            $created->getCategorie()?->getId()
        );
    }

    public function testCreateDuplicateCategorieAndYearDoesNotCreateSecondEntry(): void
    {
        $admin = $this->createAdmin();
        $categorie = $this->createCategorie();

        $this->createCategorieTagImage(
            $categorie,
            2026
        );

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/categorieTagImage/create'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="categorie_tag_image"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $form['categorie_tag_image[categorie]'] =
            (string) $categorie->getId();

        $form['categorie_tag_image[annee]'] = 2026;

        $this->client->submit($form);

        /*
         * Le contrôleur intercepte la
         * UniqueConstraintViolationException
         * puis réaffiche le formulaire.
         */
        $this->assertResponseIsSuccessful();

        $count = $this->entityManager
            ->getRepository(CategorieTagImage::class)
            ->count([
                'categorie' => $categorie,
                'annee' => 2026,
            ]);

        $this->assertSame(1, $count);
    }

    /*
     * ============================================================
     * EDIT
     * ============================================================
     */

    public function testEditPageIsAccessible(): void
    {
        $admin = $this->createAdmin();
        $categorie = $this->createCategorie();

        $tagImage = $this->createCategorieTagImage(
            $categorie,
            2025
        );

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            sprintf(
                '/admin/categorieTagImage/%d/edit',
                $tagImage->getId()
            )
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter(
                'form[name="categorie_tag_image"]'
            )
        );
    }

    public function testEditCategorieTagImage(): void
    {
        $admin = $this->createAdmin();
        $categorie = $this->createCategorie();

        $tagImage = $this->createCategorieTagImage(
            $categorie,
            2025
        );

        $tagImageId = $tagImage->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            sprintf(
                '/admin/categorieTagImage/%d/edit',
                $tagImageId
            )
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="categorie_tag_image"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $form['categorie_tag_image[categorie]'] =
            (string) $categorie->getId();

        $form['categorie_tag_image[annee]'] = 2027;

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/categorieTagImage/'
        );

        $updated = $this->entityManager
            ->getRepository(CategorieTagImage::class)
            ->find($tagImageId);

        $this->assertNotNull($updated);

        $this->assertSame(
            2027,
            $updated->getAnnee()
        );
    }

    public function testEditDuplicateCategorieAndYearDisplaysFormAgain(): void
    {
        $admin = $this->createAdmin();

        $categorie1 = $this->createCategorie(
            'Catégorie collision 1 ' . uniqid()
        );

        $categorie2 = $this->createCategorie(
            'Catégorie collision 2 ' . uniqid()
        );

        $this->createCategorieTagImage(
            $categorie1,
            2026
        );

        $tagImageToEdit = $this->createCategorieTagImage(
            $categorie2,
            2026
        );

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            sprintf(
                '/admin/categorieTagImage/%d/edit',
                $tagImageToEdit->getId()
            )
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="categorie_tag_image"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        /*
         * On tente de transformer la seconde ligne
         * en doublon exact de la première.
         */
        $form['categorie_tag_image[categorie]'] =
            (string) $categorie1->getId();

        $form['categorie_tag_image[annee]'] = 2026;

        $this->client->submit($form);

        /*
         * Le contrôleur intercepte l'exception
         * et réaffiche le formulaire.
         */
        $this->assertResponseIsSuccessful();
    }

    public function testEditUnknownCategorieTagImageReturns404(): void
    {
        $admin = $this->createAdmin();
        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/categorieTagImage/999999999/edit'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    /*
     * ============================================================
     * DELETE
     * ============================================================
     */

    public function testDeleteCategorieTagImage(): void
    {
        $admin = $this->createAdmin();
        $categorie = $this->createCategorie();

        $tagImage = $this->createCategorieTagImage(
            $categorie,
            2026
        );

        $tagImageId = $tagImage->getId();

        $this->client->loginUser($admin);

        /*
         * On récupère le vrai token CSRF depuis
         * le formulaire présent sur l'index.
         */
        $crawler = $this->client->request(
            'GET',
            '/admin/categorieTagImage/'
        );

        $this->assertResponseIsSuccessful();

        $token = $this->getDeleteTokenFromCrawler(
            $crawler,
            $tagImageId
        );

        $this->client->request(
            'POST',
            sprintf(
                '/admin/categorieTagImage/%d/delete',
                $tagImageId
            ),
            [
                '_token' => $token,
            ]
        );

        $this->assertResponseRedirects(
            '/admin/categorieTagImage/'
        );

        $deleted = $this->entityManager
            ->getRepository(CategorieTagImage::class)
            ->find($tagImageId);

        $this->assertNull($deleted);
    }

    public function testDeleteWithInvalidCsrfDoesNotDeleteCategorieTagImage(): void
    {
        $admin = $this->createAdmin();
        $categorie = $this->createCategorie();

        $tagImage = $this->createCategorieTagImage(
            $categorie,
            2026
        );

        $tagImageId = $tagImage->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'POST',
            sprintf(
                '/admin/categorieTagImage/%d/delete',
                $tagImageId
            ),
            [
                '_token' => 'invalid-token',
            ]
        );

        $this->assertResponseRedirects(
            '/admin/categorieTagImage/'
        );

        $existing = $this->entityManager
            ->getRepository(CategorieTagImage::class)
            ->find($tagImageId);

        $this->assertNotNull($existing);
    }

    /*
     * ============================================================
     * HELPERS
     * ============================================================
     */

    private function createAdmin(): User
    {
        return $this->createUser([
            'ROLE_ADMIN',
            'ROLE_SUPER_ADMIN',
        ]);
    }

    private function createUser(
        array $roles = ['ROLE_USER']
    ): User {
        $unique = uniqid('', true);

        $user = new User();

        $user
            ->setUsername(
                'categorie_tag_image_' . $unique
            )
            ->setEmail(
                'categorie_tag_image_' . $unique . '@example.com'
            )
            ->setPassword('fakehashedpassword')
            ->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createEditeur(): Editeur
    {
        $unique = uniqid('', true);

        $editeur = new Editeur();

        $editeur
            ->setName(
                'Éditeur tag image ' . $unique
            )
            ->setMail(
                'editeur_tag_' . $unique . '@example.com'
            )
            ->setPhone('0102030405')
            ->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);
        $this->entityManager->flush();

        return $editeur;
    }

    private function createCategorie(
        ?string $titre = null
    ): Categories {
        $categorie = new Categories();

        $categorie
            ->setTitre(
                $titre
                ?? 'Catégorie tag image ' . uniqid()
            )
            ->setSlug(
                $this->uniqueCategorySlug()
            )
            ->setEditeur(
                $this->createEditeur()
            )
            ->setDuree(60)
            ->setDescriptif(
                'Description catégorie tag image'
            )
            ->setActive(true)
            ->setSoftDelete(false)
            ->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($categorie);
        $this->entityManager->flush();

        return $categorie;
    }

    private function createCategorieTagImage(
        Categories $categorie,
        int $annee
    ): CategorieTagImage {
        $tagImage = new CategorieTagImage();

        $tagImage
            ->setCategorie($categorie)
            ->setAnnee($annee)
            ->setUpdatedAt(
                new \DateTimeImmutable()
            );

        $this->entityManager->persist($tagImage);
        $this->entityManager->flush();

        return $tagImage;
    }

    private function uniqueCategorySlug(): string
    {
        $value = strtoupper(
            base_convert(
                sprintf(
                    '%u',
                    crc32(uniqid('', true))
                ),
                10,
                36
            )
        );

        $value = preg_replace(
            '/[^A-Z]/',
            '',
            $value
        );

        $value = str_pad(
            $value ?: 'ABC',
            3,
            'X'
        );

        return substr($value, 0, 3);
    }

    private function getDeleteTokenFromCrawler(
        Crawler $crawler,
        int $tagImageId
    ): string {
        $expectedAction = sprintf(
            '/admin/categorieTagImage/%d/delete',
            $tagImageId
        );

        $formNode = $crawler
            ->filter('form')
            ->reduce(
                static function (
                    Crawler $node
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
            $formNode->count(),
            sprintf(
                'Aucun formulaire de suppression trouvé pour CategorieTagImage %d.',
                $tagImageId
            )
        );

        $tokenInput = $formNode
            ->first()
            ->filter('input[name="_token"]');

        $this->assertGreaterThan(
            0,
            $tokenInput->count(),
            sprintf(
                'Aucun token CSRF trouvé pour CategorieTagImage %d.',
                $tagImageId
            )
        );

        $token = $tokenInput->attr('value');

        $this->assertNotNull($token);
        $this->assertNotSame('', $token);

        return $token;
    }
}
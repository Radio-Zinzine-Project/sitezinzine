<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Editeur;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EditeurControllerTest extends WebTestCase
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
        try {
            if (
                isset($this->entityManager)
                && $this->entityManager->getConnection()->isTransactionActive()
            ) {
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
        $this->client->request(
            'GET',
            '/admin/editeur/'
        );

        $this->assertResponseRedirects();
    }

    public function testAdminWithoutSuperAdminCannotAccessIndex(): void
    {
        $admin = $this->createUser([
            'ROLE_ADMIN',
        ]);

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/editeur/'
        );

        $this->assertResponseRedirects(
            '/access-denied'
        );
    }

    public function testSuperAdminCanAccessIndex(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/editeur/'
        );

        $this->assertResponseIsSuccessful();
    }

    public function testIndexDisplaysEditors(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $editeur = $this->createEditeur();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/editeur/'
        );

        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString(
            $editeur->getName(),
            $crawler->filter('body')->text()
        );
    }

    public function testShowEditor(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $editeur = $this->createEditeur();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/editeur/' . $editeur->getId()
        );

        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString(
            $editeur->getName(),
            $crawler->filter('body')->text()
        );
    }

    public function testShowEditorWithPageAndInitiale(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $editeur = $this->createEditeur();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/editeur/' . $editeur->getId() . '?page=2&initiale=b'
        );

        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString(
            $editeur->getName(),
            $crawler->filter('body')->text()
        );
    }

    public function testShowUnknownEditorReturns404(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/editeur/999999999'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEditPage(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $editeur = $this->createEditeur();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/editeur/' . $editeur->getId() . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter('form[name="editeur"]')
        );
    }

    public function testEditEditor(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $editeur = $this->createEditeur();

        $editeurId = $editeur->getId();
        $oldUpdateAt = $editeur->getUpdateAt();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/editeur/' . $editeurId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="editeur"]'
        );

        $this->assertCount(
            1,
            $formNode
        );

        $form = $formNode->form();

        $form['editeur[name]'] =
            'Éditeur modifié ' . uniqid();

        $form['editeur[mail]'] =
            'modifie_' . uniqid() . '@example.com';

        $form['editeur[phone]'] =
            '0203040506';

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/editeur/'
        );

        $updated = $this->entityManager
            ->getRepository(Editeur::class)
            ->find($editeurId);

        $this->assertNotNull($updated);

        $this->assertStringStartsWith(
            'Éditeur modifié ',
            $updated->getName()
        );

        $this->assertSame(
            '0203040506',
            $updated->getPhone()
        );

        $this->assertGreaterThanOrEqual(
            $oldUpdateAt->getTimestamp(),
            $updated->getUpdateAt()->getTimestamp()
        );
    }

    public function testEditUnknownEditorReturns404(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/editeur/999999999/edit'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCreatePage(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/editeur/create'
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter('form[name="editeur"]')
        );
    }

    public function testCreateEditor(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/editeur/create'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="editeur"]'
        );

        $this->assertCount(
            1,
            $formNode
        );

        $form = $formNode->form();

        $name = 'Nouvel éditeur test ' . uniqid();

        $form['editeur[name]'] = $name;
        $form['editeur[mail]'] =
            'nouvel_editeur_' . uniqid() . '@example.com';
        $form['editeur[phone]'] =
            '0304050607';

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/editeur/'
        );

        $created = $this->entityManager
            ->getRepository(Editeur::class)
            ->findOneBy([
                'name' => $name,
            ]);

        $this->assertNotNull($created);

        $this->assertSame(
            '0304050607',
            $created->getPhone()
        );

        $this->assertNotNull(
            $created->getUpdateAt()
        );
    }

    public function testDeleteEditor(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $editeur = $this->createEditeur();

        $editeurId = $editeur->getId();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'DELETE',
            '/admin/editeur/' . $editeurId
        );

        $this->assertResponseRedirects(
            '/admin/editeur/'
        );

        $deleted = $this->entityManager
            ->getRepository(Editeur::class)
            ->find($editeurId);

        $this->assertNull($deleted);
    }

    public function testDeleteUnknownEditorReturns404(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'DELETE',
            '/admin/editeur/999999999'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    private function createSuperAdmin(): User
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
                'editeur_test_' . $unique
            )
            ->setEmail(
                'editeur_test_' . $unique . '@example.com'
            )
            ->setPassword(
                'fakehashedpassword'
            )
            ->setRoles(
                $roles
            );

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
                'Éditeur test ' . $unique
            )
            ->setMail(
                'editeur_' . $unique . '@example.com'
            )
            ->setPhone(
                '0102030405'
            )
            ->setUpdateAt(
                new \DateTime('2026-01-01 10:00:00')
            );

        $this->entityManager->persist($editeur);
        $this->entityManager->flush();

        return $editeur;
    }
}
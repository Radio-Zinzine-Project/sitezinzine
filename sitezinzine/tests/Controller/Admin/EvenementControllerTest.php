<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Evenement;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EvenementControllerTest extends WebTestCase
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
     * SECURITY
     * ============================================================
     */

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseRedirects();
    }

    public function testUserWithoutEditorRoleCannotAccessIndex(): void
    {
        $user = $this->createUser([
            'ROLE_USER',
        ]);

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseRedirects(
            '/access-denied'
        );
    }

    public function testEditorCanAccessIndex(): void
    {
        $editor = $this->createEditor();

        $this->client->loginUser($editor);

        $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();
    }

    /*
     * ============================================================
     * INDEX
     * ============================================================
     */

    public function testIndexDisplaysEvents(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor
        );

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString(
            $evenement->getTitre(),
            $crawler->filter('body')->text()
        );
    }

    /*
     * ============================================================
     * CREATE
     * ============================================================
     */

    public function testCreatePage(): void
    {
        $editor = $this->createEditor();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/create'
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter('form[name="evenement"]')
        );
    }

    public function testCreateEvent(): void
    {
        $editor = $this->createEditor();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/create'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="evenement"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $titre = 'Évènement créé ' . uniqid();

        $form['evenement[titre]'] = $titre;
        $form['evenement[organisateur]'] = 'Association test';
        $form['evenement[ville]'] = 'Marseille';
        $form['evenement[departement]'] = '13';
        $form['evenement[adresse]'] = '10 rue du Test';
        $form['evenement[dateDebut]'] = '2026-11-01';
        $form['evenement[dateFin]'] = '2026-11-02';
        $form['evenement[horaire]'] = '18h';
        $form['evenement[prix]'] = '5 euros';
        $form['evenement[presentation]'] = 'Présentation création';
        $form['evenement[contact]'] = 'creation@example.com';
        $form['evenement[type]'] = 'emission';

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/evenement/'
        );

        $created = $this->entityManager
            ->getRepository(Evenement::class)
            ->findOneBy([
                'titre' => $titre,
            ]);

        $this->assertNotNull($created);

        $this->assertFalse(
            $created->isSoftDelete()
        );

        $this->assertFalse(
            $created->isValid()
        );

        $this->assertNotNull(
            $created->getUser()
        );

        $this->assertSame(
            $editor->getId(),
            $created->getUser()?->getId()
        );

        $this->assertNotNull(
            $created->getUpdateAt()
        );
    }

    /*
     * ============================================================
     * EDIT
     * ============================================================
     */

    public function testEditPage(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor
        );

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/' . $evenement->getId() . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter('form[name="evenement"]')
        );
    }

    public function testEditEvent(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/' . $evenementId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="evenement"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $titre = 'Évènement modifié ' . uniqid();

        $form['evenement[titre]'] = $titre;
        $form['evenement[organisateur]'] = 'Organisateur modifié';
        $form['evenement[ville]'] = 'Avignon';
        $form['evenement[departement]'] = '84';
        $form['evenement[adresse]'] = '20 rue du Test';
        $form['evenement[dateDebut]'] = '2026-12-01';
        $form['evenement[dateFin]'] = '2026-12-02';
        $form['evenement[horaire]'] = '19h';
        $form['evenement[prix]'] = 'Libre';
        $form['evenement[presentation]'] = 'Présentation modifiée';
        $form['evenement[contact]'] = 'modifie@example.com';
        $form['evenement[type]'] = 'table ronde';

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/evenement/'
        );

        $updated = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($updated);

        $this->assertSame(
            $titre,
            $updated->getTitre()
        );

        $this->assertSame(
            'Table Ronde',
            $updated->getType()
        );

        $this->assertNotNull(
            $updated->getUpdateAt()
        );
    }

    public function testEditAssignsCurrentUserWhenEventHasNoUser(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: null
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/' . $evenementId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->filter('form[name="evenement"]')
            ->form();

        $form['evenement[titre]'] =
            $evenement->getTitre();

        $form['evenement[dateDebut]'] =
            '2026-10-01';

        $form['evenement[dateFin]'] =
            '2026-10-02';

        $form['evenement[type]'] =
            'fete';

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/evenement/'
        );

        $updated = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($updated);

        $this->assertNotNull(
            $updated->getUser()
        );

        $this->assertSame(
            $editor->getId(),
            $updated->getUser()?->getId()
        );
    }

    public function testEditCustomTypeStoresOtherType(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor,
            type: 'Fete'
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/' . $evenementId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->filter('form[name="evenement"]')
            ->form();

        $form['evenement[titre]'] =
            $evenement->getTitre();

        $form['evenement[dateDebut]'] =
            '2026-10-01';

        $form['evenement[dateFin]'] =
            '2026-10-02';

        $form['evenement[type]'] =
            'autre';

        $form['evenement[autreType]'] =
            'Concert';

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/evenement/'
        );

        $updated = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($updated);

        $this->assertSame(
            'Concert',
            $updated->getType()
        );
    }

    public function testEditUnknownEventReturns404(): void
    {
        $editor = $this->createEditor();

        $this->client->loginUser($editor);

        $this->client->request(
            'GET',
            '/admin/evenement/999999999/edit'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    /*
     * ============================================================
     * SHOW
     * ============================================================
     */

    public function testShowEvent(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor
        );

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/' . $evenement->getId()
        );

        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString(
            $evenement->getTitre(),
            $crawler->filter('body')->text()
        );
    }

    public function testShowUnknownEventReturns404(): void
    {
        $editor = $this->createEditor();

        $this->client->loginUser($editor);

        $this->client->request(
            'GET',
            '/admin/evenement/999999999'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    /*
     * ============================================================
     * SOFT DELETE
     * ============================================================
     */

    public function testSoftDeleteEvent(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->filter(
                sprintf(
                    'form[action="/admin/evenement/%d"]',
                    $evenementId
                )
            )
            ->form();

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/evenement/'
        );

        $deleted = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($deleted);

        $this->assertTrue(
            $deleted->isSoftDelete()
        );
    }

    public function testSoftDeleteRejectsInvalidCsrfToken(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();

        $this->client->request(
            'DELETE',
            '/admin/evenement/' . $evenementId,
            [
                '_token' => 'invalid-token',
            ]
        );

        $this->assertResponseRedirects(
            '/access-denied'
        );

        $storedEvent = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($storedEvent);

        $this->assertFalse(
            $storedEvent->isSoftDelete()
        );
    }

    /*
     * ============================================================
     * VALID / UNVALID
     * ============================================================
     */

    public function testValidateEvent(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor,
            valid: false
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->filter(
                sprintf(
                    'form[action="/admin/evenement/%d/valid"]',
                    $evenementId
                )
            )
            ->form();

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/evenement/'
        );

        $validated = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($validated);

        $this->assertTrue(
            $validated->isValid()
        );
    }

    public function testValidateRejectsInvalidCsrfToken(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor,
            valid: false
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/admin/evenement/' . $evenementId . '/valid',
            [
                '_token' => 'invalid-token',
            ]
        );

        $this->assertResponseRedirects(
            '/access-denied'
        );

        $storedEvent = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($storedEvent);

        $this->assertFalse(
            $storedEvent->isValid()
        );
    }

    public function testUnvalidateEvent(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor,
            valid: true
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $crawler = $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->filter(
                sprintf(
                    'form[action="/admin/evenement/%d/unvalid"]',
                    $evenementId
                )
            )
            ->form();

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/evenement/'
        );

        $unvalidated = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($unvalidated);

        $this->assertFalse(
            $unvalidated->isValid()
        );
    }

    public function testUnvalidateRejectsInvalidCsrfToken(): void
    {
        $editor = $this->createEditor();

        $evenement = $this->createEvenement(
            user: $editor,
            valid: true
        );

        $evenementId = $evenement->getId();

        $this->client->loginUser($editor);

        $this->client->request(
            'GET',
            '/admin/evenement/'
        );

        $this->assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/admin/evenement/' . $evenementId . '/unvalid',
            [
                '_token' => 'invalid-token',
            ]
        );

        $this->assertResponseRedirects(
            '/access-denied'
        );

        $storedEvent = $this->entityManager
            ->getRepository(Evenement::class)
            ->find($evenementId);

        $this->assertNotNull($storedEvent);

        $this->assertTrue(
            $storedEvent->isValid()
        );
    }

    /*
     * ============================================================
     * HELPERS
     * ============================================================
     */

    private function createEditor(): User
    {
        return $this->createUser([
            'ROLE_EDITOR',
        ]);
    }

    private function createUser(
        array $roles = ['ROLE_USER']
    ): User {
        $unique = uniqid('', true);

        $user = new User();

        $user
            ->setUsername(
                'evenement_test_' . $unique
            )
            ->setEmail(
                'evenement_test_' . $unique . '@example.com'
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

    private function createEvenement(
        ?User $user = null,
        string $type = 'Fete',
        bool $valid = false
    ): Evenement {
        $unique = uniqid('', true);

        $evenement = new Evenement();

        $evenement
            ->setTitre(
                'Évènement test ' . $unique
            )
            ->setOrganisateur(
                'Organisateur test'
            )
            ->setVille(
                'Nantes'
            )
            ->setDepartement(
                '44'
            )
            ->setAdresse(
                '1 rue du Test'
            )
            ->setDateDebut(
                new \DateTime('2026-09-20 10:00:00')
            )
            ->setDateFin(
                new \DateTime('2026-09-21 18:00:00')
            )
            ->setHoraire(
                '10h - 18h'
            )
            ->setPrix(
                'Gratuit'
            )
            ->setPresentation(
                'Présentation événement test'
            )
            ->setContact(
                'contact@example.com'
            )
            ->setType(
                $type
            )
            ->setValid(
                $valid
            )
            ->setUpdateAt(
                new \DateTime('2026-09-01 10:00:00')
            )
            ->setSoftDelete(
                false
            )
            ->setUser(
                $user
            );

        $this->entityManager->persist($evenement);
        $this->entityManager->flush();

        return $evenement;
    }
}

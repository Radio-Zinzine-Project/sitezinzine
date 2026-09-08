<?php

namespace App\Tests\Controller;

use App\Entity\Evenement;
use App\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $this->entityManager->beginTransaction();

        $this->createStaticPages();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        parent::tearDown();
    }

    public function testIndex(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertPageTitleContains('Radio Zinzine, radio libre !');

        $this->assertSelectorExists('div.titrelast');
        $this->assertSelectorExists('div.bodyondes');
        $this->assertSelectorExists('div.vagues');
        $this->assertSelectorExists('article.evenements');
    }

    public function testShowEvenement(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setTitre('Test Event')
            ->setOrganisateur('Test Organisateur')
            ->setVille('Test Ville')
            ->setDepartement('01')
            ->setAdresse('123 Test Street')
            ->setDateDebut(new \DateTime('now'))
            ->setDateFin(new \DateTime('tomorrow'))
            ->setHoraire('10:00 AM')
            ->setPrix('Free')
            ->setPresentation('This is a test event.')
            ->setContact('contact@test.com')
            ->setType('Public')
            ->setValid(true)
            ->setUpdateAt(new \DateTime('now'))
            ->setSoftDelete(false);

        $this->entityManager->persist($evenement);
        $this->entityManager->flush();

        $this->client->request('GET', '/' . $evenement->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.evenement');
        $this->assertSelectorTextContains('h1.evenement-titre', 'Test Event');
    }

    public function testRadio(): void
    {
        $this->client->request('GET', '/radio');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.page-card');
        $this->assertSelectorTextContains(
            'h1.page-title',
            'Page de test Radio'
        );
    }

    public function testProgramme(): void
    {
        $this->client->request('GET', '/programme');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodyprogramme');
    }

    public function testInfos(): void
    {
        $this->client->request('GET', '/infos');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodyinfos');
    }

    public function testZone(): void
    {
        $this->client->request('GET', '/zone');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodyzoneecoute');
    }

    public function testAide(): void
    {
        $this->client->request('GET', '/aide');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodyaide');
    }

    public function testAmis(): void
    {
        $this->client->request('GET', '/amis');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodyamis');
    }

    public function testMentions(): void
    {
        $this->client->request('GET', '/mentions');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodymentions');
    }

    public function testContacts(): void
    {
        $this->client->request('GET', '/contacts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodycontacts');
    }

    public function testDon(): void
    {
        $this->client->request('GET', '/don');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodydon');
    }

    public function testNewsletter(): void
    {
        $this->client->request('GET', '/newsletter');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.bodynewsletter');
    }

    private function createStaticPages(): void
    {
        $pages = [
            'radio',
            'zone',
            'aide',
            'amis',
            'mentions',
            'contacts',
            'don',
            'newsletter',
        ];

        foreach ($pages as $slug) {
            $page = new Page();

            $page
                ->setSlug($slug)
                ->setTitle('Page de test ' . ucfirst($slug))
                ->setContent('<p>Contenu de test pour ' . $slug . '</p>');

            $this->entityManager->persist($page);
        }

        $this->entityManager->flush();
    }
}

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
        parent::setUp();

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

        // partials/lastEmissions.html.twig
        $this->assertSelectorExists('div.bodylast');

        // partials/ondes.html.twig
        $this->assertSelectorExists('div.ondes-section');

        // partials/vagues.html.twig
        $this->assertSelectorExists('div.vagues');

        // partials/evenement.html.twig
        $this->assertSelectorExists('div.evenements-section');
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

        $this->client->request(
            'GET',
            '/' . $evenement->getId()
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('div.evenement');
        $this->assertSelectorTextContains(
            'h1.evenement-titre',
            'Test Event'
        );
    }

    public function testRadio(): void
    {
        $this->assertStaticPage('/radio', 'Radio');
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
        $this->assertStaticPage('/zone', 'Zone');
    }

    public function testAide(): void
    {
        $this->assertStaticPage('/aide', 'Aide');
    }

    public function testAmis(): void
    {
        $this->assertStaticPage('/amis', 'Amis');
    }

    public function testMentions(): void
    {
        $this->assertStaticPage('/mentions', 'Mentions');
    }

    public function testContacts(): void
    {
        $this->assertStaticPage('/contacts', 'Contacts');
    }

    public function testDon(): void
    {
        $this->assertStaticPage('/don', 'Don');
    }

    public function testNewsletter(): void
    {
        $this->assertStaticPage('/newsletter', 'Newsletter');
    }

    private function assertStaticPage(
        string $url,
        string $title
    ): void {
        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists(
            'div.page-card'
        );

        $this->assertSelectorTextContains(
            'h1.page-title',
            'Page de test ' . $title
        );
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
                ->setTitle(
                    'Page de test ' . ucfirst($slug)
                )
                ->setContent(
                    '<p>Contenu de test pour '
                        . $slug
                        . '</p>'
                );

            $this->entityManager->persist($page);
        }

        $this->entityManager->flush();
    }

    /**
     * @dataProvider staticPageNotFoundProvider
     */
    public function testStaticPageReturns404WhenPageDoesNotExist(
        string $slug,
        string $url
    ): void {
        $page = $this->entityManager
            ->getRepository(Page::class)
            ->findOneBy(['slug' => $slug]);

        $this->assertNotNull($page);

        $this->entityManager->remove($page);
        $this->entityManager->flush();

        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(404);
    }

    public static function staticPageNotFoundProvider(): array
    {
        return [
            'radio' => ['radio', '/radio'],
            'zone' => ['zone', '/zone'],
            'aide' => ['aide', '/aide'],
            'amis' => ['amis', '/amis'],
            'mentions' => ['mentions', '/mentions'],
            'contacts' => ['contacts', '/contacts'],
            'don' => ['don', '/don'],
            'newsletter' => ['newsletter', '/newsletter'],
        ];
    }
}

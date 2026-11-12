<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Diffusion;
use App\Entity\Emission;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class DiffusionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        /*
         * Certains tests font plusieurs requêtes HTTP
         * (GET du formulaire puis POST).
         * On conserve donc le même kernel pendant le test.
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

    /*
     * ============================================================
     * SÉCURITÉ
     * ============================================================
     */

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request(
            'GET',
            '/admin/diffusion/'
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
            '/admin/diffusion/'
        );

        /*
         * L'application redirige les accès refusés
         * vers cette route.
         */
        $this->assertResponseRedirects('/access-denied');
    }

    public function testSuperAdminCanAccessIndex(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/diffusion/'
        );

        $this->assertResponseIsSuccessful();
    }

    /*
     * ============================================================
     * INDEX
     * ============================================================
     */

    public function testIndexDisplaysDiffusions(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $emission = $this->createEmission();

        $diffusion = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-09 14:30:00'),
            1
        );

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/diffusion/'
        );

        $this->assertResponseIsSuccessful();

        /*
         * On évite de dépendre du CSS exact du template.
         * L'identifiant est une donnée suffisamment stable
         * pour vérifier que la diffusion est présente.
         */
        $this->assertStringContainsString(
            (string) $diffusion->getId(),
            $crawler->filter('body')->text()
        );
    }

    /*
     * ============================================================
     * SHOW
     * ============================================================
     */

    public function testShowDiffusion(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $emission = $this->createEmission();

        $diffusion = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-10 16:15:00'),
            2
        );

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/diffusion/' . $diffusion->getId()
        );

        $this->assertResponseIsSuccessful();
    }

    public function testShowUnknownDiffusionReturns404(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/diffusion/999999999'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    /*
     * ============================================================
     * CREATE
     * ============================================================
     */

    public function testCreatePage(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->createEmission();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/diffusion/create'
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter('form[name="diffusion"]')
        );
    }

    public function testCreateDiffusion(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $emission = $this->createEmission();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/diffusion/create'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="diffusion"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $form['diffusion[horaireDiffusion]'] =
            '2026-09-15T14:30';

        $form['diffusion[nombreDiffusion]'] = 3;

        $form['diffusion[emission]'] =
            (string) $emission->getId();

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/diffusion/',
            303
        );

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $created = $entityManager
            ->getRepository(Diffusion::class)
            ->findOneBy([
                'emission' => $emission,
                'nombreDiffusion' => 3,
            ]);

        $this->assertNotNull($created);

        $this->assertSame(
            '2026-09-15 14:30',
            $created
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i')
        );
    }

    /*
     * ============================================================
     * EDIT
     * ============================================================
     */

    public function testEditPage(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $emission = $this->createEmission();

        $diffusion = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-20 10:00:00'),
            1
        );

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/diffusion/'
                . $diffusion->getId()
                . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $this->assertCount(
            1,
            $crawler->filter('form[name="diffusion"]')
        );
    }

    public function testEditDiffusion(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $emission = $this->createEmission();

        $diffusion = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-20 10:00:00'),
            1
        );

        $diffusionId = $diffusion->getId();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/diffusion/'
                . $diffusionId
                . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $formNode = $crawler->filter(
            'form[name="diffusion"]'
        );

        $this->assertCount(1, $formNode);

        $form = $formNode->form();

        $form['diffusion[horaireDiffusion]'] =
            '2026-09-21T18:45';

        $form['diffusion[nombreDiffusion]'] = 4;

        $form['diffusion[emission]'] =
            (string) $emission->getId();

        $this->client->submit($form);

        $this->assertResponseRedirects(
            '/admin/diffusion/',
            303
        );

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $updated = $entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        $this->assertNotNull($updated);

        $this->assertSame(
            4,
            $updated->getNombreDiffusion()
        );

        $this->assertSame(
            '2026-09-21 18:45',
            $updated
                ->getHoraireDiffusion()
                ?->format('Y-m-d H:i')
        );
    }

    public function testEditUnknownDiffusionReturns404(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/diffusion/999999999/edit'
        );

        $this->assertResponseStatusCodeSame(404);
    }

    /*
     * ============================================================
     * DELETE
     * ============================================================
     */

    public function testDeleteDiffusion(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $emission = $this->createEmission();

        $diffusion = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-25 12:00:00'),
            1
        );

        $diffusionId = $diffusion->getId();

        $this->client->loginUser($superAdmin);

        /*
         * On passe d'abord par la page show afin d'obtenir
         * le véritable token CSRF généré par l'application.
         */
        $crawler = $this->client->request(
            'GET',
            '/admin/diffusion/' . $diffusionId
        );

        $this->assertResponseIsSuccessful();

        $token = $this->getDeleteTokenFromCrawler(
            $crawler,
            $diffusionId
        );

        $this->client->request(
            'POST',
            '/admin/diffusion/' . $diffusionId,
            [
                '_token' => $token,
            ]
        );

        $this->assertResponseRedirects(
            '/admin/diffusion/',
            303
        );

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $deleted = $entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        $this->assertNull($deleted);
    }

    public function testDeleteWithInvalidCsrfDoesNotDeleteDiffusion(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $emission = $this->createEmission();

        $diffusion = $this->createDiffusion(
            $emission,
            new \DateTime('2026-09-26 12:00:00'),
            1
        );

        $diffusionId = $diffusion->getId();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'POST',
            '/admin/diffusion/' . $diffusionId,
            [
                '_token' => 'invalid-token',
            ]
        );

        $this->assertResponseRedirects(
            '/admin/diffusion/',
            303
        );

        $entityManager = static::getContainer()
            ->get('doctrine')
            ->getManager();

        $existing = $entityManager
            ->getRepository(Diffusion::class)
            ->find($diffusionId);

        $this->assertNotNull($existing);
    }

    /*
     * ============================================================
     * HELPERS
     * ============================================================
     */

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
                'diffusion_test_' . $unique
            )
            ->setEmail(
                'diffusion_' . $unique . '@example.com'
            )
            ->setPassword('fakehashedpassword')
            ->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createEmission(): Emission
    {
        $unique = uniqid('', true);

        $emission = new Emission();

        $emission
            ->setTitre(
                'Émission diffusion test ' . $unique
            )
            ->setKeyword('test')
            ->setDatepub(
                new \DateTime('2026-09-01 12:00:00')
            )
            ->setRef(null)
            ->setDuree(60)
            ->setDescriptif(
                'Description émission diffusion test'
            )
            ->setIsAutoGenerated(false)
            ->setIsPendingCompletion(false);

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        return $emission;
    }

    private function createDiffusion(
        Emission $emission,
        \DateTimeInterface $startsAt,
        int $nombreDiffusion = 1
    ): Diffusion {
        $diffusion = new Diffusion();

        $diffusion
            ->setEmission($emission)
            ->setHoraireDiffusion($startsAt)
            ->setNombreDiffusion($nombreDiffusion)
            ->setDurationMinutes(60);

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        return $diffusion;
    }

    private function getDeleteTokenFromCrawler(
        Crawler $crawler,
        int $diffusionId
    ): string {
        $expectedAction =
            '/admin/diffusion/' . $diffusionId;

        $form = $crawler
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
            $form->count(),
            sprintf(
                'Aucun formulaire de suppression trouvé pour la diffusion %d.',
                $diffusionId
            )
        );

        $tokenInput = $form
            ->first()
            ->filter('input[name="_token"]');

        $this->assertGreaterThan(
            0,
            $tokenInput->count(),
            sprintf(
                'Aucun token CSRF trouvé pour la diffusion %d.',
                $diffusionId
            )
        );

        $token = $tokenInput->attr('value');

        $this->assertNotNull($token);
        $this->assertNotSame('', $token);

        return $token;
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Categories;
use App\Entity\Emission;
use App\Entity\Theme;
use App\Entity\User;
use App\Entity\Editeur;
use App\Entity\Diffusion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class EmissionControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        $this->entityManager = static::getContainer()
            ->get(EntityManagerInterface::class);
    }

    public function testIndexPageIsSecured(): void
    {
        $this->client->request('GET', '/admin/emission/');

        $this->assertResponseRedirects('/login');
    }

    public function testIndexWithAdminUser(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/emission/');

        $this->assertResponseIsSuccessful();
    }

    public function testEditEmissionWithNullRef(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Émission ref null ' . uniqid()
        );

        $emission->setRef(null);
        $emission->addUser($admin);

        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/' . $emissionId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sauvegarder')->form([
            'emission[titre]' => $emission->getTitre(),
            'emission[keyword]' => 'test-ref-null',
            'emission[theme]' => (string) $theme->getId(),
            'emission[categorie]' => (string) $categorie->getId(),
            'emission[duree]' => 60,
            'emission[descriptif]' => 'Description test',
            'emission[url]' => '',
            'emission[ref]' => '',
            'emission[users]' => [(string) $admin->getId()],
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();

        $updatedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($updatedEmission);
        $this->assertNull($updatedEmission->getRef());
    }

    public function testIndexCanFilterByCategoryWithEmptyTheme(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Filtre catégorie ' . uniqid()
        );

        $emission->addUser($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/',
            [
                'categorie' => (string) $categorie->getId(),
                'theme' => '',
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $emission->getTitre());
    }

    public function testIndexCanFilterByThemeWithEmptyCategory(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Filtre thème ' . uniqid()
        );

        $emission->addUser($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/',
            [
                'categorie' => '',
                'theme' => (string) $theme->getId(),
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $emission->getTitre());
    }

    public function testIndexAcceptsEmptyCategoryAndThemeFilters(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/',
            [
                'categorie' => '',
                'theme' => '',
            ]
        );

        $this->assertResponseIsSuccessful();
    }

    public function testCreateEmission(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie($admin);

        $titre = 'Test Émission ' . uniqid();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/create'
        );

        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler
            ->filter('input[name="emission[_token]"]')
            ->attr('value');

        $this->client->request(
            'POST',
            '/admin/emission/create',
            [
                'emission' => [
                    'titre' => $titre,
                    'descriptif' => 'Description test',
                    'duree' => '60',
                    'url' => 'https://test.com/emission',
                    'keyword' => 'test-keyword',
                    'theme' => (string) $theme->getId(),
                    'categorie' => (string) $categorie->getId(),
                    'users' => [(string) $admin->getId()],
                    '_token' => $csrfToken,
                ],
            ]
        );

        $this->assertResponseRedirects('/admin/emission/');

        $this->entityManager->clear();

        $emission = $this->entityManager
            ->getRepository(Emission::class)
            ->findOneBy([
                'titre' => $titre,
            ]);

        $this->assertNotNull($emission);
        $this->assertSame($titre, $emission->getTitre());
        $this->assertSame('Description test', $emission->getDescriptif());
        $this->assertSame(60, $emission->getDuree());

        $this->assertSame(
            'https://test.com/emission',
            $emission->getUrl()
        );

        $this->assertSame(
            'test-keyword',
            $emission->getKeyword()
        );

        $this->assertNotNull($emission->getDatepub());
        $this->assertNotNull($emission->getUpdatedat());

        $this->assertSame(
            $admin->getUserIdentifier(),
            $emission->getRef()
        );

        $this->assertTrue(
            $emission->getUsers()->exists(
                static fn(int $key, User $user): bool =>
                $user->getId() === $admin->getId()
            )
        );

        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert.alert-success');
    }

    public function testCreateEmissionAssignsCurrentUserAndSetsRefWhenMissing(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie($user);

        $keyword = 'test-create-owner-' . uniqid();

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/create'
        );

        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler
            ->filter('input[name="emission[_token]"]')
            ->attr('value');

        $this->client->request(
            'POST',
            '/admin/emission/create',
            [
                'emission' => [
                    'titre' => 'Création automatique propriétaire ' . uniqid(),
                    'keyword' => $keyword,
                    'theme' => (string) $theme->getId(),
                    'categorie' => (string) $categorie->getId(),
                    'duree' => '60',
                    'descriptif' => 'Description test création',
                    'url' => '',
                    '_token' => $csrfToken,

                    /*
                 * Pas de champ users volontairement.
                 *
                 * Le comportement testé est précisément l'attribution
                 * automatique de l'utilisateur connecté lors de la création.
                 */
                ],
            ]
        );

        $this->assertResponseRedirects('/admin/emission/');

        $this->entityManager->clear();

        $emission = $this->entityManager
            ->getRepository(Emission::class)
            ->findOneBy([
                'keyword' => $keyword,
            ]);

        $this->assertNotNull($emission);

        $this->assertCount(
            1,
            $emission->getUsers()
        );

        $this->assertSame(
            $user->getUserIdentifier(),
            $emission->getUsers()->first()->getUserIdentifier()
        );

        $this->assertSame(
            $user->getUserIdentifier(),
            $emission->getRef()
        );
    }


    public function testDeleteEmissionSoftDeletesEmission(): void
    {
        $admin = $this->createUser(['ROLE_SUPER_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'À supprimer ' . uniqid()
        );

        // La page /admin/emission/ est personnelle :
        // l'émission doit appartenir à l'utilisateur connecté.
        $emission->addUser($admin);
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/'
        );

        $this->assertResponseIsSuccessful();

        // On utilise le vrai formulaire affiché dans la page,
        // avec son token CSRF et son _method DELETE.
        $form = $crawler
            ->selectButton('Supprimer')
            ->form();

        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/emission/');

        $this->entityManager->clear();

        $deletedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        // La suppression est un soft delete :
        // l'émission existe toujours en BDD.
        $this->assertNotNull($deletedEmission);
        $this->assertTrue($deletedEmission->isDeleted());
    }

    public function testShowEmissionWithCategory(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Émission affichée ' . uniqid()
        );

        $emission->addUser($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/' . $emission->getId()
        );

        $this->assertResponseIsSuccessful();
    }

    public function testShowEmissionWithoutCategory(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: null,
            titre: 'Émission sans catégorie ' . uniqid()
        );

        $emission->addUser($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/' . $emission->getId()
        );

        $this->assertResponseIsSuccessful();
    }

    public function testSearchPageIsAccessible(): void
    {
        $user = $this->createUser(['ROLE_USER']);

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/rechercheadmin'
        );

        $this->assertResponseIsSuccessful();
    }

    public function testSearchEmissionByTitle(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $titre = 'Recherche unique ' . uniqid();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: $titre
        );

        $emission->addUser($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/rechercheadmin'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'emission_search[titre]' => $titre,
        ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $titre);
    }

    public function testSearchEmissionByInitial(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $uniqueWord = 'alpha' . uniqid();
        $titre = 'A ' . $uniqueWord;

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: $titre
        );

        $emission->addUser($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/rechercheadmin',
            [
                'initiale' => 'A',
                'emission_search' => [
                    'titre' => $uniqueWord,
                ],
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $titre);
    }

    public function testSearchEmissionWithLastDiffusion(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $titre = 'Recherche avec diffusion ' . uniqid();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: $titre
        );

        $emission->addUser($user);

        $diffusion = new Diffusion();
        $diffusion
            ->setEmission($emission)
            ->setHoraireDiffusion(new \DateTime('2026-09-01 14:00:00'))
            ->setNombreDiffusion(1);

        $this->entityManager->persist($diffusion);
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/rechercheadmin'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'emission_search[titre]' => $titre,
        ]);

        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $titre);
    }

    public function testMarkCompletedSuccessfully(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'À finaliser ' . uniqid()
        );

        $emission->addUser($user);
        $emission->setIsPendingCompletion(true);

        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Retirer de la liste')
            ->form();

        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/');

        $this->entityManager->clear();

        $completedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($completedEmission);
        $this->assertFalse($completedEmission->isPendingCompletion());
    }


    public function testEditEmission(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie($admin);

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'À modifier'
        );

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/' . $emissionId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sauvegarder')->form([
            'emission[titre]' => 'Titre modifié',
            'emission[keyword]' => 'test-keyword-modified',
            'emission[theme]' => (string) $theme->getId(),
            'emission[categorie]' => (string) $categorie->getId(),
            'emission[duree]' => 60,
            'emission[descriptif]' => 'Description initiale',
            'emission[url]' => 'https://test.com/old',
            'emission[users]' => [(string) $admin->getId()],
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();

        $updatedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($updatedEmission);
        $this->assertSame(
            'Titre modifié',
            $updatedEmission->getTitre()
        );
        $this->assertSame(
            'test-keyword-modified',
            $updatedEmission->getKeyword()
        );
    }

    public function testEditEmissionCanMarkPendingEmissionAsCompleted(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie($admin);

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'À finaliser ' . uniqid()
        );

        $emission->setIsPendingCompletion(true);

        $emissionId = $emission->getId();

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/' . $emissionId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sauvegarder')->form([
            'emission[titre]' => $emission->getTitre(),
            'emission[keyword]' => 'test-keyword',
            'emission[theme]' => (string) $theme->getId(),
            'emission[categorie]' => (string) $categorie->getId(),
            'emission[duree]' => 60,
            'emission[descriptif]' => 'Description initiale',
            'emission[url]' => 'https://test.com/emission',
            'emission[users]' => [(string) $admin->getId()],
            'emission[markAsCompleted]' => '1',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();

        $updatedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($updatedEmission);
        $this->assertFalse($updatedEmission->isPendingCompletion());
    }

    public function testEditEmissionCanDeleteMp3(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie($admin);

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Suppression MP3 ' . uniqid()
        );

        $emission
            ->setThumbnailMp3('test-file.mp3')
            ->setUrl('https://test.com/audio.mp3');

        $emissionId = $emission->getId();

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/' . $emissionId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sauvegarder')->form([
            'emission[titre]' => $emission->getTitre(),
            'emission[keyword]' => 'test-keyword',
            'emission[theme]' => (string) $theme->getId(),
            'emission[categorie]' => (string) $categorie->getId(),
            'emission[duree]' => 60,
            'emission[descriptif]' => 'Description initiale',
            'emission[url]' => 'https://test.com/audio.mp3',
            'emission[users]' => [(string) $admin->getId()],
            'emission[deleteMp3]' => '1',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();

        $updatedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($updatedEmission);
        $this->assertNull($updatedEmission->getThumbnailMp3());
        $this->assertNull($updatedEmission->getUrl());
    }

    public function testEditEmissionCanDeleteThumbnail(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie($admin);

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Suppression image ' . uniqid()
        );

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');

        $thumbnailDirectory = $projectDir . '/public/uploads/images/emissions';
        $thumbnailFilename = 'test-image-' . uniqid() . '.jpg';
        $thumbnailPath = $thumbnailDirectory . '/' . $thumbnailFilename;

        if (!is_dir($thumbnailDirectory)) {
            mkdir($thumbnailDirectory, 0777, true);
        }

        $this->assertNotFalse(
            file_put_contents($thumbnailPath, 'image test'),
            'Impossible de créer le fichier miniature de test.'
        );

        $this->assertFileExists($thumbnailPath);

        $emission->setThumbnail($thumbnailFilename);

        $emissionId = $emission->getId();

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        try {
            $crawler = $this->client->request(
                'GET',
                '/admin/emission/' . $emissionId . '/edit'
            );

            $this->assertResponseIsSuccessful();

            $form = $crawler->selectButton('Sauvegarder')->form([
                'emission[titre]' => $emission->getTitre(),
                'emission[keyword]' => 'test-keyword',
                'emission[theme]' => (string) $theme->getId(),
                'emission[categorie]' => (string) $categorie->getId(),
                'emission[duree]' => 60,
                'emission[descriptif]' => 'Description initiale',
                'emission[url]' => 'https://test.com/emission',
                'emission[users]' => [(string) $admin->getId()],
                'delete_thumbnail' => '1',
            ]);

            $this->client->submit($form);

            $this->assertResponseRedirects();

            $this->entityManager->clear();

            $updatedEmission = $this->entityManager
                ->getRepository(Emission::class)
                ->find($emissionId);

            $this->assertNotNull($updatedEmission);
            $this->assertNull($updatedEmission->getThumbnail());

            $this->assertFileDoesNotExist($thumbnailPath);
        } finally {
            if (is_file($thumbnailPath)) {
                unlink($thumbnailPath);
            }
        }
    }

    public function testEditEmissionAccessDeniedForUnrelatedUser(): void
    {
        $owner = $this->createUser(['ROLE_USER']);
        $otherUser = $this->createUser(['ROLE_USER']);

        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Émission privée ' . uniqid()
        );

        $emission->addUser($owner);
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($otherUser);

        $this->client->request(
            'GET',
            '/admin/emission/' . $emissionId . '/edit'
        );

        $this->assertResponseRedirects('/access-denied');
    }

    public function testEditEmissionCanUploadAndProcessMp3(): void
    {
        $admin = $this->createUser(['ROLE_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie($admin);
        $categorie->setSlug('TST');

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Émission MP3 test'
        );

        $emissionId = $emission->getId();

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/' . $emissionId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $mp3Path = dirname(__DIR__, 2) . '/Fixtures/mp3/test.mp3';

        $this->assertFileExists($mp3Path);

        $form = $crawler->selectButton('Sauvegarder')->form([
            'emission[titre]' => 'Émission MP3 test',
            'emission[keyword]' => 'test-mp3',
            'emission[theme]' => (string) $theme->getId(),
            'emission[categorie]' => (string) $categorie->getId(),
            'emission[duree]' => 60,
            'emission[descriptif]' => 'Description test MP3',
            'emission[url]' => '',
            'emission[users]' => [(string) $admin->getId()],
        ]);

        $mp3Field = $form['emission[thumbnailFileMp3]'];

        $this->assertInstanceOf(
            \Symfony\Component\DomCrawler\Field\FileFormField::class,
            $mp3Field
        );

        $mp3Field->upload($mp3Path);

        $this->client->submit($form);

        $this->assertResponseRedirects();

        $this->entityManager->clear();

        $updatedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($updatedEmission);
        $this->assertNotNull($updatedEmission->getThumbnailMp3());

        $this->assertStringStartsWith(
            'TST/' . date('Y') . '/',
            $updatedEmission->getThumbnailMp3()
        );

        $this->assertStringEndsWith(
            '.mp3',
            $updatedEmission->getThumbnailMp3()
        );

        $this->assertNotNull($updatedEmission->getUrl());

        $this->assertStringContainsString(
            '/uploads/emissionsMp3/' . $updatedEmission->getThumbnailMp3(),
            $updatedEmission->getUrl()
        );

        $finalMp3Path =
            dirname(__DIR__, 3)
            . '/public/uploads/emissionsMp3/'
            . $updatedEmission->getThumbnailMp3();

        $this->assertFileExists($finalMp3Path);
    }

    public function testMarkCompletedDeniedForUserNotLinkedToEmission(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Émission non autorisée ' . uniqid()
        );

        $emission->setIsPendingCompletion(true);

        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/admin/emission/' . $emission->getId() . '/mark-completed'
        );

        $this->assertResponseRedirects('/access-denied');
    }

    public function testMarkCompletedDeniedWithInvalidCsrfToken(): void
    {
        $user = $this->createUser(['ROLE_USER']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'CSRF invalide ' . uniqid()
        );

        $emission->addUser($user);
        $emission->setIsPendingCompletion(true);

        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/admin/emission/' . $emission->getId() . '/mark-completed',
            [
                '_token' => 'token-volontairement-invalide',
            ]
        );

        $this->assertResponseRedirects('/access-denied');
    }

    public function testDeleteEmissionWithInvalidCsrfToken(): void
    {
        $admin = $this->createUser(['ROLE_SUPER_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Suppression CSRF invalide ' . uniqid()
        );

        $emission->addUser($admin);

        $emissionId = $emission->getId();

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'DELETE',
            '/admin/emission/' . $emissionId,
            [
                '_token' => 'token-volontairement-invalide',
            ]
        );

        $this->assertResponseRedirects('/admin/emission/');

        $this->entityManager->clear();

        $emissionAfterRequest = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($emissionAfterRequest);
        $this->assertFalse($emissionAfterRequest->isDeleted());
    }

    public function testDeleteEmissionRedirectsToReturnTo(): void
    {
        $admin = $this->createUser(['ROLE_SUPER_ADMIN']);
        $theme = $this->createTheme();
        $categorie = $this->createCategorie();

        $emission = $this->createEmission(
            theme: $theme,
            categorie: $categorie,
            titre: 'Suppression avec returnTo ' . uniqid()
        );

        $emission->addUser($admin);
        $this->entityManager->flush();

        $emissionId = $emission->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/emission/'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Supprimer')
            ->form();

        $returnTo = '/admin/emission/rechercheadmin';

        $form->getNode()
            ->setAttribute(
                'action',
                '/admin/emission/' . $emissionId . '?returnTo=' . urlencode($returnTo)
            );

        $this->client->submit($form);

        $this->assertResponseRedirects($returnTo);

        $this->entityManager->clear();

        $deletedEmission = $this->entityManager
            ->getRepository(Emission::class)
            ->find($emissionId);

        $this->assertNotNull($deletedEmission);
        $this->assertTrue($deletedEmission->isDeleted());
    }

    private function createUser(array $roles): User
    {
        $uniqueId = uniqid();

        $user = new User();
        $user->setUsername('user_' . $uniqueId);
        $user->setEmail('user_' . $uniqueId . '@test.com');
        $user->setPassword('fakehashedpassword');
        $user->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTheme(): Theme
    {
        $theme = new Theme();
        $theme->setName('Thème test ' . uniqid());
        $theme->setUpdatedAt(new \DateTime());

        $this->entityManager->persist($theme);
        $this->entityManager->flush();

        return $theme;
    }

    private function createCategorie(?User $user = null): Categories
    {
        $editeur = $this->createEditeur();

        $categorie = new Categories();
        $categorie->setTitre('Catégorie test ' . uniqid());
        $categorie->setDuree(60);
        $categorie->setActive(true);
        $categorie->setSoftDelete(false);
        $categorie->setDescriptif('Description test');
        $categorie->setUpdatedAt(new \DateTime());
        $categorie->setEditeur($editeur);

        if ($user !== null) {
            $categorie->addUser($user);
        }

        $this->entityManager->persist($categorie);
        $this->entityManager->flush();

        return $categorie;
    }

    private function createEditeur(): Editeur
    {
        $editeur = new Editeur();
        $editeur->setName('Éditeur test ' . uniqid());
        $editeur->setUpdateAt(new \DateTime());

        $this->entityManager->persist($editeur);
        $this->entityManager->flush();

        return $editeur;
    }

    private function createEmission(
        Theme $theme,
        ?Categories $categorie = null,
        string $titre = 'Émission test'
    ): Emission {
        $emission = new Emission();

        $emission
            ->setTitre($titre)
            ->setDescriptif('Description initiale')
            ->setDuree(60)
            ->setUrl('https://test.com/emission')
            ->setDatepub(new \DateTime())
            ->setUpdatedat(new \DateTime())
            ->setKeyword('test-keyword')
            ->setRef('REF-' . uniqid())
            ->setTheme($theme);

        if ($categorie !== null) {
            $emission->setCategorie($categorie);
        }

        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        return $emission;
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager->close();
        }

        parent::tearDown();
    }
}

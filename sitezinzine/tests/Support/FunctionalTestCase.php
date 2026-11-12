<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\Theme;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class FunctionalTestCase extends WebTestCase
{
    use DoctrineTestTrait;

    protected KernelBrowser $client;
    protected string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient(['debug' => false]);
        // Keep the same connection and transaction across the HTTP journey.
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
        $this->marker = 'workflow' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        try {
            $this->closeEntityManager();
        } finally {
            try {
                parent::tearDown();
            } finally {
                unset($this->client, $this->entityManager);
            }
        }
    }

    protected function createUser(
        string $role = 'ROLE_USER',
        string $password = 'Test-password-42!'
    ): User {
        $user = (new User())
            ->setUsername('wf' . bin2hex(random_bytes(8)))
            ->setEmail(bin2hex(random_bytes(8)) . '@example.test')
            ->setRoles([$role])
            ->setVerified(true)
            ->setApproved(true);

        $user->setPassword(
            static::getContainer()
                ->get(UserPasswordHasherInterface::class)
                ->hashPassword($user, $password)
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    protected function createCategory(): Categories
    {
        // Same valid, unique category code as FormIntegrationTestCase::category().
        do {
            $slug = chr(random_int(65, 90)) . chr(random_int(65, 90)) . chr(random_int(65, 90));
        } while ($this->entityManager->getRepository(Categories::class)->findOneBy(['slug' => $slug]) !== null);
        $editor = (new Editeur())->setName($this->marker . ' editor')->setUpdateAt(new \DateTime());
        $category = (new Categories())->setTitre($this->marker . ' ' . bin2hex(random_bytes(3)))
            ->setSlug($slug)->setDuree(60)->setActive(true)->setSoftDelete(false)
            ->setDescriptif('Description de la categorie')->setUpdatedAt(new \DateTime())->setEditeur($editor);
        $this->entityManager->persist($editor);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    protected function createTheme(): Theme
    {
        $theme = (new Theme())->setName($this->marker . ' ' . bin2hex(random_bytes(3)))->setUpdatedAt(new \DateTime());
        $this->entityManager->persist($theme);
        $this->entityManager->flush();

        return $theme;
    }

    protected function createEmission(string $title, ?User $owner = null, ?Categories $category = null, ?Theme $theme = null): Emission
    {
        $emission = (new Emission())->setTitre($title)->setDescriptif('Description du parcours public')
            ->setDuree(60)->setUrl('https://example.test/audio.mp3')
            ->setDatepub(new \DateTime())->setUpdatedat(new \DateTime())
            ->setKeyword('parcours')->setRef('WF' . bin2hex(random_bytes(6)))
            ->setCategorie($category ?? $this->createCategory())->setTheme($theme ?? $this->createTheme());
        if ($owner !== null) {
            // Emission owns the real emission_user many-to-many relationship.
            $emission->addUser($owner);
        }
        $this->entityManager->persist($emission);
        $this->entityManager->flush();

        return $emission;
    }

    protected function search(string $url, array $fields): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $values = [];
        foreach ($fields as $name => $value) {
            $values['emission_search[' . $name . ']'] = $value;
        }

        $results = $this->client->submit($crawler->selectButton('Rechercher')->form($values));
        self::assertSame(0, $results->filter('form .invalid-feedback, form .form-error-message')->count(), 'The submitted search filters must be valid.');

        return $results;
    }

    protected function assertEmissionVisible(Emission $emission, bool $visible = true, bool $admin = false): void
    {
        $selector = '.emission-list .titre a[href="' . ($admin ? '/admin' : '') . '/emission/' . $emission->getId() . '"]';
        if ($visible) {
            self::assertSelectorExists($selector);
            self::assertSelectorTextContains($selector, $emission->getTitre());
        } else {
            self::assertSelectorNotExists($selector);
        }
    }
}

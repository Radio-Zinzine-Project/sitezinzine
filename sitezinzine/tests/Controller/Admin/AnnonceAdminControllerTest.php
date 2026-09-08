<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Annonce;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AnnonceAdminControllerTest extends WebTestCase
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

    public function testIndexPageIsAccessible(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);

        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/annonce/');

        $this->assertResponseIsSuccessful();
    }

    public function testCanEditAnnonce(): void
    {
        $user = $this->createUser(['ROLE_EDITOR']);
        $annonce = $this->createTestAnnonce(false);

        $annonceId = $annonce->getId();

        $this->client->loginUser($user);

        $crawler = $this->client->request(
            'GET',
            '/admin/annonce/' . $annonceId . '/edit'
        );

        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form([
            'annonce[titre]' => 'Annonce modifiée',
            'annonce[presentation]' => 'Nouvelle description',
            'annonce[valid]' => true,
            'annonce[dateDebut]' => (new \DateTime('+1 day'))->format('Y-m-d H:i'),
            'annonce[dateFin]' => (new \DateTime('+7 days'))->format('Y-m-d H:i'),
            'annonce[horaire]' => '10h-20h',
            'annonce[prix]' => '10€',
            'annonce[contact]' => 'nouvellemail@example.com',
            'annonce[type]' => 'Concert',
            'annonce[departement]' => '04',
            'annonce[ville]' => 'New York',
            'annonce[adresse]' => '123 Main St',
        ]);

        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/annonce/');

        $this->entityManager->clear();

        $updatedAnnonce = $this->entityManager
            ->getRepository(Annonce::class)
            ->find($annonceId);

        $this->assertNotNull($updatedAnnonce);
        $this->assertSame('Annonce modifiée', $updatedAnnonce->getTitre());
        $this->assertSame(
            'Nouvelle description',
            $updatedAnnonce->getPresentation()
        );
        $this->assertSame('Concert', $updatedAnnonce->getType());
        $this->assertSame('New York', $updatedAnnonce->getVille());
        $this->assertSame('123 Main St', $updatedAnnonce->getAdresse());
        $this->assertSame('10h-20h', $updatedAnnonce->getHoraire());
        $this->assertSame('10€', $updatedAnnonce->getPrix());
        $this->assertSame(
            'nouvellemail@example.com',
            $updatedAnnonce->getContact()
        );
        $this->assertTrue($updatedAnnonce->isValid());

        $this->client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert.alert-success');
    }

    public function testCanValidateAnnonce(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $annonce = $this->createTestAnnonce(false);

        $annonceId = $annonce->getId();

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/admin/annonce/' . $annonceId . '/valid'
        );

        $this->assertResponseRedirects('/admin/annonce/');

        $this->entityManager->clear();

        $updatedAnnonce = $this->entityManager
            ->getRepository(Annonce::class)
            ->find($annonceId);

        $this->assertNotNull($updatedAnnonce);
        $this->assertTrue($updatedAnnonce->isValid());
    }

    public function testCanUnvalidateAnnonce(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $annonce = $this->createTestAnnonce(true);

        $annonceId = $annonce->getId();

        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/admin/annonce/' . $annonceId . '/unvalid'
        );

        $this->assertResponseRedirects('/admin/annonce/');

        $this->entityManager->clear();

        $updatedAnnonce = $this->entityManager
            ->getRepository(Annonce::class)
            ->find($annonceId);

        $this->assertNotNull($updatedAnnonce);
        $this->assertFalse($updatedAnnonce->isValid());
    }

    public function testCanSoftDeleteAnnonce(): void
    {
        $user = $this->createUser(['ROLE_ADMIN']);
        $annonce = $this->createTestAnnonce();

        $annonceId = $annonce->getId();

        $this->client->loginUser($user);

        $this->client->request(
            'DELETE',
            '/admin/annonce/' . $annonceId
        );

        $this->assertResponseRedirects('/admin/annonce/');

        $this->entityManager->clear();

        $deletedAnnonce = $this->entityManager
            ->getRepository(Annonce::class)
            ->find($annonceId);

        $this->assertNotNull($deletedAnnonce);
        $this->assertTrue($deletedAnnonce->isSoftDelete());
    }

    private function createUser(array $roles): User
    {
        $uniqueId = uniqid();

        $user = new User();
        $user->setUsername('user_' . $uniqueId);
        $user->setEmail('user_' . $uniqueId . '@example.com');
        $user->setPassword('fakehashedpassword');
        $user->setRoles($roles);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function createTestAnnonce(bool $valid = true): Annonce
    {
        $annonce = new Annonce();
        $annonce->setTitre('Test annonce');
        $annonce->setPresentation('Description test');
        $annonce->setType('Concert');
        $annonce->setValid($valid);
        $annonce->setSoftDelete(false);
        $annonce->setUpdateAt(new \DateTime());
        $annonce->setDateDebut(new \DateTime('+1 day'));
        $annonce->setDateFin(new \DateTime('+7 days'));
        $annonce->setHoraire('9h-18h');
        $annonce->setPrix('Gratuit');
        $annonce->setContact('test@example.com');
        $annonce->setOrganisateur('Organisateur test');
        $annonce->setDepartement('75');
        $annonce->setVille('Paris');
        $annonce->setAdresse('1 rue du Test');

        $this->entityManager->persist($annonce);
        $this->entityManager->flush();

        return $annonce;
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $this->entityManager
                ->createQuery('DELETE FROM App\Entity\Annonce')
                ->execute();

            $this->entityManager
                ->createQuery('DELETE FROM App\Entity\User')
                ->execute();

            $this->entityManager->close();
        }

        parent::tearDown();
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Categories;
use App\Entity\Emission;
use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;

final class EmissionUsersForCategoryTest extends FunctionalTestCase
{
    public function testUserGetsUsersFromOwnCategory(): void
    {
        $user = $this->createUser('ROLE_USER');
        $otherCategoryUser = $this->createUser('ROLE_USER');
        $outsideUser = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($user);
        $category->addUser($otherCategoryUser);

        $this->entityManager->flush();

        self::assertNotNull($category->getId());

        $categoryId = $category->getId();

        self::assertNotNull(
            $this->entityManager
                ->getRepository(Categories::class)
                ->find($categoryId),
            'La catégorie doit exister avant la requête HTTP.'
        );
        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/' . $categoryId
        );

        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse();

        self::assertSame($categoryId, $data['categoryId']);
        self::assertNull($data['emissionId']);

        $userData = $this->findUserData($data, $user);
        $otherData = $this->findUserData($data, $otherCategoryUser);

        self::assertNotNull($userData);
        self::assertNotNull($otherData);

        self::assertSame('current', $userData['status']);
        self::assertSame('current', $otherData['status']);

        self::assertTrue($userData['selected']);
        self::assertFalse($otherData['selected']);

        self::assertNull(
            $this->findUserData($data, $outsideUser),
            'Un USER ne doit pas recevoir les utilisateurs hors de sa catégorie.'
        );
    }

    public function testUserCannotGetUsersFromUnrelatedCategory(): void
    {
        $user = $this->createUser('ROLE_USER');
        $categoryUser = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($categoryUser);

        $this->entityManager->flush();

        $this->client->loginUser($user);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/' . $category->getId()
        );

        self::assertResponseRedirects('/access-denied');
    }

    public function testEditorGetsUsersFromOwnCategory(): void
    {
        $editor = $this->createUser('ROLE_EDITOR');
        $categoryUser = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($editor);
        $category->addUser($categoryUser);

        $this->entityManager->flush();

        $this->client->loginUser($editor);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/' . $category->getId()
        );

        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse();

        self::assertNotNull(
            $this->findUserData($data, $editor)
        );

        self::assertNotNull(
            $this->findUserData($data, $categoryUser)
        );
    }

    public function testAdminGetsOnlyCurrentCategoryUsersOnCreation(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $categoryUserOne = $this->createUser('ROLE_USER');
        $categoryUserTwo = $this->createUser('ROLE_USER');
        $outsideUser = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($categoryUserOne);
        $category->addUser($categoryUserTwo);

        $this->entityManager->flush();

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/' . $category->getId()
        );

        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse();

        $first = $this->findUserData($data, $categoryUserOne);
        $second = $this->findUserData($data, $categoryUserTwo);

        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertSame('current', $first['status']);
        self::assertSame('current', $second['status']);

        self::assertTrue(
            $first['selected'],
            'En création ADMIN, tous les users actuels de la catégorie doivent être présélectionnés.'
        );

        self::assertTrue(
            $second['selected'],
            'En création ADMIN, tous les users actuels de la catégorie doivent être présélectionnés.'
        );

        self::assertNull(
            $this->findUserData($data, $outsideUser),
            'Un ADMIN ne peut pas associer librement un utilisateur hors catégorie.'
        );
    }

    public function testSuperAdminGetsAllUsersButOnlyCurrentCategoryUsersAreSelectedOnCreation(): void
    {
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');

        $categoryUserOne = $this->createUser('ROLE_USER');
        $categoryUserTwo = $this->createUser('ROLE_USER');
        $outsideUser = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($categoryUserOne);
        $category->addUser($categoryUserTwo);

        $this->entityManager->flush();

        $this->client->loginUser($superAdmin);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/' . $category->getId()
        );

        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse();

        $first = $this->findUserData($data, $categoryUserOne);
        $second = $this->findUserData($data, $categoryUserTwo);
        $outside = $this->findUserData($data, $outsideUser);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotNull($outside);

        self::assertSame('current', $first['status']);
        self::assertSame('current', $second['status']);
        self::assertSame('outside', $outside['status']);

        self::assertTrue($first['selected']);
        self::assertTrue($second['selected']);

        self::assertFalse(
            $outside['selected'],
            'Un SUPER_ADMIN peut sélectionner un user hors catégorie, mais celui-ci ne doit pas être ajouté automatiquement.'
        );

        self::assertStringContainsString(
            'catégorie actuelle',
            $first['label']
        );

        self::assertStringContainsString(
            'hors catégorie',
            $outside['label']
        );
    }

    public function testEditKeepsHistoricalUserSelectedWithoutAutomaticallySelectingCurrentCategoryUsers(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $historicalUser = $this->createUser('ROLE_USER');
        $currentCategoryUser = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($currentCategoryUser);

        $this->entityManager->flush();

        $emission = $this->createEmission(
            $this->marker . ' émission historique',
            $historicalUser,
            $category
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/'
                . $category->getId()
                . '?emissionId='
                . $emission->getId()
        );

        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse();

        self::assertSame($emission->getId(), $data['emissionId']);

        $historical = $this->findUserData(
            $data,
            $historicalUser
        );

        $current = $this->findUserData(
            $data,
            $currentCategoryUser
        );

        self::assertNotNull($historical);
        self::assertNotNull($current);

        self::assertSame(
            'historical',
            $historical['status']
        );

        self::assertSame(
            'current',
            $current['status']
        );

        self::assertTrue(
            $historical['selected'],
            'L’utilisateur historiquement associé à l’émission doit rester sélectionné.'
        );

        self::assertFalse(
            $current['selected'],
            'En édition, un membre actuel de la catégorie ne doit pas être ajouté automatiquement à une ancienne émission.'
        );

        self::assertStringContainsString(
            'ancienne association',
            $historical['label']
        );
    }

    public function testUserCanEditEmissionWithHistoricalCategoryEvenWhenNoLongerAttachedToCategory(): void
    {
        $historicalUser = $this->createUser('ROLE_USER');
        $currentCategoryUser = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($currentCategoryUser);

        $this->entityManager->flush();

        $emission = $this->createEmission(
            $this->marker . ' ancienne responsabilité',
            $historicalUser,
            $category
        );

        self::assertFalse(
            $category->getUsers()->contains($historicalUser)
        );

        self::assertTrue(
            $emission->getUsers()->contains($historicalUser)
        );

        $this->client->loginUser($historicalUser);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/'
                . $category->getId()
                . '?emissionId='
                . $emission->getId()
        );

        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse();

        $historical = $this->findUserData(
            $data,
            $historicalUser
        );

        $current = $this->findUserData(
            $data,
            $currentCategoryUser
        );

        self::assertNotNull($historical);
        self::assertNotNull($current);

        self::assertSame(
            'historical',
            $historical['status']
        );

        self::assertTrue(
            $historical['selected']
        );
    }

    public function testUserCannotUseEmissionIdToInspectAnotherUsersEmission(): void
    {
        $owner = $this->createUser('ROLE_USER');
        $intruder = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($owner);

        $this->entityManager->flush();

        $emission = $this->createEmission(
            $this->marker . ' protected emission',
            $owner,
            $category
        );

        $this->client->loginUser($intruder);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/' . $category->getId() . '?emissionId=' . $emission->getId()
        );

        self::assertResponseRedirects('/access-denied');
    }

    public function testAdminCanRequestUsersForAnotherUsersEmission(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $emissionOwner = $this->createUser('ROLE_USER');

        $category = $this->createCategory();
        $category->addUser($emissionOwner);

        $this->entityManager->flush();

        $emission = $this->createEmission(
            $this->marker . ' émission administrable',
            $emissionOwner,
            $category
        );

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/'
                . $category->getId()
                . '?emissionId='
                . $emission->getId()
        );

        self::assertResponseIsSuccessful();

        $data = $this->jsonResponse();

        self::assertSame(
            $emission->getId(),
            $data['emissionId']
        );

        self::assertNotNull(
            $this->findUserData($data, $emissionOwner)
        );
    }

    public function testUnknownCategoryReturns404(): void
    {
        $user = $this->createUser('ROLE_USER');

        $this->client->loginUser($user);

        $unknownCategoryId = $this->nextUnusedCategoryId();

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/' . $unknownCategoryId
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownEmissionReturns404(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $category = $this->createCategory();

        $this->client->loginUser($admin);

        $unknownEmissionId = $this->nextUnusedEmissionId();

        $this->client->request(
            'GET',
            '/admin/emission/users-for-category/'
                . $category->getId()
                . '?emissionId='
                . $unknownEmissionId
        );

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(): array
    {
        $content = $this->client
            ->getResponse()
            ->getContent();

        self::assertIsString($content);

        $data = json_decode(
            $content,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private function findUserData(
        array $data,
        User $user
    ): ?array {
        self::assertArrayHasKey('users', $data);
        self::assertIsArray($data['users']);

        foreach ($data['users'] as $userData) {
            if (
                is_array($userData)
                && ($userData['id'] ?? null) === $user->getId()
            ) {
                return $userData;
            }
        }

        return null;
    }

    private function nextUnusedCategoryId(): int
    {
        $maxId = $this->entityManager
            ->createQueryBuilder()
            ->select('MAX(c.id)')
            ->from(Categories::class, 'c')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $maxId) + 1;
    }

    private function nextUnusedEmissionId(): int
    {
        $maxId = $this->entityManager
            ->createQueryBuilder()
            ->select('MAX(e.id)')
            ->from(Emission::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $maxId) + 1;
    }
}

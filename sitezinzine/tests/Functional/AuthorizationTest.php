<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Emission;
use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;

final class AuthorizationTest extends FunctionalTestCase
{
    /** @var array<string, User> */
    private array $users;
    private Emission $own;
    private Emission $other;

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            unset($this->users, $this->own, $this->other);
        }
    }

    public static function roles(): iterable
    {
        yield 'user' => ['ROLE_USER'];
        yield 'admin' => ['ROLE_ADMIN'];
        yield 'super admin' => ['ROLE_SUPER_ADMIN'];
    }

    private function prepareEmissions(string $role): void
    {
        foreach (['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as $fixtureRole) {
            $this->users[$fixtureRole] = $this->createUser($fixtureRole);
        }
        $this->own = $this->createEmission($this->marker . ' personnelle', $this->users[$role]);
        $otherRole = $role === 'ROLE_USER' ? 'ROLE_ADMIN' : 'ROLE_USER';
        $this->other = $this->createEmission($this->marker . ' autre', $this->users[$otherRole]);
        $this->client->loginUser($this->users[$role]);
    }

    /** @dataProvider roles */
    public function testMyEmissionsOnlyShowsOwnedEmissions(string $role): void
    {
        $this->prepareEmissions($role);
        $this->client->request('GET', '/admin/emission/', ['q' => $this->marker]);
        self::assertResponseIsSuccessful();
        $this->assertEmissionVisible($this->own, true, true);
        $this->assertEmissionVisible($this->other, false, true);
    }

    /** @dataProvider roles */
    public function testGlobalSearchShowsEmissionsFromDifferentOwners(string $role): void
    {
        $this->prepareEmissions($role);
        $this->search('/admin/emission/rechercheadmin', ['titre' => $this->marker]);
        self::assertResponseIsSuccessful();
        $this->assertEmissionVisible($this->own, true, true);
        $this->assertEmissionVisible($this->other, true, true);
    }

    /** @dataProvider roles */
    public function testGlobalSearchShowsDeleteButtonForOwnEmission(string $role): void
    {
        $this->prepareEmissions($role);
        $this->search('/admin/emission/rechercheadmin', ['titre' => $this->marker]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/admin/emission/' . $this->own->getId() . '"] button.btn-delete');
    }

    /** @dataProvider administrationScreens */
    public function testAdministrativeScreensRespectTheirDeclaredRoles(string $role, string $url, bool $allowed): void
    {
        $this->client->loginUser($this->createUser($role));
        $this->client->request('GET', $url);
        if ($allowed) {
            self::assertResponseIsSuccessful();
        } else {
            self::assertResponseRedirects('/access-denied');
        }
    }

    public static function administrationScreens(): iterable
    {
        foreach (['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'] as $role) {
            yield $role . ' categories' => [
                $role,
                '/admin/categorie',
                true,
            ];

            yield $role . ' create category' => [
                $role,
                '/admin/categorie/create',
                $role !== 'ROLE_USER',
            ];

            /*
         * La gestion des utilisateurs est accessible à partir de ROLE_ADMIN.
         * ROLE_SUPER_ADMIN hérite de ROLE_ADMIN.
         */
            yield $role . ' user' => [
                $role,
                '/admin/user/',
                $role !== 'ROLE_USER',
            ];

            /*
         * Theme et Editeur conservent pour l'instant leur comportement
         * actuel : les deux IsGranted cumulés les réservent au SUPER_ADMIN.
         */
            foreach (['theme', 'editeur'] as $screen) {
                yield $role . ' ' . $screen => [
                    $role,
                    '/admin/' . $screen . '/',
                    $role === 'ROLE_SUPER_ADMIN',
                ];
            }
        }
    }

    /** @dataProvider roles */
    public function testGlobalSearchShowsEditButtonsAccordingToAuthorization(string $role): void
    {
        $this->prepareEmissions($role);

        $this->search('/admin/emission/rechercheadmin', [
            'titre' => $this->marker,
        ]);

        self::assertResponseIsSuccessful();

        // L'utilisateur peut modifier sa propre émission.
        self::assertSelectorExists(
            'a.btn-edit[href="/admin/emission/' . $this->own->getId() . '/edit"]'
        );

        $selector = 'a.btn-edit[href="/admin/emission/' . $this->other->getId() . '/edit"]';

        // ADMIN et SUPER_ADMIN peuvent modifier toutes les émissions.
        // USER ne peut modifier que les émissions auxquelles il est associé.
        if ($role === 'ROLE_ADMIN' || $role === 'ROLE_SUPER_ADMIN') {
            self::assertSelectorExists($selector);
        } else {
            self::assertSelectorNotExists($selector);
        }
    }

    /** @dataProvider roles */
    public function testGlobalSearchShowsOthersDeleteButtonAccordingToAuthorization(string $role): void
    {
        $this->prepareEmissions($role);

        $this->search('/admin/emission/rechercheadmin', [
            'titre' => $this->marker,
        ]);

        self::assertResponseIsSuccessful();

        $selector = 'form[action="/admin/emission/' . $this->other->getId() . '"] button.btn-delete';

        // ADMIN et SUPER_ADMIN peuvent supprimer toutes les émissions.
        // USER ne peut supprimer que les émissions auxquelles il est associé.
        if ($role === 'ROLE_ADMIN' || $role === 'ROLE_SUPER_ADMIN') {
            self::assertSelectorExists($selector);
        } else {
            self::assertSelectorNotExists($selector);
        }
    }

    /** @dataProvider roles */
    public function testDirectEditOfAnotherOwnersEmissionRequiresAdmin(string $role): void
    {
        $this->prepareEmissions($role);

        $this->client->request(
            'GET',
            '/admin/emission/' . $this->other->getId() . '/edit'
        );

        if ($role === 'ROLE_ADMIN' || $role === 'ROLE_SUPER_ADMIN') {
            self::assertResponseIsSuccessful();
            self::assertSelectorExists(
                'form[name="emission"] input[name="emission[titre]"]'
            );

            return;
        }

        self::assertSame(
            302,
            $this->client->getResponse()->getStatusCode(),
            $role . ' must be denied access to another owner\'s edit form.'
        );
        self::assertResponseRedirects('/access-denied');
    }

    /** @dataProvider roles */
    public function testDirectDeleteOfAnotherOwnersEmissionRequiresAdmin(string $role): void
    {
        $this->prepareEmissions($role);

        $id = $this->other->getId();

        /*
     * On récupère un véritable token CSRF afin que le refus de ROLE_USER
     * teste bien l'autorisation et non un token invalide.
     */
        $this->client->loginUser($this->users['ROLE_SUPER_ADMIN']);

        $crawler = $this->search(
            '/admin/emission/rechercheadmin',
            ['titre' => $this->marker]
        );

        self::assertResponseIsSuccessful();

        $token = $crawler
            ->filter(
                'form[action="/admin/emission/' . $id . '"] input[name="_token"]'
            )
            ->attr('value');

        $this->client->loginUser($this->users[$role]);

        $this->client->request(
            'DELETE',
            '/admin/emission/' . $id,
            ['_token' => $token]
        );

        $this->entityManager->clear();

        $emission = $this->entityManager->find(Emission::class, $id);

        self::assertNotNull($emission);

        if ($role === 'ROLE_ADMIN' || $role === 'ROLE_SUPER_ADMIN') {
            self::assertResponseRedirects('/admin/emission/');
            self::assertTrue(
                $emission->isDeleted(),
                $role . ' must be allowed to delete another owner\'s emission.'
            );

            return;
        }

        self::assertFalse(
            $emission->isDeleted(),
            $role . ' must not delete another owner\'s emission with a valid CSRF token.'
        );
        self::assertResponseRedirects('/access-denied');
    }
}

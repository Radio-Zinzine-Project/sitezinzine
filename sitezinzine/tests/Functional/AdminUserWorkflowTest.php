<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\FunctionalTestCase;

final class AdminUserWorkflowTest extends FunctionalTestCase
{
    public function testAdminCanAccessUserManagement(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/user/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'h1',
            'Gestion des utilisateurices'
        );
    }

    public function testRegularUserCannotAccessUserManagement(): void
    {
        $user = $this->createUser('ROLE_USER');

        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/user/');

        self::assertResponseRedirects('/access-denied');
    }

    public function testAdminSeesApproveButtonForVerifiedUnapprovedUser(): void
    {
        $pendingUser = $this->createUser();

        $pendingUser
            ->setVerified(true)
            ->setApproved(false);

        $this->entityManager->flush();

        $id = $pendingUser->getId();
        $username = $pendingUser->getUsername();

        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);
        self::assertTrue($reloadedUser->isVerified());
        self::assertFalse($reloadedUser->isApproved());

        $admin = $this->createUser('ROLE_ADMIN');

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/'
        );

        self::assertResponseIsSuccessful();

        /*
     * On retrouve la ligne correspondant exactement
     * à l'utilisateur créé par ce test.
     */
        $userRow = $crawler
            ->filter('table.users-table tbody tr')
            ->reduce(
                static function ($node) use ($username): bool {
                    return str_contains(
                        $node->text(),
                        $username
                    );
                }
            );

        self::assertCount(
            1,
            $userRow,
            sprintf(
                'L’utilisateur %s (id %d) devrait être présent dans la liste.',
                $username,
                $id
            )
        );

        /*
     * Twig doit reconnaître le compte comme vérifié
     * mais pas encore approuvé.
     */
        self::assertStringContainsString(
            'En attente de validation',
            $userRow->text()
        );

        /*
     * Cette ligne doit donc proposer l'approbation.
     */
        self::assertCount(
            1,
            $userRow->filter(
                'form[action="/admin/user/' . $id . '/approve"]'
            )
        );
    }

    public function testApproveButtonIsNotShownBeforeEmailVerification(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $pendingUser = $this->createUser();
        $pendingUser
            ->setVerified(false)
            ->setApproved(false);

        $this->entityManager->flush();

        $id = $pendingUser->getId();

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/user/'
        );

        self::assertResponseIsSuccessful();

        self::assertSelectorNotExists(
            'form[action="/admin/user/' . $id . '/approve"]'
        );
    }

    public function testAdminCanApproveVerifiedUser(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $pendingUser = $this->createUser();
        $pendingUser
            ->setVerified(true)
            ->setApproved(false);

        $this->entityManager->flush();

        $id = $pendingUser->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter(
                'form[action="/admin/user/' . $id . '/approve"]'
            )
            ->form();

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/user/');

        $this->entityManager->clear();

        $approvedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($approvedUser);
        self::assertTrue($approvedUser->isVerified());
        self::assertTrue($approvedUser->isApproved());
    }

    public function testInvalidCsrfTokenDoesNotApproveUser(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $pendingUser = $this->createUser();
        $pendingUser
            ->setVerified(true)
            ->setApproved(false);

        $this->entityManager->flush();

        $id = $pendingUser->getId();

        $this->client->loginUser($admin);

        /*
         * Une première requête crée la session utilisée par
         * l'authentification et la protection CSRF.
         */
        $this->client->request(
            'GET',
            '/admin/user/'
        );

        self::assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            '/admin/user/' . $id . '/approve',
            [
                '_token' => 'invalid-token',
            ]
        );

        self::assertResponseRedirects('/admin/user/');

        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);
        self::assertFalse($reloadedUser->isApproved());
    }

    public function testAdminDoesNotSeeDeactivateButtons(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $target = $this->createUser();

        $id = $target->getId();

        self::assertNotNull($id);

        $this->client->loginUser($admin);

        $this->client->request(
            'GET',
            '/admin/user/'
        );

        self::assertResponseIsSuccessful();

        /*
     * La désactivation d'un compte est réservée
     * aux SUPER_ADMIN.
     *
     * Un ADMIN ne doit donc pas voir le formulaire
     * permettant de désactiver cet utilisateur.
     */
        self::assertSelectorNotExists(
            'form[action="/admin/user/' . $id . '"] button.btn-deactivate'
        );
    }

    public function testAdminCannotApproveUnverifiedUser(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        /*
     * On crée d'abord le compte dans un état qui permet
     * au template de générer son véritable formulaire CSRF.
     */
        $target = $this->createUser();
        $target
            ->setVerified(true)
            ->setApproved(false);

        $this->entityManager->flush();

        $id = $target->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter(
                'form[action="/admin/user/' . $id . '/approve"]'
            )
            ->form();

        /*
     * Avant la soumission, le compte repasse dans l'état
     * que l'on veut réellement tester.
     */
        $target->setVerified(false);
        $this->entityManager->flush();

        /*
     * Le formulaire possède toujours le vrai token CSRF
     * généré pour approve-user-{id}.
     */
        $this->client->submit($form);

        self::assertResponseRedirects('/admin/user/');

        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);
        self::assertFalse($reloadedUser->isVerified());
        self::assertFalse($reloadedUser->isApproved());
    }

    public function testAdminCannotDeleteUserByCallingRouteDirectly(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $target = $this->createUser();

        $id = $target->getId();

        $this->client->loginUser($admin);

        /*
     * On appelle volontairement directement la route.
     *
     * Le token n'a pas besoin d'être valide :
     * #[IsGranted('ROLE_SUPER_ADMIN')] doit bloquer l'ADMIN
     * avant même l'exécution de la méthode delete().
     */
        $this->client->request(
            'POST',
            '/admin/user/' . $id,
            [
                '_token' => 'irrelevant-for-authorization-test',
            ]
        );

        self::assertResponseRedirects('/access-denied');

        $this->entityManager->clear();

        self::assertNotNull(
            $this->entityManager->find(
                User::class,
                $id
            )
        );
    }

    public function testSuperAdminCanDeactivateUser(): void
    {
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');
        $target = $this->createUser();

        $id = $target->getId();
        $username = $target->getUsername();

        self::assertNotNull($id);

        /*
     * Le compte est actif avant l'action.
     */
        self::assertFalse($target->isDeleted());
        self::assertNull($target->getDeletedAt());

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/'
        );

        self::assertResponseIsSuccessful();

        /*
     * On retrouve précisément la ligne du compte cible.
     */
        $userRow = $crawler
            ->filter('table.users-table tbody tr')
            ->reduce(
                static function ($node) use ($username): bool {
                    return str_contains(
                        $node->text(),
                        $username
                    );
                }
            );

        self::assertCount(
            1,
            $userRow
        );

        /*
     * Un SUPER_ADMIN doit disposer du bouton
     * de désactivation pour un compte actif.
     */
        $deactivateForm = $userRow
            ->filter(
                'form[action="/admin/user/' . $id . '"]'
            );

        self::assertCount(
            1,
            $deactivateForm
        );

        self::assertCount(
            1,
            $deactivateForm->filter('button.btn-deactivate')
        );

        /*
     * On soumet le véritable formulaire afin d'utiliser
     * le token CSRF généré par l'application.
     */
        $this->client->submit(
            $deactivateForm->form()
        );

        self::assertResponseRedirects('/admin/user/');

        /*
     * On vide l'EntityManager pour forcer une nouvelle
     * lecture depuis la base de données.
     */
        $this->entityManager->clear();

        $deactivatedUser = $this->entityManager->find(
            User::class,
            $id
        );

        /*
     * Soft delete :
     *
     * le User doit toujours exister en base.
     */
        self::assertNotNull($deactivatedUser);

        /*
     * En revanche, deletedAt doit maintenant être renseigné.
     */
        self::assertTrue(
            $deactivatedUser->isDeleted()
        );

        self::assertNotNull(
            $deactivatedUser->getDeletedAt()
        );

        /*
     * On suit la redirection vers la liste afin de vérifier
     * également le résultat réellement présenté par le front.
     */
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();

        $deactivatedRow = $crawler
            ->filter('table.users-table tbody tr')
            ->reduce(
                static function ($node) use ($username): bool {
                    return str_contains(
                        $node->text(),
                        $username
                    );
                }
            );

        self::assertCount(
            1,
            $deactivatedRow
        );

        /*
     * Le compte reste dans la liste, mais son nouvel état
     * doit être clairement affiché.
     */
        self::assertStringContainsString(
            'Compte désactivé',
            $deactivatedRow->text()
        );

        /*
     * Un compte déjà désactivé ne doit plus proposer
     * l'action de désactivation.
     *
     * La réactivation est volontairement hors périmètre
     * pour le lancement en production.
     */
        self::assertCount(
            0,
            $deactivatedRow->filter('button.btn-deactivate')
        );
    }

    public function testAdminCannotSeeSuperAdminRoleChoice(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $target = $this->createUser('ROLE_USER');

        $id = $target->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/' . $id . '/edit'
        );

        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter(
                'input[name="user_roles[roles][]"][value="ROLE_SUPER_ADMIN"]'
            )
        );
    }

    public function testSuperAdminCanSeeSuperAdminRoleChoice(): void
    {
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');
        $target = $this->createUser('ROLE_USER');

        $id = $target->getId();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/' . $id . '/edit'
        );

        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter(
                'input[name="user_roles[roles][]"][value="ROLE_SUPER_ADMIN"]'
            )
        );
    }

    public function testAdminCannotAssignSuperAdminRoleWithForgedPost(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');
        $target = $this->createUser('ROLE_USER');

        $id = $target->getId();

        $this->client->loginUser($admin);

        /*
     * On charge d'abord le véritable formulaire afin
     * de récupérer son token CSRF.
     */
        $crawler = $this->client->request(
            'GET',
            '/admin/user/' . $id . '/edit'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Enregistrer')
            ->form();

        /*
     * On forge ensuite la requête HTTP en ajoutant
     * ROLE_SUPER_ADMIN alors que ce choix n'est pas
     * proposé à un ADMIN.
     */
        $values = $form->getPhpValues();

        $values['user_roles']['roles'] = [
            'ROLE_USER',
            'ROLE_ADMIN',
            'ROLE_SUPER_ADMIN',
        ];

        $this->client->request(
            'POST',
            '/admin/user/' . $id . '/edit',
            $values
        );

        /*
     * Le formulaire doit être refusé :
     * ROLE_SUPER_ADMIN n'est pas un choix autorisé
     * pour un ADMIN.
     */
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);

        self::assertNotContains(
            'ROLE_SUPER_ADMIN',
            $reloadedUser->getRoles()
        );
    }

    public function testAdminCannotRemoveSuperAdminRoleFromExistingSuperAdmin(): void
    {
        $admin = $this->createUser('ROLE_ADMIN');

        $target = $this->createUser('ROLE_SUPER_ADMIN');
        $id = $target->getId();

        $this->client->loginUser($admin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/' . $id . '/edit'
        );

        self::assertResponseIsSuccessful();

        /*
     * Un ADMIN ne dispose pas du choix ROLE_SUPER_ADMIN.
     */
        self::assertCount(
            0,
            $crawler->filter(
                'input[name="user_roles[roles][]"][value="ROLE_SUPER_ADMIN"]'
            )
        );

        /*
     * On récupère le véritable formulaire afin de conserver
     * notamment son token CSRF.
     */
        $form = $crawler
            ->selectButton('Enregistrer')
            ->form();

        $values = $form->getPhpValues();

        /*
     * L'ADMIN modifie les rôles ordinaires.
     *
     * ROLE_SUPER_ADMIN est volontairement absent du POST,
     * exactement comme lors d'une soumission normale puisque
     * ce choix ne lui est pas présenté.
     */
        $values['user_roles']['roles'] = [
            'ROLE_USER',
            'ROLE_EDITOR',
        ];

        $this->client->request(
            'POST',
            '/admin/user/' . $id . '/edit',
            $values
        );

        self::assertResponseRedirects('/admin/user/');

        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);

        /*
     * La modification d'un rôle ordinaire a bien été prise
     * en compte.
     */
        self::assertContains(
            'ROLE_EDITOR',
            $reloadedUser->getRoles()
        );

        /*
     * Mais ROLE_SUPER_ADMIN a été préservé par le contrôleur.
     */
        self::assertContains(
            'ROLE_SUPER_ADMIN',
            $reloadedUser->getRoles()
        );
    }

    public function testSuperAdminCanAssignSuperAdminRole(): void
    {
        $superAdmin = $this->createUser('ROLE_SUPER_ADMIN');
        $target = $this->createUser('ROLE_USER');

        $id = $target->getId();

        $this->client->loginUser($superAdmin);

        $crawler = $this->client->request(
            'GET',
            '/admin/user/' . $id . '/edit'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->selectButton('Enregistrer')
            ->form();

        $values = $form->getPhpValues();

        $values['user_roles']['roles'] = [
            'ROLE_USER',
            'ROLE_SUPER_ADMIN',
        ];

        $this->client->request(
            'POST',
            '/admin/user/' . $id . '/edit',
            $values
        );

        self::assertResponseRedirects('/admin/user/');

        $this->entityManager->clear();

        $reloadedUser = $this->entityManager->find(
            User::class,
            $id
        );

        self::assertNotNull($reloadedUser);
        self::assertContains(
            'ROLE_SUPER_ADMIN',
            $reloadedUser->getRoles()
        );
    }
}

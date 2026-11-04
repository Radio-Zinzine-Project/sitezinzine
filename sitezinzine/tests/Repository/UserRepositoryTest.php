<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(UserRepository::class);

        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }

        $this->em->close();

        parent::tearDown();
    }

    public function testUpgradePasswordUpdatesAndFlushesUserPassword(): void
    {
        $user = $this->createUser();

        $this->em->flush();

        $id = $user->getId();

        $newPassword = 'nouveau-hash-' . $this->unique();

        $this->repository->upgradePassword(
            $user,
            $newPassword
        );

        self::assertSame(
            $newPassword,
            $user->getPassword()
        );

        /*
         * On vide l'EntityManager pour forcer Doctrine à relire
         * l'utilisateur depuis la BDD.
         *
         * Cela vérifie que upgradePassword() a bien appelé flush().
         */
        $this->em->clear();

        $reloadedUser = $this->repository->find($id);

        self::assertNotNull($reloadedUser);
        self::assertSame(
            $newPassword,
            $reloadedUser->getPassword()
        );
    }

    public function testUpgradePasswordThrowsExceptionForUnsupportedUser(): void
    {
        $unsupportedUser = new class implements PasswordAuthenticatedUserInterface {
            public function getPassword(): ?string
            {
                return 'password';
            }
        };

        $this->expectException(UnsupportedUserException::class);
        $this->expectExceptionMessage('are not supported');

        $this->repository->upgradePassword(
            $unsupportedUser,
            'nouveau-password'
        );
    }

    private function createUser(): User
    {
        $unique = $this->unique();

        $user = (new User())
            ->setUsername('user_' . $unique)
            ->setEmail('user_' . $unique . '@test.fr')
            ->setPassword('ancien-hash-' . $unique)
            ->setRoles(['ROLE_USER'])
            ->setVerified(true);

        $this->em->persist($user);

        return $user;
    }

    private function unique(): string
    {
        return str_replace('.', '', uniqid('', true));
    }
}
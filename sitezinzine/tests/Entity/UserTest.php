<?php

namespace App\Tests\Entity;

use App\Entity\Categories;
use App\Entity\Emission;
use App\Entity\Evenement;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class UserTest extends TestCase
{
    public function testInitialValues(): void
    {
        $user = new User();

        $this->assertNull($user->getId());
        $this->assertNull($user->getUsername());
        $this->assertSame('', $user->getUserIdentifier());
        $this->assertNull($user->getEmail());
        $this->assertNull($user->getPendingEmail());
        $this->assertNull($user->getPseudo());
        $this->assertFalse($user->isVerified());
        $this->assertNull($user->getDeletedAt());
        $this->assertFalse($user->isDeleted());

        $this->assertSame(['ROLE_USER'], $user->getRoles());

        $this->assertCount(0, $user->getEmissions());
        $this->assertCount(0, $user->getEvenements());
        $this->assertCount(0, $user->getCategories());
    }

    public function testBasicGettersAndSetters(): void
    {
        $user = new User();

        $result = $user
            ->setUsername('testuser')
            ->setEmail('test@example.com')
            ->setPassword('secure_password')
            ->setVerified(true);

        $this->assertSame($user, $result);
        $this->assertSame('testuser', $user->getUsername());
        $this->assertSame('testuser', $user->getUserIdentifier());
        $this->assertSame('test@example.com', $user->getEmail());
        $this->assertSame('secure_password', $user->getPassword());
        $this->assertTrue($user->isVerified());
    }

    public function testRolesAlwaysContainRoleUser(): void
    {
        $user = new User();

        $user->setRoles(['ROLE_ADMIN']);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles);
    }

    public function testVerifiedUserGetsRoleVerified(): void
    {
        $user = new User();

        $user
            ->setRoles(['ROLE_ADMIN'])
            ->setVerified(true);

        $roles = $user->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_VERIFIED', $roles);
    }

    public function testUnverifiedUserDoesNotGetRoleVerified(): void
    {
        $user = new User();

        $user
            ->setRoles(['ROLE_ADMIN'])
            ->setVerified(false);

        $this->assertNotContains(
            'ROLE_VERIFIED',
            $user->getRoles()
        );
    }

    public function testRolesAreUnique(): void
    {
        $user = new User();

        $user->setRoles([
            'ROLE_USER',
            'ROLE_ADMIN',
            'ROLE_ADMIN',
        ]);

        $roles = $user->getRoles();

        $this->assertCount(
            1,
            array_keys($roles, 'ROLE_USER', true)
        );

        $this->assertCount(
            1,
            array_keys($roles, 'ROLE_ADMIN', true)
        );
    }

    public function testPseudoIsTrimmed(): void
    {
        $user = new User();

        $result = $user->setPseudo('  Eloise  ');

        $this->assertSame($user, $result);
        $this->assertSame('Eloise', $user->getPseudo());
    }

    public function testPseudoCanBeNull(): void
    {
        $user = new User();

        $user->setPseudo('Eloise');
        $user->setPseudo(null);

        $this->assertNull($user->getPseudo());
    }

    public function testDisplayNameUsesPseudoWhenAvailable(): void
    {
        $user = new User();

        $user
            ->setUsername('drelin04')
            ->setPseudo('Eloise');

        $this->assertSame('Eloise', $user->getDisplayName());
    }

    public function testDisplayNameFallsBackToUsername(): void
    {
        $user = new User();

        $user->setUsername('drelin04');

        $this->assertSame(
            'drelin04',
            $user->getDisplayName()
        );
    }

    public function testPendingEmailIsTrimmed(): void
    {
        $user = new User();

        $result = $user->setPendingEmail(
            '  nouveau@example.com  '
        );

        $this->assertSame($user, $result);

        $this->assertSame(
            'nouveau@example.com',
            $user->getPendingEmail()
        );
    }

    public function testPendingEmailCanBeNull(): void
    {
        $user = new User();

        $user->setPendingEmail('nouveau@example.com');
        $user->setPendingEmail(null);

        $this->assertNull($user->getPendingEmail());
    }

    public function testAddEmissionUpdatesBothSides(): void
    {
        $user = new User();
        $emission = new Emission();

        $result = $user->addEmission($emission);

        $this->assertSame($user, $result);
        $this->assertCount(1, $user->getEmissions());

        $this->assertTrue(
            $user->getEmissions()->contains($emission)
        );

        $this->assertTrue(
            $emission->getUsers()->contains($user)
        );
    }

    public function testAddingSameEmissionTwiceDoesNotDuplicateIt(): void
    {
        $user = new User();
        $emission = new Emission();

        $user->addEmission($emission);
        $user->addEmission($emission);

        $this->assertCount(1, $user->getEmissions());
        $this->assertCount(1, $emission->getUsers());
    }

    public function testRemoveEmissionUpdatesBothSides(): void
    {
        $user = new User();
        $emission = new Emission();

        $user->addEmission($emission);

        $result = $user->removeEmission($emission);

        $this->assertSame($user, $result);
        $this->assertCount(0, $user->getEmissions());

        $this->assertFalse(
            $emission->getUsers()->contains($user)
        );
    }

    public function testRemovingUnknownEmissionDoesNothing(): void
    {
        $user = new User();
        $emission = new Emission();

        $result = $user->removeEmission($emission);

        $this->assertSame($user, $result);
        $this->assertCount(0, $user->getEmissions());
    }

    public function testAddEvenementSetsOwningSide(): void
    {
        $user = new User();
        $evenement = new Evenement();

        $result = $user->addEvenement($evenement);

        $this->assertSame($user, $result);
        $this->assertCount(1, $user->getEvenements());

        $this->assertTrue(
            $user->getEvenements()->contains($evenement)
        );

        $this->assertSame(
            $user,
            $evenement->getUser()
        );
    }

    public function testAddingSameEvenementTwiceDoesNotDuplicateIt(): void
    {
        $user = new User();
        $evenement = new Evenement();

        $user->addEvenement($evenement);
        $user->addEvenement($evenement);

        $this->assertCount(1, $user->getEvenements());
        $this->assertSame($user, $evenement->getUser());
    }

    public function testRemoveEvenementClearsOwningSide(): void
    {
        $user = new User();
        $evenement = new Evenement();

        $user->addEvenement($evenement);

        $result = $user->removeEvenement($evenement);

        $this->assertSame($user, $result);
        $this->assertCount(0, $user->getEvenements());
        $this->assertNull($evenement->getUser());
    }

    public function testRemoveEvenementDoesNotClearAnotherUser(): void
    {
        $user = new User();
        $otherUser = new User();
        $evenement = new Evenement();

        $user->addEvenement($evenement);

        $evenement->setUser($otherUser);

        $user->removeEvenement($evenement);

        $this->assertCount(0, $user->getEvenements());

        $this->assertSame(
            $otherUser,
            $evenement->getUser()
        );
    }

    public function testRemovingUnknownEvenementDoesNothing(): void
    {
        $user = new User();
        $evenement = new Evenement();

        $result = $user->removeEvenement($evenement);

        $this->assertSame($user, $result);
        $this->assertCount(0, $user->getEvenements());
        $this->assertNull($evenement->getUser());
    }

    public function testAddCategoryUpdatesBothSides(): void
    {
        $user = new User();
        $category = new Categories();

        $result = $user->addCategory($category);

        $this->assertSame($user, $result);
        $this->assertCount(1, $user->getCategories());

        $this->assertTrue(
            $user->getCategories()->contains($category)
        );

        $this->assertTrue(
            $category->getUsers()->contains($user)
        );
    }

    public function testAddingSameCategoryTwiceDoesNotDuplicateIt(): void
    {
        $user = new User();
        $category = new Categories();

        $user->addCategory($category);
        $user->addCategory($category);

        $this->assertCount(1, $user->getCategories());
        $this->assertCount(1, $category->getUsers());
    }

    public function testRemoveCategoryUpdatesBothSides(): void
    {
        $user = new User();
        $category = new Categories();

        $user->addCategory($category);

        $result = $user->removeCategory($category);

        $this->assertSame($user, $result);
        $this->assertCount(0, $user->getCategories());

        $this->assertFalse(
            $category->getUsers()->contains($user)
        );
    }

    public function testRemovingUnknownCategoryDoesNothing(): void
    {
        $user = new User();
        $category = new Categories();

        $result = $user->removeCategory($category);

        $this->assertSame($user, $result);
        $this->assertCount(0, $user->getCategories());
    }

    public function testPseudoTooShortIsInvalid(): void
    {
        $user = new User();

        $user->setPseudo('A');

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validateProperty($user, 'pseudo');

        $this->assertHasViolationOnProperty(
            $violations,
            'pseudo'
        );
    }

    public function testPseudoTooLongIsInvalid(): void
    {
        $user = new User();

        $user->setPseudo(str_repeat('A', 61));

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validateProperty($user, 'pseudo');

        $this->assertHasViolationOnProperty(
            $violations,
            'pseudo'
        );
    }

    public function testPseudoWithForbiddenCharactersIsInvalid(): void
    {
        $user = new User();

        $user->setPseudo('Eloise@Radio');

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validateProperty($user, 'pseudo');

        $this->assertHasViolation(
            $violations,
            'pseudo',
            'Le pseudo contient des caractères non autorisés.'
        );
    }

    public function testValidPseudoHasNoViolation(): void
    {
        $user = new User();

        $user->setPseudo('Eloise-Radio_04');

        $violations = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
            ->validateProperty($user, 'pseudo');

        $this->assertCount(0, $violations);
    }

    public function testUserCanBeDeactivated(): void
    {
        $user = new User();

        $result = $user->deactivate();

        $this->assertSame($user, $result);
        $this->assertTrue($user->isDeleted());
        $this->assertInstanceOf(
            \DateTimeImmutable::class,
            $user->getDeletedAt()
        );
    }

    public function testDeactivatingAlreadyDeletedUserKeepsOriginalDeletionDate(): void
    {
        $user = new User();

        $user->deactivate();

        $deletedAt = $user->getDeletedAt();

        $user->deactivate();

        $this->assertSame(
            $deletedAt,
            $user->getDeletedAt()
        );

        $this->assertTrue($user->isDeleted());
    }

    public function testUserCanBeReactivated(): void
    {
        $user = new User();

        $user->deactivate();

        $result = $user->reactivate();

        $this->assertSame($user, $result);
        $this->assertNull($user->getDeletedAt());
        $this->assertFalse($user->isDeleted());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = new User();

        $user
            ->setUsername('testuser')
            ->setPassword('secure_password');

        $user->eraseCredentials();

        $this->assertSame(
            'secure_password',
            $user->getPassword()
        );

        $this->assertSame(
            'testuser',
            $user->getUsername()
        );
    }

    private function assertHasViolationOnProperty(
        iterable $violations,
        string $property
    ): void {
        foreach ($violations as $violation) {
            if ($violation->getPropertyPath() === $property) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail(
            sprintf(
                'Aucune violation trouvée sur la propriété "%s".',
                $property
            )
        );
    }

    private function assertHasViolation(
        iterable $violations,
        string $property,
        string $message
    ): void {
        foreach ($violations as $violation) {
            if (
                $violation->getPropertyPath() === $property
                && $violation->getMessage() === $message
            ) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail(
            sprintf(
                'Violation attendue introuvable sur "%s" : %s',
                $property,
                $message
            )
        );
    }
}

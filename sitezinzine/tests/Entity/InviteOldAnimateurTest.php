<?php

namespace App\Tests\Entity;

use App\Entity\Categories;
use App\Entity\Emission;
use App\Entity\InviteOldAnimateur;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class InviteOldAnimateurTest extends TestCase
{
    public function testInitialValues(): void
    {
        $invite = new InviteOldAnimateur();

        $this->assertNull($invite->getId());
        $this->assertNull($invite->getFirstName());
        $this->assertNull($invite->getLastName());
        $this->assertNull($invite->getPhoneNumber());
        $this->assertNull($invite->getMail());
        $this->assertNull($invite->isAncienanimateur());

        $this->assertCount(0, $invite->getEmissions());
        $this->assertCount(0, $invite->getCategories());

        $this->assertSame('Invité #—', (string) $invite);
    }

    public function testGettersAndSetters(): void
    {
        $invite = new InviteOldAnimateur();

        $result = $invite
            ->setFirstName('Alice')
            ->setLastName('Dupont')
            ->setPhoneNumber('0612345678')
            ->setMail('alice@example.com')
            ->setAncienanimateur(true);

        $this->assertSame($invite, $result);
        $this->assertSame('Alice', $invite->getFirstName());
        $this->assertSame('Dupont', $invite->getLastName());
        $this->assertSame('0612345678', $invite->getPhoneNumber());
        $this->assertSame('alice@example.com', $invite->getMail());
        $this->assertTrue($invite->isAncienanimateur());
    }

    public function testNullableFields(): void
    {
        $invite = new InviteOldAnimateur();

        $invite
            ->setFirstName('Alice')
            ->setLastName('Dupont')
            ->setPhoneNumber('0612345678')
            ->setMail('alice@example.com')
            ->setAncienanimateur(true);

        $invite
            ->setFirstName(null)
            ->setLastName(null)
            ->setPhoneNumber(null)
            ->setMail(null)
            ->setAncienanimateur(null);

        $this->assertNull($invite->getFirstName());
        $this->assertNull($invite->getLastName());
        $this->assertNull($invite->getPhoneNumber());
        $this->assertNull($invite->getMail());
        $this->assertNull($invite->isAncienanimateur());
    }

    public function testToStringWithFirstAndLastName(): void
    {
        $invite = new InviteOldAnimateur();

        $invite
            ->setFirstName('Alice')
            ->setLastName('Dupont');

        $this->assertSame('Alice Dupont', (string) $invite);
    }

    public function testToStringWithFirstNameOnly(): void
    {
        $invite = new InviteOldAnimateur();

        $invite->setFirstName('Alice');

        $this->assertSame('Alice', (string) $invite);
    }

    public function testToStringWithLastNameOnly(): void
    {
        $invite = new InviteOldAnimateur();

        $invite->setLastName('Dupont');

        $this->assertSame('Dupont', (string) $invite);
    }

    public function testAddEmissionUpdatesBothSides(): void
    {
        $invite = new InviteOldAnimateur();

        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->once())
            ->method('addInviteOldAnimateur')
            ->with($invite);

        $result = $invite->addEmission($emission);

        $this->assertSame($invite, $result);
        $this->assertCount(1, $invite->getEmissions());
        $this->assertTrue(
            $invite->getEmissions()->contains($emission)
        );
    }

    public function testAddingSameEmissionTwiceDoesNotDuplicateIt(): void
    {
        $invite = new InviteOldAnimateur();

        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->once())
            ->method('addInviteOldAnimateur')
            ->with($invite);

        $invite->addEmission($emission);
        $invite->addEmission($emission);

        $this->assertCount(1, $invite->getEmissions());
    }

    public function testRemoveEmissionUpdatesBothSides(): void
    {
        $invite = new InviteOldAnimateur();

        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->once())
            ->method('addInviteOldAnimateur')
            ->with($invite);

        $emission
            ->expects($this->once())
            ->method('removeInviteOldAnimateur')
            ->with($invite);

        $invite->addEmission($emission);

        $result = $invite->removeEmission($emission);

        $this->assertSame($invite, $result);
        $this->assertCount(0, $invite->getEmissions());
    }

    public function testRemovingUnknownEmissionDoesNothing(): void
    {
        $invite = new InviteOldAnimateur();

        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->never())
            ->method('removeInviteOldAnimateur');

        $result = $invite->removeEmission($emission);

        $this->assertSame($invite, $result);
        $this->assertCount(0, $invite->getEmissions());
    }

    public function testAddCategoryUpdatesBothSides(): void
    {
        $invite = new InviteOldAnimateur();

        $category = $this->createMock(Categories::class);

        $category
            ->expects($this->once())
            ->method('addInviteOldAnimateur')
            ->with($invite);

        $result = $invite->addCategory($category);

        $this->assertSame($invite, $result);
        $this->assertCount(1, $invite->getCategories());
        $this->assertTrue(
            $invite->getCategories()->contains($category)
        );
    }

    public function testAddingSameCategoryTwiceDoesNotDuplicateIt(): void
    {
        $invite = new InviteOldAnimateur();

        $category = $this->createMock(Categories::class);

        $category
            ->expects($this->once())
            ->method('addInviteOldAnimateur')
            ->with($invite);

        $invite->addCategory($category);
        $invite->addCategory($category);

        $this->assertCount(1, $invite->getCategories());
    }

    public function testRemoveCategoryUpdatesBothSides(): void
    {
        $invite = new InviteOldAnimateur();

        $category = $this->createMock(Categories::class);

        $category
            ->expects($this->once())
            ->method('addInviteOldAnimateur')
            ->with($invite);

        $category
            ->expects($this->once())
            ->method('removeInviteOldAnimateur')
            ->with($invite);

        $invite->addCategory($category);

        $result = $invite->removeCategory($category);

        $this->assertSame($invite, $result);
        $this->assertCount(0, $invite->getCategories());
    }

    public function testRemovingUnknownCategoryDoesNothing(): void
    {
        $invite = new InviteOldAnimateur();

        $category = $this->createMock(Categories::class);

        $category
            ->expects($this->never())
            ->method('removeInviteOldAnimateur');

        $result = $invite->removeCategory($category);

        $this->assertSame($invite, $result);
        $this->assertCount(0, $invite->getCategories());
    }

    public function testFirstNameIsRequired(): void
    {
        $invite = new InviteOldAnimateur();

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($invite);

        $found = false;

        foreach ($violations as $violation) {
            if (
                $violation->getPropertyPath() === 'firstName'
                && $violation->getMessage() === 'Le prénom est obligatoire.'
            ) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'La violation NotBlank attendue sur firstName est absente.'
        );
    }

    public function testWhitespaceOnlyFirstNameIsInvalid(): void
    {
        $invite = new InviteOldAnimateur();

        $invite->setFirstName('   ');

        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($invite);

        $found = false;

        foreach ($violations as $violation) {
            if (
                $violation->getPropertyPath() === 'firstName'
                && $violation->getMessage() === 'Le prénom est obligatoire.'
            ) {
                $found = true;
                break;
            }
        }

        $this->assertTrue(
            $found,
            'Un prénom composé uniquement d’espaces devrait être invalide.'
        );
    }
}
<?php

namespace App\Tests\Entity;

use App\Entity\CategorieTagImage;
use App\Entity\Categories;
use App\Entity\Editeur;
use App\Entity\Emission;
use App\Entity\InviteOldAnimateur;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class CategorieTest extends TestCase
{
    public function testInitialValues(): void
    {
        $categorie = new Categories();

        $this->assertNull($categorie->getId());
        $this->assertSame('', $categorie->getTitre());
        $this->assertNull($categorie->getOldid());
        $this->assertNull($categorie->getEditeur());
        $this->assertNull($categorie->getDuree());
        $this->assertNull($categorie->getDescriptif());
        $this->assertNull($categorie->getThumbnail());
        $this->assertNull($categorie->getThumbnailFile());
        $this->assertNull($categorie->getUpdatedAt());
        $this->assertNull($categorie->getSlug());
        $this->assertNull($categorie->isActive());
        $this->assertNull($categorie->isSoftDelete());

        $this->assertCount(0, $categorie->getEmissions());
        $this->assertCount(0, $categorie->getUsers());
        $this->assertCount(0, $categorie->getInviteOldAnimateurs());
        $this->assertCount(0, $categorie->getTagImages());
    }

    public function testGettersAndSetters(): void
    {
        $categorie = new Categories();
        $editeur = $this->createMock(Editeur::class);
        $date = new \DateTime('2026-09-06 12:00:00');

        $categorie
            ->setTitre('Catégorie Libre')
            ->setOldid(10)
            ->setEditeur($editeur)
            ->setDuree(45)
            ->setDescriptif('Une catégorie intéressante')
            ->setThumbnail('thumb.jpg')
            ->setUpdatedAt($date)
            ->setActive(true)
            ->setSoftDelete(false);

        $this->assertSame('Catégorie Libre', $categorie->getTitre());
        $this->assertSame(10, $categorie->getOldid());
        $this->assertSame($editeur, $categorie->getEditeur());
        $this->assertSame(45, $categorie->getDuree());
        $this->assertSame(
            'Une catégorie intéressante',
            $categorie->getDescriptif()
        );
        $this->assertSame('thumb.jpg', $categorie->getThumbnail());
        $this->assertSame($date, $categorie->getUpdatedAt());
        $this->assertTrue($categorie->isActive());
        $this->assertFalse($categorie->isSoftDelete());
    }

    public function testEditeurCanBeNull(): void
    {
        $categorie = new Categories();
        $editeur = $this->createMock(Editeur::class);

        $categorie->setEditeur($editeur);
        $this->assertSame($editeur, $categorie->getEditeur());

        $categorie->setEditeur(null);
        $this->assertNull($categorie->getEditeur());
    }

    public function testThumbnailCanBeNull(): void
    {
        $categorie = new Categories();

        $categorie->setThumbnail('thumb.jpg');
        $this->assertSame('thumb.jpg', $categorie->getThumbnail());

        $categorie->setThumbnail(null);
        $this->assertNull($categorie->getThumbnail());
    }

    public function testSetThumbnailFileSetsUpdatedAt(): void
    {
        $file = $this->createMock(File::class);
        $categorie = new Categories();

        $this->assertNull($categorie->getUpdatedAt());

        $categorie->setThumbnailFile($file);

        $this->assertSame($file, $categorie->getThumbnailFile());
        $this->assertInstanceOf(
            \DateTime::class,
            $categorie->getUpdatedAt()
        );
    }

    public function testSetThumbnailFileNullDoesNotSetUpdatedAt(): void
    {
        $categorie = new Categories();

        $categorie->setThumbnailFile(null);

        $this->assertNull($categorie->getThumbnailFile());
        $this->assertNull($categorie->getUpdatedAt());
    }

    public function testSlugIsTrimmedAndUppercased(): void
    {
        $categorie = new Categories();

        $categorie->setSlug('  abc  ');

        $this->assertSame('ABC', $categorie->getSlug());
    }

    public function testEmptySlugBecomesNull(): void
    {
        $categorie = new Categories();

        $categorie->setSlug('   ');

        $this->assertNull($categorie->getSlug());

        $categorie->setSlug(null);

        $this->assertNull($categorie->getSlug());
    }

    public function testActiveAndSoftDeleteStates(): void
    {
        $categorie = new Categories();

        $categorie->setActive(true);
        $categorie->setSoftDelete(true);

        $this->assertTrue($categorie->isActive());
        $this->assertTrue($categorie->isSoftDelete());

        $categorie->setActive(false);
        $categorie->setSoftDelete(false);

        $this->assertFalse($categorie->isActive());
        $this->assertFalse($categorie->isSoftDelete());
    }

    public function testAddEmissionSetsOwningSide(): void
    {
        $categorie = new Categories();
        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->once())
            ->method('setCategorie')
            ->with($categorie);

        $categorie->addEmission($emission);

        $this->assertCount(1, $categorie->getEmissions());
        $this->assertTrue(
            $categorie->getEmissions()->contains($emission)
        );
    }

    public function testAddingSameEmissionTwiceDoesNotDuplicateIt(): void
    {
        $categorie = new Categories();
        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->once())
            ->method('setCategorie')
            ->with($categorie);

        $categorie->addEmission($emission);
        $categorie->addEmission($emission);

        $this->assertCount(1, $categorie->getEmissions());
    }

    public function testRemoveEmissionClearsOwningSide(): void
    {
        $categorie = new Categories();
        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->exactly(2))
            ->method('setCategorie')
            ->withConsecutive(
                [$categorie],
                [null]
            );

        $emission
            ->expects($this->once())
            ->method('getCategorie')
            ->willReturn($categorie);

        $categorie->addEmission($emission);
        $categorie->removeEmission($emission);

        $this->assertCount(0, $categorie->getEmissions());
    }

    public function testRemoveEmissionDoesNotClearAnotherCategorie(): void
    {
        $categorie = new Categories();
        $otherCategorie = new Categories();

        $emission = $this->createMock(Emission::class);

        $emission
            ->expects($this->once())
            ->method('setCategorie')
            ->with($categorie);

        $emission
            ->expects($this->once())
            ->method('getCategorie')
            ->willReturn($otherCategorie);

        $categorie->addEmission($emission);
        $categorie->removeEmission($emission);

        $this->assertCount(0, $categorie->getEmissions());
    }

    public function testAddAndRemoveUsers(): void
    {
        $categorie = new Categories();

        $user1 = $this->createMock(User::class);
        $user2 = $this->createMock(User::class);

        $categorie->addUser($user1);
        $categorie->addUser($user2);

        $this->assertCount(2, $categorie->getUsers());
        $this->assertTrue($categorie->getUsers()->contains($user1));
        $this->assertTrue($categorie->getUsers()->contains($user2));

        $categorie->removeUser($user1);

        $this->assertCount(1, $categorie->getUsers());
        $this->assertFalse($categorie->getUsers()->contains($user1));
        $this->assertTrue($categorie->getUsers()->contains($user2));
    }

    public function testAddingSameUserTwiceDoesNotDuplicateIt(): void
    {
        $categorie = new Categories();
        $user = $this->createMock(User::class);

        $categorie->addUser($user);
        $categorie->addUser($user);

        $this->assertCount(1, $categorie->getUsers());
    }

    public function testAddAndRemoveInviteOldAnimateur(): void
    {
        $categorie = new Categories();
        $invite = $this->createMock(InviteOldAnimateur::class);

        $categorie->addInviteOldAnimateur($invite);

        $this->assertCount(1, $categorie->getInviteOldAnimateurs());
        $this->assertTrue(
            $categorie->getInviteOldAnimateurs()->contains($invite)
        );

        $categorie->removeInviteOldAnimateur($invite);

        $this->assertCount(0, $categorie->getInviteOldAnimateurs());
    }

    public function testAddingSameInviteTwiceDoesNotDuplicateIt(): void
    {
        $categorie = new Categories();
        $invite = $this->createMock(InviteOldAnimateur::class);

        $categorie->addInviteOldAnimateur($invite);
        $categorie->addInviteOldAnimateur($invite);

        $this->assertCount(1, $categorie->getInviteOldAnimateurs());
    }

    public function testAddTagImageSetsOwningSide(): void
    {
        $categorie = new Categories();
        $tagImage = $this->createMock(CategorieTagImage::class);

        $tagImage
            ->expects($this->once())
            ->method('setCategorie')
            ->with($categorie);

        $categorie->addTagImage($tagImage);

        $this->assertCount(1, $categorie->getTagImages());
        $this->assertTrue(
            $categorie->getTagImages()->contains($tagImage)
        );
    }

    public function testAddingSameTagImageTwiceDoesNotDuplicateIt(): void
    {
        $categorie = new Categories();
        $tagImage = $this->createMock(CategorieTagImage::class);

        $tagImage
            ->expects($this->once())
            ->method('setCategorie')
            ->with($categorie);

        $categorie->addTagImage($tagImage);
        $categorie->addTagImage($tagImage);

        $this->assertCount(1, $categorie->getTagImages());
    }

    public function testRemoveTagImageClearsOwningSide(): void
    {
        $categorie = new Categories();
        $tagImage = $this->createMock(CategorieTagImage::class);

        $tagImage
            ->expects($this->exactly(2))
            ->method('setCategorie')
            ->withConsecutive(
                [$categorie],
                [null]
            );

        $tagImage
            ->expects($this->once())
            ->method('getCategorie')
            ->willReturn($categorie);

        $categorie->addTagImage($tagImage);
        $categorie->removeTagImage($tagImage);

        $this->assertCount(0, $categorie->getTagImages());
    }

    public function testValidationPassesWithUser(): void
    {
        $categorie = new Categories();
        $user = $this->createMock(User::class);

        $categorie->addUser($user);

        $context = $this->createMock(
            ExecutionContextInterface::class
        );

        $context
            ->expects($this->never())
            ->method('buildViolation');

        $categorie->validateAtLeastOneOwner($context);
    }

    public function testValidationPassesWithFormerAnimator(): void
    {
        $categorie = new Categories();

        $invite = $this->createMock(InviteOldAnimateur::class);

        $invite
            ->expects($this->once())
            ->method('isAncienanimateur')
            ->willReturn(true);

        $categorie->addInviteOldAnimateur($invite);

        $context = $this->createMock(
            ExecutionContextInterface::class
        );

        $context
            ->expects($this->never())
            ->method('buildViolation');

        $categorie->validateAtLeastOneOwner($context);
    }

    public function testValidationFailsWithoutOwner(): void
    {
        $categorie = new Categories();

        $context = $this->createMock(
            ExecutionContextInterface::class
        );

        $globalBuilder = $this->createMock(
            ConstraintViolationBuilderInterface::class
        );

        $usersBuilder = $this->createMock(
            ConstraintViolationBuilderInterface::class
        );

        $invitesBuilder = $this->createMock(
            ConstraintViolationBuilderInterface::class
        );

        $message =
            'Vous devez sélectionner au moins un·e utilisateurice ' .
            'OU un·e ancien·ne animateur·ice.';

        $context
            ->expects($this->exactly(3))
            ->method('buildViolation')
            ->with($message)
            ->willReturnOnConsecutiveCalls(
                $globalBuilder,
                $usersBuilder,
                $invitesBuilder
            );

        $globalBuilder
            ->expects($this->once())
            ->method('addViolation');

        $usersBuilder
            ->expects($this->once())
            ->method('atPath')
            ->with('users')
            ->willReturnSelf();

        $usersBuilder
            ->expects($this->once())
            ->method('addViolation');

        $invitesBuilder
            ->expects($this->once())
            ->method('atPath')
            ->with('inviteOldAnimateurs')
            ->willReturnSelf();

        $invitesBuilder
            ->expects($this->once())
            ->method('addViolation');

        $categorie->validateAtLeastOneOwner($context);
    }

    public function testNonFormerInviteDoesNotSatisfyValidation(): void
    {
        $categorie = new Categories();

        $invite = $this->createMock(InviteOldAnimateur::class);

        $invite
            ->expects($this->once())
            ->method('isAncienanimateur')
            ->willReturn(false);

        $categorie->addInviteOldAnimateur($invite);

        $context = $this->createMock(
            ExecutionContextInterface::class
        );

        $globalBuilder = $this->createMock(
            ConstraintViolationBuilderInterface::class
        );

        $usersBuilder = $this->createMock(
            ConstraintViolationBuilderInterface::class
        );

        $invitesBuilder = $this->createMock(
            ConstraintViolationBuilderInterface::class
        );

        $context
            ->expects($this->exactly(3))
            ->method('buildViolation')
            ->willReturnOnConsecutiveCalls(
                $globalBuilder,
                $usersBuilder,
                $invitesBuilder
            );

        $globalBuilder
            ->method('addViolation');

        $usersBuilder
            ->method('atPath')
            ->willReturnSelf();

        $usersBuilder
            ->method('addViolation');

        $invitesBuilder
            ->method('atPath')
            ->willReturnSelf();

        $invitesBuilder
            ->method('addViolation');

        $categorie->validateAtLeastOneOwner($context);
    }
}
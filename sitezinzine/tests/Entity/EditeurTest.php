<?php

namespace App\Tests\Entity;

use App\Entity\Editeur;
use App\Entity\Emission;
use PHPUnit\Framework\TestCase;

class EditeurTest extends TestCase
{
    public function testInitialValues(): void
    {
        $editeur = new Editeur();

        $this->assertNull($editeur->getId());
        $this->assertNull($editeur->getName());
        $this->assertNull($editeur->getMail());
        $this->assertNull($editeur->getPhone());
        $this->assertNull($editeur->getUpdateAt());
        $this->assertCount(0, $editeur->getEmissions());
        $this->assertSame('', (string) $editeur);
    }

    public function testGettersAndSetters(): void
    {
        $editeur = new Editeur();
        $date = new \DateTime('2026-09-06 12:00:00');

        $result = $editeur
            ->setName('Nom de test')
            ->setMail('test@example.com')
            ->setPhone('0601020304')
            ->setUpdateAt($date);

        $this->assertSame($editeur, $result);
        $this->assertSame('Nom de test', $editeur->getName());
        $this->assertSame('test@example.com', $editeur->getMail());
        $this->assertSame('0601020304', $editeur->getPhone());
        $this->assertSame($date, $editeur->getUpdateAt());
    }

    public function testMailAndPhoneCanBeNull(): void
    {
        $editeur = new Editeur();

        $editeur
            ->setMail('test@example.com')
            ->setPhone('0601020304');

        $editeur
            ->setMail(null)
            ->setPhone(null);

        $this->assertNull($editeur->getMail());
        $this->assertNull($editeur->getPhone());
    }

    public function testToStringReturnsName(): void
    {
        $editeur = new Editeur();
        $editeur->setName('Radio Zinzine');

        $this->assertSame('Radio Zinzine', (string) $editeur);
    }

    public function testAddEmissionSetsOwningSide(): void
    {
        $editeur = new Editeur();
        $emission = new Emission();

        $result = $editeur->addEmission($emission);

        $this->assertSame($editeur, $result);
        $this->assertCount(1, $editeur->getEmissions());
        $this->assertTrue(
            $editeur->getEmissions()->contains($emission)
        );
        $this->assertSame($editeur, $emission->getEditeur());
    }

    public function testAddingSameEmissionTwiceDoesNotDuplicateIt(): void
    {
        $editeur = new Editeur();
        $emission = new Emission();

        $editeur->addEmission($emission);
        $editeur->addEmission($emission);

        $this->assertCount(1, $editeur->getEmissions());
        $this->assertSame($editeur, $emission->getEditeur());
    }

    public function testRemoveEmissionClearsOwningSide(): void
    {
        $editeur = new Editeur();
        $emission = new Emission();

        $editeur->addEmission($emission);

        $result = $editeur->removeEmission($emission);

        $this->assertSame($editeur, $result);
        $this->assertCount(0, $editeur->getEmissions());
        $this->assertNull($emission->getEditeur());
    }

    public function testRemoveEmissionDoesNotClearAnotherEditeur(): void
    {
        $editeur = new Editeur();
        $otherEditeur = new Editeur();
        $emission = new Emission();

        $editeur->addEmission($emission);

        // L'émission a entre-temps été rattachée à un autre éditeur.
        $emission->setEditeur($otherEditeur);

        $editeur->removeEmission($emission);

        $this->assertCount(0, $editeur->getEmissions());
        $this->assertSame($otherEditeur, $emission->getEditeur());
    }

    public function testRemovingUnknownEmissionDoesNothing(): void
    {
        $editeur = new Editeur();
        $emission = new Emission();

        $result = $editeur->removeEmission($emission);

        $this->assertSame($editeur, $result);
        $this->assertCount(0, $editeur->getEmissions());
        $this->assertNull($emission->getEditeur());
    }
}
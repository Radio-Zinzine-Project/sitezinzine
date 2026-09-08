<?php

namespace App\Tests\Entity;

use App\Entity\Annonce;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;

class AnnonceTest extends TestCase
{
    public function testInitialValues(): void
    {
        $annonce = new Annonce();

        $this->assertNull($annonce->getId());
        $this->assertNull($annonce->getTitre());
        $this->assertNull($annonce->getOrganisateur());
        $this->assertNull($annonce->getVille());
        $this->assertNull($annonce->getDepartement());
        $this->assertNull($annonce->getAdresse());
        $this->assertNull($annonce->getDateDebut());
        $this->assertNull($annonce->getDateFin());
        $this->assertNull($annonce->getHoraire());
        $this->assertNull($annonce->getPrix());
        $this->assertNull($annonce->getPresentation());
        $this->assertNull($annonce->getContact());
        $this->assertNull($annonce->getType());
        $this->assertNull($annonce->isValid());
        $this->assertNull($annonce->getUpdateAt());
        $this->assertNull($annonce->getThumbnail());
        $this->assertNull($annonce->getThumbnailFile());
        $this->assertNull($annonce->isSoftDelete());
    }

    public function testGettersAndSetters(): void
    {
        $annonce = new Annonce();

        $dateDebut = new \DateTime('2024-01-01');
        $dateFin = new \DateTime('2024-01-02');
        $updateAt = new \DateTime('2024-01-03');

        $annonce
            ->setTitre('Concert Libre')
            ->setOrganisateur('Zinzine Prod')
            ->setVille('Forcalquier')
            ->setDepartement('04')
            ->setAdresse('La Borie')
            ->setDateDebut($dateDebut)
            ->setDateFin($dateFin)
            ->setHoraire('20h')
            ->setPrix('10€')
            ->setPresentation('Présentation de test')
            ->setContact('contact@zinzine.org')
            ->setType('concert')
            ->setValid(true)
            ->setUpdateAt($updateAt)
            ->setThumbnail('annonce.jpg')
            ->setSoftDelete(true);

        $this->assertSame('Concert Libre', $annonce->getTitre());
        $this->assertSame('Zinzine Prod', $annonce->getOrganisateur());
        $this->assertSame('Forcalquier', $annonce->getVille());
        $this->assertSame('04', $annonce->getDepartement());
        $this->assertSame('La Borie', $annonce->getAdresse());
        $this->assertSame($dateDebut, $annonce->getDateDebut());
        $this->assertSame($dateFin, $annonce->getDateFin());
        $this->assertSame('20h', $annonce->getHoraire());
        $this->assertSame('10€', $annonce->getPrix());
        $this->assertSame(
            'Présentation de test',
            $annonce->getPresentation()
        );
        $this->assertSame(
            'contact@zinzine.org',
            $annonce->getContact()
        );
        $this->assertSame('concert', $annonce->getType());
        $this->assertTrue($annonce->isValid());
        $this->assertSame($updateAt, $annonce->getUpdateAt());
        $this->assertSame('annonce.jpg', $annonce->getThumbnail());
        $this->assertTrue($annonce->isSoftDelete());
    }

    public function testNullableBooleanStates(): void
    {
        $annonce = new Annonce();

        $annonce->setValid(true);
        $annonce->setSoftDelete(true);

        $this->assertTrue($annonce->isValid());
        $this->assertTrue($annonce->isSoftDelete());

        $annonce->setValid(false);
        $annonce->setSoftDelete(false);

        $this->assertFalse($annonce->isValid());
        $this->assertFalse($annonce->isSoftDelete());

        $annonce->setValid(null);
        $annonce->setSoftDelete(null);

        $this->assertNull($annonce->isValid());
        $this->assertNull($annonce->isSoftDelete());
    }

    public function testThumbnailIsTrimmed(): void
    {
        $annonce = new Annonce();

        $annonce->setThumbnail('  annonce.jpg  ');

        $this->assertSame('annonce.jpg', $annonce->getThumbnail());
    }

    public function testThumbnailCanBeNull(): void
    {
        $annonce = new Annonce();

        $annonce->setThumbnail('annonce.jpg');
        $this->assertSame('annonce.jpg', $annonce->getThumbnail());

        $annonce->setThumbnail(null);

        $this->assertNull($annonce->getThumbnail());
    }

    public function testSetThumbnailFileSetsUpdateAt(): void
    {
        $file = $this->createMock(File::class);
        $annonce = new Annonce();

        $this->assertNull($annonce->getUpdateAt());

        $annonce->setThumbnailFile($file);

        $this->assertSame($file, $annonce->getThumbnailFile());
        $this->assertInstanceOf(
            \DateTime::class,
            $annonce->getUpdateAt()
        );
    }

    public function testSetThumbnailFileNullDoesNotSetUpdateAt(): void
    {
        $annonce = new Annonce();

        $annonce->setThumbnailFile(null);

        $this->assertNull($annonce->getThumbnailFile());
        $this->assertNull($annonce->getUpdateAt());
    }
}
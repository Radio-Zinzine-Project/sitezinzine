<?php

namespace App\Tests\Entity;

use App\Entity\Evenement;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Mapping\Factory\LazyLoadingMetadataFactory;
use Symfony\Component\Validator\Mapping\Loader\AttributeLoader;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EvenementTest extends TestCase
{
    private function getValidator(): ValidatorInterface
    {
        $metadataFactory = new LazyLoadingMetadataFactory(
            new AttributeLoader()
        );

        $validatorFactory = new ConstraintValidatorFactory();

        return Validation::createValidatorBuilder()
            ->setMetadataFactory($metadataFactory)
            ->setConstraintValidatorFactory($validatorFactory)
            ->getValidator();
    }

    public function testInitialValues(): void
    {
        $evenement = new Evenement();

        $this->assertNull($evenement->getId());
        $this->assertNull($evenement->getTitre());
        $this->assertNull($evenement->getOrganisateur());
        $this->assertNull($evenement->getVille());
        $this->assertNull($evenement->getDepartement());
        $this->assertNull($evenement->getAdresse());
        $this->assertNull($evenement->getDateDebut());
        $this->assertNull($evenement->getDateFin());
        $this->assertNull($evenement->getHoraire());
        $this->assertNull($evenement->getPrix());
        $this->assertNull($evenement->getPresentation());
        $this->assertNull($evenement->getContact());
        $this->assertNull($evenement->getType());
        $this->assertNull($evenement->isValid());
        $this->assertNull($evenement->getUpdateAt());
        $this->assertNull($evenement->getThumbnail());
        $this->assertNull($evenement->getThumbnailFile());
        $this->assertNull($evenement->isSoftDelete());
        $this->assertNull($evenement->getUser());
    }

    public function testGettersAndSetters(): void
    {
        $evenement = new Evenement();

        $dateDebut = new \DateTime('2026-09-01 20:00:00');
        $dateFin = new \DateTime('2026-09-02 01:00:00');
        $updateAt = new \DateTime('2026-09-03 12:00:00');
        $user = new User();

        $result = $evenement
            ->setTitre('Festival')
            ->setOrganisateur('Org sympa')
            ->setVille('Paris')
            ->setDepartement('75')
            ->setAdresse('1 avenue des Champs')
            ->setDateDebut($dateDebut)
            ->setDateFin($dateFin)
            ->setHoraire('20h-23h')
            ->setPrix('Gratuit')
            ->setPresentation('Une belle présentation')
            ->setContact('email@example.com')
            ->setType('Concert')
            ->setValid(true)
            ->setUpdateAt($updateAt)
            ->setThumbnail('thumb.jpg')
            ->setSoftDelete(false)
            ->setUser($user);

        $this->assertSame($evenement, $result);

        $this->assertSame('Festival', $evenement->getTitre());
        $this->assertSame('Org sympa', $evenement->getOrganisateur());
        $this->assertSame('Paris', $evenement->getVille());
        $this->assertSame('75', $evenement->getDepartement());
        $this->assertSame(
            '1 avenue des Champs',
            $evenement->getAdresse()
        );
        $this->assertSame($dateDebut, $evenement->getDateDebut());
        $this->assertSame($dateFin, $evenement->getDateFin());
        $this->assertSame('20h-23h', $evenement->getHoraire());
        $this->assertSame('Gratuit', $evenement->getPrix());
        $this->assertSame(
            'Une belle présentation',
            $evenement->getPresentation()
        );
        $this->assertSame(
            'email@example.com',
            $evenement->getContact()
        );
        $this->assertSame('Concert', $evenement->getType());
        $this->assertTrue($evenement->isValid());
        $this->assertSame($updateAt, $evenement->getUpdateAt());
        $this->assertSame('thumb.jpg', $evenement->getThumbnail());
        $this->assertFalse($evenement->isSoftDelete());
        $this->assertSame($user, $evenement->getUser());
    }

    public function testNullableBooleanStates(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setValid(true)
            ->setSoftDelete(true);

        $this->assertTrue($evenement->isValid());
        $this->assertTrue($evenement->isSoftDelete());

        $evenement
            ->setValid(false)
            ->setSoftDelete(false);

        $this->assertFalse($evenement->isValid());
        $this->assertFalse($evenement->isSoftDelete());

        $evenement
            ->setValid(null)
            ->setSoftDelete(null);

        $this->assertNull($evenement->isValid());
        $this->assertNull($evenement->isSoftDelete());
    }

    public function testUserCanBeNull(): void
    {
        $evenement = new Evenement();
        $user = new User();

        $evenement->setUser($user);

        $this->assertSame($user, $evenement->getUser());

        $evenement->setUser(null);

        $this->assertNull($evenement->getUser());
    }

    public function testThumbnailIsTrimmed(): void
    {
        $evenement = new Evenement();

        $evenement->setThumbnail('  thumb.jpg  ');

        $this->assertSame('thumb.jpg', $evenement->getThumbnail());
    }

    public function testThumbnailCanBeNull(): void
    {
        $evenement = new Evenement();

        $evenement->setThumbnail('thumb.jpg');
        $this->assertSame('thumb.jpg', $evenement->getThumbnail());

        $evenement->setThumbnail(null);

        $this->assertNull($evenement->getThumbnail());
    }

    public function testSetThumbnailFileSetsUpdateAt(): void
    {
        $file = $this->createMock(File::class);
        $evenement = new Evenement();

        $this->assertNull($evenement->getUpdateAt());

        $evenement->setThumbnailFile($file);

        $this->assertSame(
            $file,
            $evenement->getThumbnailFile()
        );

        $this->assertInstanceOf(
            \DateTime::class,
            $evenement->getUpdateAt()
        );
    }

    public function testSetThumbnailFileNullDoesNotSetUpdateAt(): void
    {
        $evenement = new Evenement();

        $evenement->setThumbnailFile(null);

        $this->assertNull($evenement->getThumbnailFile());
        $this->assertNull($evenement->getUpdateAt());
    }

    public function testValidEvenement(): void
    {
        $evenement = (new Evenement())
            ->setTitre('Festival')
            ->setOrganisateur('Org sympa')
            ->setVille('Paris')
            ->setDepartement('75')
            ->setAdresse('1 avenue des Champs')
            ->setDateDebut(new \DateTime())
            ->setDateFin(new \DateTime('+1 day'))
            ->setHoraire('20h-23h')
            ->setPrix('Gratuit')
            ->setPresentation('Une belle présentation')
            ->setContact('email@example.com')
            ->setType('Concert')
            ->setValid(true)
            ->setUpdateAt(new \DateTime())
            ->setThumbnail('thumb.jpg')
            ->setSoftDelete(false)
            ->setUser(new User());

        $validator = $this->getValidator();
        $violations = $validator->validate($evenement);

        $this->assertCount(
            0,
            $violations,
            $this->formatViolations($violations)
        );
    }

    public function testTitreIsRequired(): void
    {
        $evenement = new Evenement();

        $validator = $this->getValidator();
        $violations = $validator->validate($evenement);

        $messages = [];

        foreach ($violations as $violation) {
            if ($violation->getPropertyPath() === 'titre') {
                $messages[] = $violation->getMessage();
            }
        }

        $this->assertNotEmpty($messages);
    }

    public function testTitreTooLong(): void
    {
        $evenement = new Evenement();
        $evenement->setTitre(str_repeat('A', 101));

        $validator = $this->getValidator();
        $violations = $validator->validate($evenement);

        $this->assertHasViolation(
            $violations,
            'titre',
            'Le titre ne doit pas dépasser 100 caractères.'
        );
    }

    public function testOrganisateurTooLong(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setTitre('Valide')
            ->setOrganisateur(str_repeat('B', 101));

        $validator = $this->getValidator();
        $violations = $validator->validate($evenement);

        $this->assertHasViolation(
            $violations,
            'organisateur',
            "L'organisateur ne doit pas dépasser 100 caractères."
        );
    }

    public function testVilleTooLong(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setTitre('Valide')
            ->setVille(str_repeat('V', 51));

        $violations = $this->getValidator()->validate($evenement);

        $this->assertHasViolation(
            $violations,
            'ville',
            'La ville ne doit pas dépasser 50 caractères.'
        );
    }

    public function testAdresseTooLong(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setTitre('Valide')
            ->setAdresse(str_repeat('A', 51));

        $violations = $this->getValidator()->validate($evenement);

        $this->assertHasViolation(
            $violations,
            'adresse',
            "L'adresse ne doit pas dépasser 50 caractères."
        );
    }

    public function testHoraireTooLong(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setTitre('Valide')
            ->setHoraire(str_repeat('H', 51));

        $violations = $this->getValidator()->validate($evenement);

        $this->assertHasViolation(
            $violations,
            'horaire',
            "L'horaire ne doit pas dépasser 50 caractères."
        );
    }

    public function testPrixTooLong(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setTitre('Valide')
            ->setPrix(str_repeat('P', 51));

        $violations = $this->getValidator()->validate($evenement);

        $this->assertHasViolation(
            $violations,
            'prix',
            'Le prix ne doit pas dépasser 50 caractères.'
        );
    }

    public function testContactTooLong(): void
    {
        $evenement = new Evenement();

        $evenement
            ->setTitre('Valide')
            ->setContact(str_repeat('C', 201));

        $violations = $this->getValidator()->validate($evenement);

        $this->assertHasViolation(
            $violations,
            'contact',
            'Le contact ne doit pas dépasser 200 caractères.'
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

    private function formatViolations(iterable $violations): string
    {
        $messages = [];

        foreach ($violations as $violation) {
            $messages[] =
                $violation->getPropertyPath()
                . ': '
                . $violation->getMessage();
        }

        return implode("\n", $messages);
    }
}
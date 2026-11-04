<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\{Categories, Editeur, Emission, InviteOldAnimateur, User};
use App\Repository\CategorieTagImageRepository;
use App\Service\Mp3Processor;
use App\Tests\Support\Mp3TestStorage;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\{EntityManagerInterface, PersistentCollection};
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Vich\UploaderBundle\Storage\StorageInterface;

final class Mp3EditorialStateTest extends TestCase
{
    private Mp3Processor $processor;
    private Emission $emission;

    protected function setUp(): void
    {
        $fs = $this->createMock(Filesystem::class);
        foreach (['mkdir', 'rename', 'remove'] as $method) {
            $fs->expects(self::never())->method($method);
        }
        $repository = $this->createMock(CategorieTagImageRepository::class);
        $repository->expects(self::never())->method('findOneByCategorieAndYear');
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects(self::never())->method('resolvePath');
        $this->processor = new Mp3Processor($storage, $fs, $repository,
            Mp3TestStorage::root(), 'https://example.test/mp3', dirname(__DIR__, 2));
        $this->emission = (new Emission())->setTitre('Titre initial')->setRef('Createur')
            ->setCategorie((new Categories())->setSlug('TST')->setTitre('Album'))
            ->setDatepub(new \DateTime('2024-06-15'))
            ->setDescriptif('<p>Texte &amp; commentaire</p>');
    }

    /** @dataProvider usedInputs */
    public function testInputsUsedByTheMp3ChangeTheEditorialState(string $field): void
    {
        $before = $this->processor->editorialState($this->emission);
        match ($field) {
            'title' => $this->emission->setTitre('Autre titre'),
            'category' => $this->emission->setCategorie((new Categories())->setSlug('ECO')->setTitre('Autre album')),
            'date' => $this->emission->setDatepub(new \DateTime('2025-07-16')),
            'hosts' => $this->emission->addUser((new User())->setUsername('Animatrice')),
            'guests' => $this->emission->addInviteOldAnimateur((new InviteOldAnimateur())->setFirstName('Alice')->setLastName('Martin')),
            'ref' => $this->emission->setRef('Autre createur'),
            'description' => $this->emission->setDescriptif('Autre commentaire'),
            'publisher' => $this->emission->setEditeur((new Editeur())->setName('Autre editeur')),
        };
        self::assertNotSame($before, $this->processor->editorialState($this->emission));
    }

    public static function usedInputs(): iterable
    {
        foreach (['title', 'category', 'date', 'hosts', 'guests', 'ref', 'description', 'publisher'] as $field) {
            yield $field => [$field];
        }
    }

    public function testEquivalentTagsAndUnrelatedFieldsDoNotChangeTheState(): void
    {
        $this->emission->addUser((new User())->setUsername('Animatrice'));
        $before = $this->processor->editorialState($this->emission);
        $this->emission->setKeyword('Nouveau mot-cle')->setDuree(45)->setUpdatedat(new \DateTime())
            ->setUrl('https://example.test/other')->setRef('Unused while users provide the artist')
            ->setDescriptif('<div> Texte &amp; commentaire </div>')->setTitre('  Titre initial  ')
            ->setDatepub(new \DateTime('2024-06-15 18:30:00'));
        self::assertSame($before, $this->processor->editorialState($this->emission));
    }

    public function testPersistedDateIsUsedEvenIfChangedBeforeControllerEntry(): void
    {
        $before = $this->processor->editorialState($this->emission);
        $persistedDate = clone $this->emission->getDatepub();
        $this->emission->setDatepub(new \DateTime('2025-07-16'));
        self::assertSame($before, $this->processor->editorialState($this->emission, ['datepub' => $persistedDate]));
        self::assertNotSame($before, $this->processor->editorialState($this->emission));
    }

    public function testPersistedHostCollectionSnapshotIsIndependentOfEdits(): void
    {
        $members = new ArrayCollection([(new User())->setUsername('First host')]);
        $collection = new PersistentCollection($this->createMock(EntityManagerInterface::class), new ClassMetadata(User::class), $members);
        $collection->setInitialized(true);
        $collection->takeSnapshot();
        (new \ReflectionProperty(Emission::class, 'users'))->setValue($this->emission, $collection);
        $before = $this->processor->editorialState($this->emission);
        $members->add((new User())->setUsername('Second host'));
        $collection->setDirty(true);
        self::assertSame($before, $this->processor->editorialState($this->emission, ['users' => $collection]));
        self::assertNotSame($before, $this->processor->editorialState($this->emission));
    }

    public function testArtistPriorityMatchesTheTagWriter(): void
    {
        $guest = (new InviteOldAnimateur())->setFirstName('Alice')->setLastName('Martin');
        $this->emission->addInviteOldAnimateur($guest);
        self::assertSame((string) $guest, $this->processor->editorialState($this->emission)['artist']);
        $host = (new User())->setUsername('Animatrice');
        $this->emission->addUser($host);
        self::assertSame('Animatrice', $this->processor->editorialState($this->emission)['artist']);
        $this->emission->removeUser($host);
        self::assertSame((string) $guest, $this->processor->editorialState($this->emission)['artist']);
        $this->emission->removeInviteOldAnimateur($guest);
        self::assertSame('Createur', $this->processor->editorialState($this->emission)['artist']);
        self::assertSame('Radio Zinzine', $this->processor->editorialState($this->emission, ['ref' => null])['artist']);
    }

    public function testReplacedCollectionStillUsesPersistedMembersForTheBaseline(): void
    {
        $originalMembers = new ArrayCollection([(new User())->setUsername('First host')]);
        (new \ReflectionProperty(Emission::class, 'users'))->setValue($this->emission, $originalMembers);
        $before = $this->processor->editorialState($this->emission);
        (new \ReflectionProperty(Emission::class, 'users'))->setValue($this->emission,
            new ArrayCollection([(new User())->setUsername('Second host')]));
        self::assertSame($before, $this->processor->editorialState($this->emission, ['users' => $originalMembers]));
        self::assertNotSame($before, $this->processor->editorialState($this->emission));
    }
}

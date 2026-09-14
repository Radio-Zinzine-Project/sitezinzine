<?php

namespace App\Entity;

use App\Repository\CategoriesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: CategoriesRepository::class)]
#[UniqueEntity('titre')]
#[UniqueEntity('slug')]
#[Vich\Uploadable]
class Categories
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['categories.index'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Groups(['categories.index', 'emissions.index'])]
    private string $titre = '';

    #[ORM\Column(nullable: true)]
    #[Groups(['categories.index'])]
    private ?int $oldid = null;

    #[ORM\ManyToOne(targetEntity: Editeur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Editeur $editeur = null;

    #[ORM\Column]
    #[Groups(['categories.index'])]
    #[Assert\LessThan(value: 720)]
    private ?int $duree = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Le descriptif de la catégorie est obligatoire.')]
    #[Groups(['categories.index'])]
    private ?string $descriptif = null;

    /**
     * @var Collection<int, Emission>
     */
    #[ORM\OneToMany(targetEntity: Emission::class, mappedBy: 'categorie')]
    private Collection $emissions;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['categories.index'])]
    private ?string $thumbnail = null;

    #[Vich\UploadableField(mapping: 'categories', fileNameProperty: 'thumbnail')]
    #[Assert\Image]
    #[Groups(['categories.index'])]
    private ?File $thumbnailFile = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['categories.index'])]
    private ?\DateTime $updatedAt = null;

    #[ORM\Column(length: 3, nullable: true)]
    #[Assert\NotBlank(message: 'Le code catégorie est obligatoire.')]
    #[Assert\Regex(
        pattern: '/^[A-Z]{3}$/',
        message: 'Le code doit contenir exactement 3 lettres majuscules.'
    )]
    private ?string $slug = null;

    #[ORM\Column]
    private ?bool $active = null;

    #[ORM\Column]
    private ?bool $softDelete = null;

    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'categories')]
    #[ORM\JoinTable(name: 'categories_user')]
    private Collection $users;

    /**
     * @var Collection<int, InviteOldAnimateur>
     */
    #[ORM\ManyToMany(targetEntity: InviteOldAnimateur::class, inversedBy: 'categories')]
    #[ORM\JoinTable(name: 'categories_invite_old_animateur')]
    private Collection $inviteOldAnimateurs;

    #[ORM\OneToMany(mappedBy: 'categorie', targetEntity: CategorieTagImage::class, orphanRemoval: true)]
    private Collection $tagImages;

    public function __construct()
    {
        $this->emissions = new ArrayCollection();
        $this->users = new ArrayCollection();
        $this->inviteOldAnimateurs = new ArrayCollection();
        $this->tagImages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getOldid(): ?int
    {
        return $this->oldid;
    }

    public function setOldid(int $oldid): static
    {
        $this->oldid = $oldid;

        return $this;
    }

    public function getEditeur(): ?Editeur
    {
        return $this->editeur;
    }

    public function setEditeur(?Editeur $editeur): static
    {
        $this->editeur = $editeur;

        return $this;
    }

    public function getDuree(): ?int
    {
        return $this->duree;
    }

    public function setDuree(int $duree): static
    {
        $this->duree = $duree;

        return $this;
    }

    public function getDescriptif(): ?string
    {
        return $this->descriptif;
    }

    public function setDescriptif(string $descriptif): static
    {
        $this->descriptif = $descriptif;

        return $this;
    }

    /**
     * @return Collection<int, Emission>
     */
    public function getEmissions(): Collection
    {
        return $this->emissions;
    }

    public function addEmission(Emission $emission): static
    {
        if (!$this->emissions->contains($emission)) {
            $this->emissions->add($emission);
            $emission->setCategorie($this);
        }

        return $this;
    }

    public function removeEmission(Emission $emission): static
    {
        if ($this->emissions->removeElement($emission)) {
            if ($emission->getCategorie() === $this) {
                $emission->setCategorie(null);
            }
        }

        return $this;
    }

    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function setThumbnail(?string $thumbnail): static
    {
        $this->thumbnail = $thumbnail;

        return $this;
    }

    public function getThumbnailFile(): ?File
    {
        return $this->thumbnailFile;
    }

    public function setThumbnailFile(?File $thumbnailFile): static
    {
        $this->thumbnailFile = $thumbnailFile;

        if (null !== $thumbnailFile) {
            $this->updatedAt = new \DateTime();
        }

        return $this;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTime $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function isSoftDelete(): ?bool
    {
        return $this->softDelete;
    }

    public function setSoftDelete(bool $softDelete): static
    {
        $this->softDelete = $softDelete;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        $this->users->removeElement($user);

        return $this;
    }

    /**
     * @return Collection<int, InviteOldAnimateur>
     */
    public function getInviteOldAnimateurs(): Collection
    {
        return $this->inviteOldAnimateurs;
    }

    public function addInviteOldAnimateur(InviteOldAnimateur $invite): static
    {
        if (!$this->inviteOldAnimateurs->contains($invite)) {
            $this->inviteOldAnimateurs->add($invite);
        }

        return $this;
    }

    public function removeInviteOldAnimateur(InviteOldAnimateur $invite): static
    {
        $this->inviteOldAnimateurs->removeElement($invite);

        return $this;
    }

    #[Assert\Callback(groups: ['admin'])]
    public function validateAtLeastOneOwner(ExecutionContextInterface $context): void
    {
        $hasUsers = !$this->getUsers()->isEmpty();

        $hasAnciens = !$this->getInviteOldAnimateurs()
            ->filter(fn ($a) => (bool) $a->isAncienanimateur())
            ->isEmpty();

        if (!$hasUsers && !$hasAnciens) {
            $message = 'Vous devez sélectionner au moins un·e utilisateurice OU un·e ancien·ne animateur·ice.';

            $context->buildViolation($message)->addViolation();

            $context->buildViolation($message)->atPath('users')->addViolation();
            $context->buildViolation($message)->atPath('inviteOldAnimateurs')->addViolation();
        }
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $slug = $slug !== null ? strtoupper(trim($slug)) : null;
        $this->slug = ($slug === '') ? null : $slug;

        return $this;
    }

    public function getTagImages(): Collection
    {
        return $this->tagImages;
    }

    public function addTagImage(CategorieTagImage $tagImage): static
    {
        if (!$this->tagImages->contains($tagImage)) {
            $this->tagImages->add($tagImage);
            $tagImage->setCategorie($this);
        }

        return $this;
    }

    public function removeTagImage(CategorieTagImage $tagImage): static
    {
        if ($this->tagImages->removeElement($tagImage)) {
            if ($tagImage->getCategorie() === $this) {
                $tagImage->setCategorie(null);
            }
        }

        return $this;
    }
}
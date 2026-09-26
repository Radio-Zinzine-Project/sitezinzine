<?php

namespace App\Entity;

use App\Repository\PageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: PageRepository::class)]
#[Vich\Uploadable]
class Page
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // identifiant unique pour la page (soutien, a-propos, etc.)
    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $title = null;

    // contenu HTML (TinyMCE)
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private ?string $content = null;

    // nom de fichier image stocké en BDD
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mainImageName = null;

    // fichier uploadé (non mappé en BDD)
    #[Vich\UploadableField(mapping: 'page_main_image', fileNameProperty: 'mainImageName')]
    private ?File $mainImageFile = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    public function __toString(): string
    {
        return $this->title ?? $this->slug ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getMainImageName(): ?string
    {
        return $this->mainImageName;
    }

    public function setMainImageName(?string $mainImageName): self
    {
        $this->mainImageName = $mainImageName;
        return $this;
    }

    public function getMainImageFile(): ?File
    {
        return $this->mainImageFile;
    }

    public function setMainImageFile(?File $mainImageFile): self
    {
        $this->mainImageFile = $mainImageFile;

        if ($mainImageFile) {
            $this->updatedAt = new \DateTime();
        }

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

        /**
     * Champ virtuel (non stocké en BDD)
     */
    private ?bool $deleteMainImage = false;

    public function getDeleteMainImage(): ?bool
    {
        return $this->deleteMainImage;
    }

    public function setDeleteMainImage(?bool $deleteMainImage): self
    {
        $this->deleteMainImage = $deleteMainImage;
        return $this;
    }
}

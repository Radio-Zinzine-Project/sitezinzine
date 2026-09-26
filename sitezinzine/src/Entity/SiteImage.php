<?php

namespace App\Entity;

use App\Repository\SiteImageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: SiteImageRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_site_image_key',
    columns: ['image_key']
)]
#[Vich\Uploadable]
class SiteImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Identifiant technique unique utilisé par l'application.
     *
     * Exemples :
     * - playlist_day
     * - playlist_night
     */
    #[ORM\Column(name: 'image_key', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $key = null;

    /**
     * Nom lisible affiché dans l'administration.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    /**
     * Description facultative indiquant où l'image est utilisée.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Nom du fichier enregistré par VichUploaderBundle.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnail = null;

    /**
     * Fichier utilisé pour charger ou remplacer l'image.
     *
     * Cette propriété n'est pas persistée directement en base.
     */
    #[Vich\UploadableField(
        mapping: 'site_images',
        fileNameProperty: 'thumbnail'
    )]
    #[Assert\Image(
        maxSize: '2M',
        mimeTypes: [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        maxSizeMessage: 'Fichier trop lourd ({{ size }}). Taille max : {{ limit }}.',
        mimeTypesMessage: 'Format non autorisé. Formats acceptés : JPEG, PNG, WEBP.'
    )]
    private ?File $thumbnailFile = null;

    /**
     * Date de dernière modification.
     *
     * Elle est notamment mise à jour lors du chargement
     * d'un nouveau fichier afin que VichUploaderBundle
     * détecte la modification de l'entité.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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

        if ($thumbnailFile !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function __toString(): string
    {
        return $this->label ?? $this->key ?? 'Image du site';
    }
}
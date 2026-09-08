<?php

namespace App\Entity;

use App\Repository\EvenementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: EvenementRepository::class)]
#[Vich\Uploadable()]

class Evenement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100, maxMessage: "Le titre ne doit pas dépasser {{ limit }} caractères.")]
   
    private ?string $titre = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 100, maxMessage: "L'organisateur ne doit pas dépasser {{ limit }} caractères.")]
   
    private ?string $organisateur = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(
        max: 50, // 🔥 Limite à 50 caractères
        maxMessage: "La ville ne doit pas dépasser {{ limit }} caractères."
    )]
    private ?string $ville = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $departement = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(
        max: 50, // 🔥 Limite à 50 caractères
        maxMessage: "L'adresse ne doit pas dépasser {{ limit }} caractères."
    )]
    private ?string $adresse = null;

    #[ORM\Column]
    private ?\DateTime $dateDebut = null;

    #[ORM\Column]
    private ?\DateTime $dateFin = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(
        max: 50, // 🔥 Limite à 50 caractères
        maxMessage: "L'horaire ne doit pas dépasser {{ limit }} caractères."
    )]
    private ?string $horaire = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(
        max: 50, // 🔥 Limite à 50 caractères
        maxMessage: "Le prix ne doit pas dépasser {{ limit }} caractères."
    )]
    private ?string $prix = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $presentation = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(
        max: 200, // 🔥 Limite à 200 caractères
        maxMessage: "Le contact ne doit pas dépasser {{ limit }} caractères."
    )]
    private ?string $contact = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(nullable: true)]
    private ?bool $valid = null;

    #[ORM\Column]
    private ?\DateTime $updateAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $thumbnail = null;

    #[Vich\UploadableField(mapping: 'evenements', fileNameProperty: 'thumbnail')]
   /*  #[Assert\Image(
        maxWidth: 650,
        maxHeight: 500,
    )] */
    private ?File $thumbnailFile = null;

    #[ORM\Column(nullable: true)]
    private ?bool $softDelete = null;

    #[ORM\ManyToOne(inversedBy: 'evenements')]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getOrganisateur(): ?string
    {
        return $this->organisateur;
    }

    public function setOrganisateur(string $organisateur): static
    {
        $this->organisateur = $organisateur;

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(string $ville): static
    {
        $this->ville = $ville;

        return $this;
    }

    public function getDepartement(): ?string
    {
        return $this->departement;
    }

    public function setDepartement(string $departement): static
    {
        $this->departement = $departement;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getDateDebut(): ?\DateTime
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTime $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTime
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTime $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getHoraire(): ?string
    {
        return $this->horaire;
    }

    public function setHoraire(string $horaire): static
    {
        $this->horaire = $horaire;

        return $this;
    }

    public function getPrix(): ?string
    {
        return $this->prix;
    }

    public function setPrix(string $prix): static
    {
        $this->prix = $prix;

        return $this;
    }

    public function getPresentation(): ?string
    {
        return $this->presentation;
    }

    public function setPresentation(string $presentation): static
    {
        $this->presentation = $presentation;

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(string $contact): static
    {
        $this->contact = $contact;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isValid(): ?bool
    {
        return $this->valid;
    }

    public function setValid(?bool $valid): static
    {
        $this->valid = $valid;

        return $this;
    }

    public function getUpdateAt(): ?\DateTime
    {
        return $this->updateAt;
    }

    public function setUpdateAt(\DateTime $updateAt): static
    {
        $this->updateAt = $updateAt;

        return $this;
    }

    
    /**
     * Get the value of thumbnail
     */ 
    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    /**
     * Set the value of thumbnail
    */ 
    public function setThumbnail(?string $thumbnail): static
    {
        $this->thumbnail = $thumbnail !== null ? trim($thumbnail) : null;

        return $this;
    }

    /**
     * Get the value of thumbnailFile
     */ 
    public function getThumbnailFile(): ?File
    {
        return $this->thumbnailFile;
    }

    /**
     * Set the value of thumbnailFile
     *
     */ 
    public function setThumbnailFile(?File $thumbnailFile): static
    {
        $this->thumbnailFile = $thumbnailFile;

        if (null !== $thumbnailFile) {
            // It is required that at least one field changes if you are using doctrine
            // otherwise the event listeners won't be called and the file is lost
            $this->updateAt = new \DateTime();
        }

        return $this;
    }

    public function isSoftDelete(): ?bool
    {
        return $this->softDelete;
    }

    public function setSoftDelete(?bool $softDelete): static
    {
        $this->softDelete = $softDelete;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

}

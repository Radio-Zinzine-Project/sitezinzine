<?php

namespace App\Entity;

use App\Repository\DiffusionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiffusionRepository::class)]
#[ORM\Table(name: 'diffusion')]
#[ORM\Index(name: 'idx_diffusion_horaire', columns: ['horaire_diffusion'])]
#[ORM\Index(name: 'idx_diffusion_emission', columns: ['emission_id'])]
#[ORM\Index(name: 'idx_diffusion_ends_at', columns: ['ends_at'])]
#[ORM\Index(name: 'idx_diffusion_assignment_group', columns: ['assignment_group_key'])]
class Diffusion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'diffusions')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Emission $emission = null;


    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $horaireDiffusion = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $endsAt = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $assignmentGroupKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\Column]
    private ?int $nombreDiffusion = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmission(): ?Emission
    {
        return $this->emission;
    }

    public function setEmission(?Emission $emission): static
    {
        $this->emission = $emission;
        $this->touch();

        return $this;
    }

    public function getHoraireDiffusion(): ?\DateTimeInterface
    {
        return $this->horaireDiffusion;
    }

    public function setHoraireDiffusion(\DateTimeInterface $horaireDiffusion): static
    {
        if (null !== $this->endsAt && $this->endsAt <= $horaireDiffusion) {
            throw new \InvalidArgumentException('horaireDiffusion doit être avant endsAt.');
        }

        $this->horaireDiffusion = $horaireDiffusion;
        $this->touch();

        return $this;
    }

    public function getNombreDiffusion(): ?int
    {
        return $this->nombreDiffusion;
    }

    public function setNombreDiffusion(int $nombreDiffusion): static
    {
        if ($nombreDiffusion < 0) {
            throw new \InvalidArgumentException('nombreDiffusion doit être supérieur ou égal à 0.');
        }

        $this->nombreDiffusion = $nombreDiffusion;
        $this->touch();

        return $this;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): static
    {
        if (null !== $durationMinutes && $durationMinutes < 1) {
            throw new \InvalidArgumentException('durationMinutes doit être supérieur ou égal à 1.');
        }

        $this->durationMinutes = $durationMinutes;
        $this->touch();

        return $this;
    }

    public function getEndsAt(): ?\DateTimeInterface
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeInterface $endsAt): static
    {
        if ($this->horaireDiffusion && $endsAt !== null && $endsAt <= $this->horaireDiffusion) {
            throw new \InvalidArgumentException('endsAt doit être après horaireDiffusion.');
        }

        $this->endsAt = $endsAt;
        $this->touch();

        return $this;
    }

    public function getAssignmentGroupKey(): ?string
    {
        return $this->assignmentGroupKey;
    }

    public function setAssignmentGroupKey(?string $assignmentGroupKey): static
    {
        $this->assignmentGroupKey = $assignmentGroupKey;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

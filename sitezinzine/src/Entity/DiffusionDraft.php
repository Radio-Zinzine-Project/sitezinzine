<?php

namespace App\Entity;

use App\Repository\DiffusionDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiffusionDraftRepository::class)]
#[ORM\Table(name: 'diffusion_draft')]
#[ORM\UniqueConstraint(name: 'uniq_diffusion_draft_slot_horaire', columns: ['slot_id', 'horaire_diffusion'])]
#[ORM\Index(name: 'idx_diffusion_draft_horaire', columns: ['horaire_diffusion'])]
#[ORM\Index(name: 'idx_diffusion_draft_ends_at', columns: ['ends_at'])]
#[ORM\Index(name: 'idx_diffusion_draft_emission', columns: ['emission_id'])]
#[ORM\Index(name: 'idx_diffusion_draft_slot', columns: ['slot_id'])]
#[ORM\Index(name: 'idx_diffusion_draft_type', columns: ['draft_type'])]
#[ORM\Index(name: 'idx_diffusion_draft_assignment_group', columns: ['assignment_group_key'])]
class DiffusionDraft
{
    public const TYPE_REGULAR = 'regular';
    public const TYPE_MANUAL_REBROADCAST = 'manual_rebroadcast';
    public const TYPE_MANUAL_SPECIAL = 'manual_special';
    public const TYPE_MANUAL_LIVE = 'manual_live';

    public const ALLOWED_TYPES = [
        self::TYPE_REGULAR,
        self::TYPE_MANUAL_REBROADCAST,
        self::TYPE_MANUAL_SPECIAL,
        self::TYPE_MANUAL_LIVE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Emission::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Emission $emission = null;

    #[ORM\ManyToOne(targetEntity: ProgrammationRuleSlot::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ProgrammationRuleSlot $slot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $horaireDiffusion = null;

    /**
     * 1 = première diffusion
     * 2 = rediffusion 1
     * 3 = rediffusion 2
     * etc.
     */
    #[ORM\Column]
    private ?int $nombreDiffusion = null;

    #[ORM\Column(length: 40, options: ['default' => self::TYPE_REGULAR])]
    private string $draftType = self::TYPE_REGULAR;

    /**
     * Durée effectivement programmée dans la grille.
     * Peut différer de la durée de l'émission, surtout pour un direct manuel.
     */
    #[ORM\Column(nullable: true)]
    private ?int $durationMinutes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $assignmentGroupKey = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

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

    public function getSlot(): ?ProgrammationRuleSlot
    {
        return $this->slot;
    }

    public function setSlot(?ProgrammationRuleSlot $slot): static
    {
        $this->slot = $slot;
        $this->touch();

        return $this;
    }

    public function hasSlot(): bool
    {
        return null !== $this->slot;
    }

    public function getHoraireDiffusion(): ?\DateTimeImmutable
    {
        return $this->horaireDiffusion;
    }

    public function setHoraireDiffusion(\DateTimeImmutable $horaireDiffusion): static
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
        if ($nombreDiffusion < 1) {
            throw new \InvalidArgumentException('nombreDiffusion doit être supérieur ou égal à 1.');
        }

        $this->nombreDiffusion = $nombreDiffusion;
        $this->touch();

        return $this;
    }

    public function getDraftType(): string
    {
        return $this->draftType;
    }

    public function setDraftType(string $draftType): static
    {
        if (!\in_array($draftType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Type de draft invalide : %s', $draftType));
        }

        $this->draftType = $draftType;
        $this->touch();

        return $this;
    }

    public function isRegular(): bool
    {
        return self::TYPE_REGULAR === $this->draftType;
    }

    public function isManual(): bool
    {
        return !$this->isRegular();
    }

    public function isManualRebroadcast(): bool
    {
        return self::TYPE_MANUAL_REBROADCAST === $this->draftType;
    }

    public function isManualSpecial(): bool
    {
        return self::TYPE_MANUAL_SPECIAL === $this->draftType;
    }

    public function isManualLive(): bool
    {
        return self::TYPE_MANUAL_LIVE === $this->draftType;
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

    public function getEffectiveDurationMinutes(): ?int
    {
        if (null !== $this->durationMinutes) {
            return $this->durationMinutes;
        }

        return $this->emission?->getDuree();
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        if ($this->horaireDiffusion && $endsAt <= $this->horaireDiffusion) {
            throw new \InvalidArgumentException('endsAt doit être après horaireDiffusion.');
        }

        $this->endsAt = $endsAt;
        $this->touch();

        return $this;
    }

    public function computeEndsAtFromDuration(int $durationMinutes): void
    {
        if ($durationMinutes < 1) {
            throw new \InvalidArgumentException('durationMinutes doit être supérieur ou égal à 1.');
        }

        if (!$this->horaireDiffusion) {
            throw new \LogicException('horaireDiffusion doit être défini avant de calculer endsAt.');
        }

        $this->endsAt = $this->horaireDiffusion->modify(sprintf('+%d minutes', $durationMinutes));
        $this->touch();
    }

    public function setSchedule(\DateTimeImmutable $startsAt, int $durationMinutes): static
    {
        if ($durationMinutes < 1) {
            throw new \InvalidArgumentException('durationMinutes doit être supérieur ou égal à 1.');
        }

        $this->horaireDiffusion = $startsAt;
        $this->durationMinutes = $durationMinutes;
        $this->endsAt = $startsAt->modify(sprintf('+%d minutes', $durationMinutes));
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

    public function getRule(): ?ProgrammationRule
    {
        return $this->slot?->getRule();
    }

    public function getBroadcastRank(): ?int
    {
        return $this->nombreDiffusion;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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
}

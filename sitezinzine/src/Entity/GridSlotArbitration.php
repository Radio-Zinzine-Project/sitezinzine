<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GridSlotArbitrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GridSlotArbitrationRepository::class)]
#[ORM\Table(name: 'grid_slot_arbitration')]
#[ORM\Index(columns: ['original_starts_at'], name: 'idx_grid_slot_arbitration_original_starts_at')]
#[ORM\Index(columns: ['rescheduled_starts_at'], name: 'idx_grid_slot_arbitration_rescheduled_starts_at')]
#[ORM\Index(columns: ['status'], name: 'idx_grid_slot_arbitration_status')]
#[ORM\Index(columns: ['arbitration_group_key'], name: 'idx_grid_slot_arbitration_group_key')]
class GridSlotArbitration
{
    public const TYPE_RULE_OVERLAP = 'rule_overlap';
    public const TYPE_MANUAL_OVERRIDE = 'manual_override';
    public const TYPE_CALENDAR_ADJUSTMENT = 'calendar_adjustment';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTION_KEEP = 'keep';
    public const ACTION_REPLACE = 'replace';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_RESCHEDULE_PREVIOUS_WEEK = 'reschedule_previous_week';
    public const ACTION_RESCHEDULE_NEXT_WEEK = 'reschedule_next_week';
    public const ACTION_RESCHEDULE_CUSTOM = 'reschedule_custom';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProgrammationRuleSlot::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProgrammationRuleSlot $slot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $originalStartsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $originalEndsAt = null;

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_RULE_OVERLAP;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 50)]
    private string $action = self::ACTION_KEEP;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rescheduledStartsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rescheduledEndsAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $arbitrationGroupKey = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markResolved(): void
    {
        $this->status = self::STATUS_RESOLVED;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function markPending(): void
    {
        $this->status = self::STATUS_PENDING;
        $this->resolvedAt = null;
        $this->touch();
    }

    public function markCancelled(): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->resolvedAt = null;
        $this->touch();
    }

    public function isRescheduleAction(): bool
    {
        return \in_array($this->action, [
            self::ACTION_RESCHEDULE_PREVIOUS_WEEK,
            self::ACTION_RESCHEDULE_NEXT_WEEK,
            self::ACTION_RESCHEDULE_CUSTOM,
        ], true);
    }

    public function isCancelAction(): bool
    {
        return self::ACTION_CANCEL === $this->action;
    }

    public function isActiveDecision(): bool
    {
        return self::STATUS_CANCELLED !== $this->status;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlot(): ?ProgrammationRuleSlot
    {
        return $this->slot;
    }

    public function setSlot(?ProgrammationRuleSlot $slot): self
    {
        $this->slot = $slot;
        return $this;
    }

    public function getOriginalStartsAt(): ?\DateTimeImmutable
    {
        return $this->originalStartsAt;
    }

    public function setOriginalStartsAt(\DateTimeImmutable $originalStartsAt): self
    {
        $this->originalStartsAt = $originalStartsAt;
        return $this;
    }

    public function getOriginalEndsAt(): ?\DateTimeImmutable
    {
        return $this->originalEndsAt;
    }

    public function setOriginalEndsAt(\DateTimeImmutable $originalEndsAt): self
    {
        $this->originalEndsAt = $originalEndsAt;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getRescheduledStartsAt(): ?\DateTimeImmutable
    {
        return $this->rescheduledStartsAt;
    }

    public function setRescheduledStartsAt(?\DateTimeImmutable $rescheduledStartsAt): self
    {
        $this->rescheduledStartsAt = $rescheduledStartsAt;
        return $this;
    }

    public function getRescheduledEndsAt(): ?\DateTimeImmutable
    {
        return $this->rescheduledEndsAt;
    }

    public function setRescheduledEndsAt(?\DateTimeImmutable $rescheduledEndsAt): self
    {
        $this->rescheduledEndsAt = $rescheduledEndsAt;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getArbitrationGroupKey(): ?string
    {
        return $this->arbitrationGroupKey;
    }

    public function setArbitrationGroupKey(?string $arbitrationGroupKey): self
    {
        $this->arbitrationGroupKey = $arbitrationGroupKey;

        return $this;
    }
}

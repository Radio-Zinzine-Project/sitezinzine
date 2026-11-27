<?php

namespace App\Entity;

use App\Repository\ProgrammationRuleSlotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProgrammationRuleSlotRepository::class)]
#[ORM\Table(name: 'programmation_rule_slot')]
#[ORM\Index(columns: ['day_of_week'], name: 'idx_programmation_rule_slot_day')]
#[ORM\Index(columns: ['broadcast_rank'], name: 'idx_programmation_rule_slot_rank')]
#[ORM\Index(columns: ['week_offset'], name: 'idx_programmation_rule_slot_week_offset')]
#[ORM\Index(columns: ['recurrence_type'], name: 'idx_programmation_rule_slot_recurrence')]
#[ORM\Index(columns: ['monthly_occurrence'], name: 'idx_programmation_rule_slot_monthly_occurrence')]
#[ORM\Index(columns: ['month_interval'], name: 'idx_programmation_rule_slot_month_interval')]
#[ORM\Index(columns: ['is_active'], name: 'idx_programmation_rule_slot_active')]
#[ORM\Index(columns: ['deleted_at'], name: 'idx_programmation_rule_slot_deleted')]
#[ORM\Index(columns: ['week_parity'], name: 'idx_programmation_rule_slot_week_parity')]
class ProgrammationRuleSlot
{
    public const RECURRENCE_WEEKLY = 'weekly';
    public const RECURRENCE_MONTHLY = 'monthly';

    public const MONTHLY_FIRST = 1;
    public const MONTHLY_SECOND = 2;
    public const MONTHLY_THIRD = 3;
    public const MONTHLY_FOURTH = 4;
    public const MONTHLY_LAST = -1;

    public const WEEK_PARITY_EVEN = 'even';
    public const WEEK_PARITY_ODD = 'odd';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProgrammationRule::class, inversedBy: 'slots')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProgrammationRule $rule = null;

    /**
     * 1 = lundi, 7 = dimanche
     */
    #[ORM\Column(name: 'day_of_week')]
    private ?int $dayOfWeek = null;

    #[ORM\Column(type: 'time_immutable')]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column]
    private ?int $durationMinutes = null;

    /**
     * 1 = 1re diffusion
     * 2 = 1re rediffusion
     * 3 = 2e rediffusion
     * 4 = 3e rediffusion
     */
    #[ORM\Column(name: 'broadcast_rank')]
    private int $broadcastRank = 1;

    /**
     * Décalage en semaines radio par rapport au cycle principal.
     * 0 = même semaine radio
     * 1 = semaine radio suivante
     * 2 = deux semaines plus tard
     */
    #[ORM\Column(name: 'week_offset')]
    private int $weekOffset = 0;

    #[ORM\Column(name: 'recurrence_type', length: 20)]
    private string $recurrenceType = self::RECURRENCE_WEEKLY;

    /**
     * Utilisé seulement si recurrenceType = monthly
     * 1 = 1er
     * 2 = 2e
     * 3 = 3e
     * 4 = 4e
     * -1 = dernier
     */
    #[ORM\Column(name: 'monthly_occurrence', nullable: true)]
    private ?int $monthlyOccurrence = null;

    /**
     * Utilisé seulement si recurrenceType = monthly
     * 1 = tous les mois
     * 2 = tous les 2 mois
     * 3 = tous les 3 mois
     */
    #[ORM\Column(name: 'month_interval')]
    private int $monthInterval = 1;

    /**
     * Utilisé seulement si recurrenceType = weekly
     * null = toutes les semaines
     * even = semaines paires
     * odd = semaines impaires
     */
    #[ORM\Column(name: 'week_parity', type: 'string', length: 10, nullable: true)]
    private ?string $weekParity = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s - %s - %s %s',
            $this->getBroadcastLabel(),
            $this->getRecurrenceLabel(),
            $this->getDayLabel(),
            $this->startTime?->format('H:i') ?? '--:--'
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRule(): ?ProgrammationRule
    {
        return $this->rule;
    }

    public function setRule(?ProgrammationRule $rule): static
    {
        $this->rule = $rule;
        $this->touch();

        return $this;
    }

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(?int $dayOfWeek): static
    {
        if ($dayOfWeek !== null && ($dayOfWeek < 1 || $dayOfWeek > 7)) {
            throw new \InvalidArgumentException('dayOfWeek doit être compris entre 1 (lundi) et 7 (dimanche).');
        }

        $this->dayOfWeek = $dayOfWeek;
        $this->touch();

        return $this;
    }

    public function getStartTime(): ?\DateTimeImmutable
    {
        return $this->startTime;
    }

    public function setStartTime(?\DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;
        $this->touch();

        return $this;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): static
    {
        if ($durationMinutes !== null && $durationMinutes <= 0) {
            throw new \InvalidArgumentException('durationMinutes doit être supérieur à 0.');
        }

        $this->durationMinutes = $durationMinutes;
        $this->touch();

        return $this;
    }

    public function getBroadcastRank(): int
    {
        return $this->broadcastRank;
    }

    public function setBroadcastRank(int $broadcastRank): static
    {
        if (!in_array($broadcastRank, [1, 2, 3, 4], true)) {
            throw new \InvalidArgumentException('broadcastRank doit valoir 1, 2, 3 ou 4.');
        }

        $this->broadcastRank = $broadcastRank;
        $this->touch();

        return $this;
    }

    public function getWeekOffset(): int
    {
        return $this->weekOffset;
    }

    public function setWeekOffset(int $weekOffset): static
    {
        if ($weekOffset < 0) {
            throw new \InvalidArgumentException('weekOffset doit être supérieur ou égal à 0.');
        }

        $this->weekOffset = $weekOffset;
        $this->touch();

        return $this;
    }

    public function getRecurrenceType(): string
    {
        return $this->recurrenceType;
    }

    public function setRecurrenceType(string $recurrenceType): static
    {
        $allowed = [
            self::RECURRENCE_WEEKLY,
            self::RECURRENCE_MONTHLY,
        ];

        if (!in_array($recurrenceType, $allowed, true)) {
            throw new \InvalidArgumentException('recurrenceType invalide.');
        }

        $this->recurrenceType = $recurrenceType;

        if ($recurrenceType === self::RECURRENCE_WEEKLY) {
            $this->monthlyOccurrence = null;
            $this->monthInterval = 1;
        }

        if ($recurrenceType === self::RECURRENCE_MONTHLY) {
            $this->weekParity = null;
        }

        $this->touch();

        return $this;
    }

    public function getMonthlyOccurrence(): ?int
    {
        return $this->monthlyOccurrence;
    }

    public function setMonthlyOccurrence(?int $monthlyOccurrence): static
    {
        $allowed = [
            self::MONTHLY_FIRST,
            self::MONTHLY_SECOND,
            self::MONTHLY_THIRD,
            self::MONTHLY_FOURTH,
            self::MONTHLY_LAST,
            null,
        ];

        if (!in_array($monthlyOccurrence, $allowed, true)) {
            throw new \InvalidArgumentException('monthlyOccurrence invalide.');
        }

        $this->monthlyOccurrence = $monthlyOccurrence;
        $this->touch();

        return $this;
    }

    public function getMonthInterval(): int
    {
        return $this->monthInterval;
    }

    public function setMonthInterval(int $monthInterval): static
    {
        if ($monthInterval < 1) {
            throw new \InvalidArgumentException('monthInterval doit être supérieur ou égal à 1.');
        }

        $this->monthInterval = $monthInterval;
        $this->touch();

        return $this;
    }

    public function getWeekParity(): ?string
    {
        return $this->weekParity;
    }

    public function setWeekParity(?string $weekParity): static
    {
        $allowed = [
            null,
            self::WEEK_PARITY_EVEN,
            self::WEEK_PARITY_ODD,
        ];

        if (!in_array($weekParity, $allowed, true)) {
            throw new \InvalidArgumentException('weekParity invalide.');
        }

        $this->weekParity = $weekParity;
        $this->touch();

        return $this;
    }

    public function getWeekParityLabel(): ?string
    {
        return match ($this->weekParity) {
            self::WEEK_PARITY_EVEN => 'Semaines paires',
            self::WEEK_PARITY_ODD => 'Semaines impaires',
            default => null,
        };
    }

    public function getMonthIntervalLabel(): string
    {
        return match ($this->monthInterval) {
            1 => 'Tous les mois',
            2 => 'Tous les 2 mois',
            default => sprintf('Tous les %d mois', $this->monthInterval),
        };
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        $this->touch();

        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt;

        if ($deletedAt !== null) {
            $this->isActive = false;
        }

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

    public function softDelete(): static
    {
        $this->deletedAt = new \DateTimeImmutable();
        $this->isActive = false;
        $this->touch();

        return $this;
    }

    public function restore(): static
    {
        $this->deletedAt = null;
        $this->isActive = true;
        $this->touch();

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getBroadcastLabel(): string
    {
        return match ($this->broadcastRank) {
            1 => '1re diffusion',
            2 => '1re rediffusion',
            3 => '2e rediffusion',
            4 => '3e rediffusion',
            default => 'Diffusion inconnue',
        };
    }

    public function getWeekOffsetLabel(): string
    {
        return match ($this->weekOffset) {
            0 => 'Même semaine radio',
            1 => 'Semaine radio suivante',
            default => sprintf('%d semaines radio plus tard', $this->weekOffset),
        };
    }

    public function getRecurrenceLabel(): string
    {
        return match ($this->recurrenceType) {
            self::RECURRENCE_WEEKLY => 'Hebdomadaire',
            self::RECURRENCE_MONTHLY => 'Mensuelle',
            default => 'Inconnue',
        };
    }

    public function getMonthlyOccurrenceLabel(): ?string
    {
        if ($this->monthlyOccurrence === null) {
            return null;
        }

        return match ($this->monthlyOccurrence) {
            self::MONTHLY_FIRST => '1er',
            self::MONTHLY_SECOND => '2e',
            self::MONTHLY_THIRD => '3e',
            self::MONTHLY_FOURTH => '4e',
            self::MONTHLY_LAST => 'Dernier',
            default => null,
        };
    }

    public function getDayLabel(): string
    {
        return match ($this->dayOfWeek) {
            1 => 'Lundi',
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
            7 => 'Dimanche',
            default => 'Jour inconnu',
        };
    }

    public function getRadioDayLabel(): string
    {
        return match ($this->dayOfWeek) {
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
            7 => 'Dimanche',
            1 => 'Lundi',
            default => 'Jour inconnu',
        };
    }

    public function getEndTime(): ?\DateTimeImmutable
    {
        if ($this->startTime === null || $this->durationMinutes === null) {
            return null;
        }

        return $this->startTime->modify(sprintf('+%d minutes', $this->durationMinutes));
    }

    public function isWeekly(): bool
    {
        return $this->recurrenceType === self::RECURRENCE_WEEKLY;
    }

    public function isMonthly(): bool
    {
        return $this->recurrenceType === self::RECURRENCE_MONTHLY;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
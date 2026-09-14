<?php

namespace App\Entity;

use App\Repository\ProgrammationRuleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use SortDirection;

#[ORM\Entity(repositoryClass: ProgrammationRuleRepository::class)]
#[ORM\Table(name: 'programmation_rule')]
#[ORM\UniqueConstraint(
    name: 'uniq_programmation_rule_category_number',
    columns: ['category_id', 'rule_number']
)]
#[ORM\Index(columns: ['is_active'], name: 'idx_programmation_rule_active')]
#[ORM\Index(columns: ['deleted_at'], name: 'idx_programmation_rule_deleted')]
#[ORM\Index(columns: ['category_id'], name: 'idx_programmation_rule_category')]
class ProgrammationRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Categories::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Categories $category = null;

    #[ORM\Column(type: 'integer')]
    private ?int $ruleNumber = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, ProgrammationRuleSlot>
     */
    #[ORM\OneToMany(
        mappedBy: 'rule',
        targetEntity: ProgrammationRuleSlot::class,
        orphanRemoval: false,
        cascade: ['persist']
    )]
    #[ORM\OrderBy([
        'broadcastRank' => SortDirection::Ascending,
        'dayOfWeek' => SortDirection::Ascending,
        'startTime' => SortDirection::Ascending,
    ])]
    private Collection $slots;

    public function __construct()
    {
        $this->slots = new ArrayCollection();

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function __toString(): string
    {
        return $this->getDisplayName();
    }

    public function getDisplayName(): string
    {
        $categoryTitle = $this->category?->getTitre() ?? 'Catégorie';

        if ($this->ruleNumber === null) {
            return sprintf('%s règle N° ?', $categoryTitle);
        }

        return sprintf('%s règle N° %d', $categoryTitle, $this->ruleNumber);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?Categories
    {
        return $this->category;
    }

    public function setCategory(?Categories $category): static
    {
        $this->category = $category;
        $this->touch();

        return $this;
    }

    public function getRuleNumber(): ?int
    {
        return $this->ruleNumber;
    }

    public function setRuleNumber(int $ruleNumber): static
    {
        $this->ruleNumber = $ruleNumber;
        $this->touch();

        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;
        $this->touch();

        return $this;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): static
    {
        $this->validUntil = $validUntil;
        $this->touch();

        return $this;
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

    /**
     * @return Collection<int, ProgrammationRuleSlot>
     */
    public function getSlots(): Collection
    {
        return $this->slots;
    }

    public function addSlot(ProgrammationRuleSlot $slot): static
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->setRule($this);
            $this->touch();
        }

        return $this;
    }

    public function removeSlot(ProgrammationRuleSlot $slot): static
    {
        if ($this->slots->removeElement($slot)) {
            if ($slot->getRule() === $this) {
                $slot->setRule(null);
            }

            $this->touch();
        }

        return $this;
    }

    public function softDelete(): static
    {
        $now = new \DateTimeImmutable();

        $this->deletedAt = $now;
        $this->isActive = false;
        $this->updatedAt = $now;

        foreach ($this->slots as $slot) {
            if (!$slot->isDeleted()) {
                $slot->softDelete();
            }
        }

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

    public function isCurrentlyValid(?\DateTimeImmutable $date = null): bool
    {
        $date ??= new \DateTimeImmutable('today');

        if ($this->isDeleted() || !$this->isActive) {
            return false;
        }

        if ($this->validFrom !== null && $date < $this->validFrom) {
            return false;
        }

        if ($this->validUntil !== null && $date > $this->validUntil) {
            return false;
        }

        return true;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getActiveSlotsCount(): int
    {
        return $this->slots->filter(
            fn (ProgrammationRuleSlot $slot) => $slot->getDeletedAt() === null
        )->count();
    }
}
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PendingRebroadcastRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PendingRebroadcastRepository::class)]
#[ORM\Table(name: 'pending_rebroadcast')]
#[ORM\Index(name: 'idx_pending_rebroadcast_emission', columns: ['emission_id'])]
#[ORM\Index(name: 'idx_pending_rebroadcast_group', columns: ['assignment_group_key'])]
#[ORM\Index(name: 'idx_pending_rebroadcast_created_at', columns: ['created_at'])]
class PendingRebroadcast
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISMISSED = 'dismissed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Emission::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Emission $emission = null;

    #[ORM\Column(length: 80)]
    private string $assignmentGroupKey;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmission(): ?Emission
    {
        return $this->emission;
    }

    public function setEmission(Emission $emission): static
    {
        $this->emission = $emission;

        return $this;
    }

    public function getAssignmentGroupKey(): string
    {
        return $this->assignmentGroupKey;
    }

    public function setAssignmentGroupKey(string $assignmentGroupKey): static
    {
        $this->assignmentGroupKey = $assignmentGroupKey;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function isDismissed(): bool
    {
        return self::STATUS_DISMISSED === $this->status;
    }

    public function dismiss(): static
    {
        $this->status = self::STATUS_DISMISSED;

        return $this;
    }

    public function restore(): static
    {
        $this->status = self::STATUS_PENDING;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

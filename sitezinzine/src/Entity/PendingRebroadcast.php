<?php

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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Emission::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Emission $emission = null;

    #[ORM\Column(length: 80)]
    private string $assignmentGroupKey;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
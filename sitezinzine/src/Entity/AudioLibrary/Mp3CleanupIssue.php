<?php

declare(strict_types=1);

namespace App\Entity\AudioLibrary;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mp3_cleanup_issue')]
#[ORM\UniqueConstraint(name: 'uniq_mp3_cleanup_active', columns: ['storage', 'obsolete_relative_path', 'active_marker'])]
class Mp3CleanupIssue
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::BINARY, length: 12, options: ['fixed' => false])]
    private string $storage;

    #[ORM\Column(name: 'obsolete_relative_path', type: Types::BINARY, length: 1020, options: ['fixed' => false])]
    private string $obsoleteRelativePath;

    #[ORM\Column(type: Types::BINARY, length: 13, options: ['fixed' => false])]
    private string $status = 'open';

    #[ORM\Column(
        name: 'created_at', type: 'utc_datetime_microseconds', insertable: false, updatable: false,
        columnDefinition: 'DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6))', generated: 'INSERT',
    )]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'resolved_at', type: 'utc_datetime_microseconds', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(
        name: 'active_marker', type: Types::SMALLINT, nullable: true, insertable: false, updatable: false,
        columnDefinition: "TINYINT GENERATED ALWAYS AS (CASE WHEN status IN ('open', 'investigating') THEN 1 ELSE NULL END) STORED",
        generated: 'ALWAYS',
    )]
    private ?int $activeMarker = null;

    /** @var Collection<int, Mp3CleanupEvent> */
    #[ORM\OneToMany(targetEntity: Mp3CleanupEvent::class, mappedBy: 'issue', cascade: [], orphanRemoval: false)]
    private Collection $events;

    public function __construct()
    {
        $this->events = new ArrayCollection();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function startInvestigation(): void
    {
        if ($this->status === 'resolved') {
            throw new \DomainException('A resolved MP3 cleanup issue cannot be reopened.');
        }

        if ($this->status === 'open') {
            $this->status = 'investigating';
        }
    }

    public function resolve(\DateTimeImmutable $resolvedAt): void
    {
        if ($this->status === 'resolved') {
            return;
        }

        $this->resolvedAt = $resolvedAt->setTimezone(new \DateTimeZone('UTC'));
        $this->status = 'resolved';
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    /** @return Collection<int, Mp3CleanupEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }
}

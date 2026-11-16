<?php

declare(strict_types=1);

namespace App\Entity\AudioLibrary;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mp3_cleanup_event')]
class Mp3CleanupEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Mp3CleanupIssue::class, inversedBy: 'events', cascade: [])]
    #[ORM\JoinColumn(name: 'issue_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Mp3CleanupIssue $issue;

    #[ORM\Column(
        name: 'occurred_at', type: 'utc_datetime_microseconds', insertable: false, updatable: false,
        columnDefinition: 'DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6))', generated: 'INSERT',
    )]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::BINARY, length: 23, options: ['fixed' => false])]
    private string $reason;

    #[ORM\Column(name: 'emission_id', type: Types::INTEGER)]
    private int $emissionId;

    #[ORM\Column(name: 'obsolete_relative_path', type: Types::BINARY, length: 1020, options: ['fixed' => false])]
    private string $obsoleteRelativePath;

    #[ORM\Column(type: Types::JSON)]
    private array $context;

    #[ORM\Column(type: Types::JSON)]
    private array $diagnostic;
}

<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Table written by GridWeekValidation on the connection of the grid operation. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'grid_week_validation')]
class GridWeekValidationState
{
    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column]
    private bool $validated = false;

    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private int|string $manualRevision = 0;
}

<?php

declare(strict_types=1);

namespace App\Entity\AudioLibrary;

use App\Enum\Mp3TrashPhase as Phase;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mp3_trash_operation')]
#[ORM\UniqueConstraint(name: 'uniq_mp3_trash_emission_active', columns: ['emission_id', 'active_marker'])]
class Mp3TrashOperation
{
    #[ORM\Column(length: 25, enumType: Phase::class, options: ['collation' => 'utf8mb4_bin'])]
    private Phase $phase = Phase::SUPPRESSION_PREPAREE;

    #[ORM\Column(name: 'created_at', type: 'utc_datetime_microseconds', updatable: false)]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'utc_datetime_microseconds')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private bool $blocked = false;

    #[ORM\Column(name: 'last_diagnostic_code', length: 64, nullable: true)]
    private ?string $lastDiagnosticCode = null;

    #[ORM\Column(name: 'last_diagnostic_at', type: 'utc_datetime_microseconds', nullable: true)]
    private ?\DateTimeImmutable $lastDiagnosticAt = null;

    #[ORM\Column(name: 'last_attempt_id', length: 32, nullable: true)]
    private ?string $lastAttemptId = null;

    /** @var array<string, array{actor_id: int, started_at: string, outcome: string, finished_at?: string}> */
    #[ORM\Column(name: 'restoration_attempts', type: Types::JSON)]
    private array $restorationAttempts = [];

    #[ORM\Column(
        name: 'active_marker', type: Types::SMALLINT, nullable: true, insertable: false, updatable: false, generated: 'ALWAYS',
        columnDefinition: "SMALLINT GENERATED ALWAYS AS (CASE WHEN phase IN ('SUPPRESSION_PREPAREE', 'RETRAIT_PUBLIC_EN_ATTENTE', 'RESTAURATION_PREPAREE', 'RETRAIT_PRIVE_EN_ATTENTE') THEN 1 ELSE NULL END) STORED",
    )]
    private ?int $activeMarker = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 32, updatable: false)]
        private readonly string $id,
        #[ORM\Column(name: 'emission_id', updatable: false)]
        private readonly int $emissionId,
        #[ORM\Column(name: 'original_thumbnail_mp3', length: 255, updatable: false)]
        private readonly string $originalThumbnailMp3,
        #[ORM\Column(name: 'original_url', length: 250, nullable: true, updatable: false)]
        private readonly ?string $originalUrl,
        #[ORM\Column(name: 'size_bytes', type: Types::BIGINT, updatable: false)]
        private readonly int $size,
        #[ORM\Column(length: 64, updatable: false)]
        private readonly string $sha256,
        #[ORM\Column(name: 'author_id', updatable: false)]
        private readonly int $authorId,
        \DateTimeImmutable $createdAt,
    ) {
        self::validateId($id);
        if ($emissionId <= 0 || $authorId <= 0 || $size <= 0 || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new \InvalidArgumentException('Invalid MP3 trash operation identity or content metadata.');
        }
        if ($originalThumbnailMp3 === '' || mb_strlen($originalThumbnailMp3, 'UTF-8') > 255
            || ($originalUrl !== null && mb_strlen($originalUrl, 'UTF-8') > 250)) {
            throw new \InvalidArgumentException('Invalid length of the original MP3 references.');
        }
        $this->createdAt = $this->updatedAt = self::utc($createdAt);
    }

    public function id(): string { return $this->id; }
    public function emissionId(): int { return $this->emissionId; }
    public function originalThumbnailMp3(): string { return $this->originalThumbnailMp3; }
    public function originalUrl(): ?string { return $this->originalUrl; }
    public function trashRelativePath(): string { return self::trashRelativePathFor($this->id); }

    public static function trashRelativePathFor(string $id): string
    {
        self::validateId($id);

        return $id . '.mp3';
    }
    public function size(): int { return $this->size; }
    public function sha256(): string { return $this->sha256; }
    public function authorId(): int { return $this->authorId; }
    public function phase(): Phase { return $this->phase; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function isBlocked(): bool { return $this->blocked; }
    public function lastDiagnosticCode(): ?string { return $this->lastDiagnosticCode; }
    public function lastDiagnosticAt(): ?\DateTimeImmutable { return $this->lastDiagnosticAt; }
    public function blockVersion(): ?string
    {
        return $this->blocked ? $this->lastDiagnosticAt?->format('Y-m-d H:i:s.u') : null;
    }
    public function restorationAttempts(): array { return $this->restorationAttempts; }
    public function lastAttemptId(): ?string { return $this->lastAttemptId; }

    public function confirmDisassociation(\DateTimeImmutable $at): void
    {
        $this->transition(Phase::SUPPRESSION_PREPAREE, Phase::RETRAIT_PUBLIC_EN_ATTENTE, $at);
    }

    public function confirmPublicRemoval(\DateTimeImmutable $at): void
    {
        $this->transition(Phase::RETRAIT_PUBLIC_EN_ATTENTE, Phase::EN_CORBEILLE, $at);
    }

    public function cancelDeletion(\DateTimeImmutable $at): void
    {
        $this->transition(Phase::SUPPRESSION_PREPAREE, Phase::ANNULEE, $at);
    }

    public function prepareRestoration(string $attemptId, int $actorId, \DateTimeImmutable $at): void
    {
        self::validateId($attemptId);
        if ($actorId <= 0) {
            throw new \InvalidArgumentException('Invalid restoration actor.');
        }
        $attempt = $this->restorationAttempts[$attemptId] ?? null;
        if ($attempt !== null) {
            if ($this->phase === Phase::RESTAURATION_PREPAREE && $this->lastAttemptId === $attemptId
                && $attempt['actor_id'] === $actorId) {
                return;
            }
            throw new \DomainException('Restoration attempt ID already used.');
        }
        $this->requirePhase(Phase::EN_CORBEILLE);
        $this->transition(Phase::EN_CORBEILLE, Phase::RESTAURATION_PREPAREE, $at);
        $this->lastAttemptId = $attemptId;
        $this->restorationAttempts[$attemptId] = [
            'actor_id' => $actorId,
            'started_at' => $this->updatedAt->format('Y-m-d\\TH:i:s.uP'),
            'outcome' => 'pending',
        ];
    }

    public function confirmAssociation(string $attemptId, \DateTimeImmutable $at): void
    {
        $this->requireAttempt($attemptId);
        $this->transition(Phase::RESTAURATION_PREPAREE, Phase::RETRAIT_PRIVE_EN_ATTENTE, $at);
    }

    public function confirmPrivateRemoval(string $attemptId, \DateTimeImmutable $at): void
    {
        $this->finishRestoration($attemptId, Phase::RETRAIT_PRIVE_EN_ATTENTE, Phase::RESTAUREE, 'restored', $at);
    }

    public function cancelRestoration(string $attemptId, \DateTimeImmutable $at): void
    {
        $this->finishRestoration($attemptId, Phase::RESTAURATION_PREPAREE, Phase::EN_CORBEILLE, 'cancelled', $at);
    }

    public function block(Phase $expected, string $code, \DateTimeImmutable $at): void
    {
        $this->requireRecoverablePhase($expected);
        if ($expected === Phase::EN_CORBEILLE) {
            throw new \DomainException('A completed deletion cannot be blocked.');
        }
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $code) !== 1) {
            throw new \InvalidArgumentException('Invalid diagnostic code.');
        }
        if ($this->blocked && $this->lastDiagnosticCode === $code) {
            return;
        }
        $this->blocked = true;
        $this->lastDiagnosticCode = $code;
        $at = self::utc($at);
        if ($this->lastDiagnosticAt !== null && $at <= $this->lastDiagnosticAt) {
            $at = $this->lastDiagnosticAt->modify('+1 microsecond');
        }
        $this->lastDiagnosticAt = $this->updatedAt = $at;
    }

    public function resume(Phase $expected, \DateTimeImmutable $at): void
    {
        $this->requireRecoverablePhase($expected);
        if ($this->blocked) {
            $this->blocked = false;
            $this->updatedAt = self::utc($at);
        }
    }

    private function transition(Phase $from, Phase $to, \DateTimeImmutable $at): void
    {
        if ($this->phase === $to) {
            return;
        }
        $this->requirePhase($from);
        $this->phase = $to;
        $this->updatedAt = self::utc($at);
    }

    private function finishRestoration(string $attemptId, Phase $from, Phase $to, string $outcome, \DateTimeImmutable $at): void
    {
        $this->requireAttempt($attemptId);
        if ($this->phase === $to && $this->restorationAttempts[$attemptId]['outcome'] === $outcome) {
            return;
        }
        $this->requirePhase($from);
        $this->transition($from, $to, $at);
        $this->restorationAttempts[$attemptId]['outcome'] = $outcome;
        $this->restorationAttempts[$attemptId]['finished_at'] = $this->updatedAt->format('Y-m-d\\TH:i:s.uP');
    }

    private function requirePhase(Phase $phase): void
    {
        if ($this->blocked || $this->phase !== $phase) {
            throw new \DomainException('The MP3 trash operation cannot transition from its current state.');
        }
    }

    private function requireRecoverablePhase(Phase $phase): void
    {
        if ($this->phase !== $phase || $phase === Phase::ANNULEE || $phase === Phase::RESTAUREE) {
            throw new \DomainException('The MP3 trash operation cannot be blocked or resumed in this phase.');
        }
    }

    private function requireAttempt(string $attemptId): void
    {
        if ($this->lastAttemptId !== $attemptId) {
            throw new \DomainException('Stale MP3 restoration attempt.');
        }
    }

    private static function validateId(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Invalid MP3 trash operation or attempt ID.');
        }
    }

    private static function utc(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTimezone(new \DateTimeZone('UTC'));
    }
}

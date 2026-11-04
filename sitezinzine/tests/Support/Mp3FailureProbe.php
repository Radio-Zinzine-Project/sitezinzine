<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Emission;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;

/** In-memory Monolog sink and deterministic failure at existing IO boundaries. */
final class Mp3FailureProbe extends AbstractHandler
{
    public array $records = [];
    public int $faultCount = 0;
    public bool $failLogging = false;
    public bool $loggingFailed = false;
    public ?string $armedStage = null;
    public readonly \RuntimeException $origin;
    public readonly \RuntimeException $loggingError;

    public function __construct(private int $emissionId, private ?string $original)
    {
        parent::__construct(bubble: false);
        $this->origin = new \RuntimeException('MP3_TECHNICAL_SENTINEL: ' . Mp3TestStorage::root() . '/private/encoder-detail');
        $this->loggingError = new \RuntimeException('MP3_LOG_FAILURE_SENTINEL');
    }

    public function fail(): never
    {
        ++$this->faultCount;
        throw $this->origin;
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        if ($this->armedStage !== 'doctrine') {
            return;
        }
        foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Emission || $entity->getId() !== $this->emissionId) {
                continue;
            }
            $relative = $entity->getThumbnailMp3();
            if ($relative !== null && $relative !== $this->original && str_contains($relative, '/')
                && !str_contains($relative, '..') && is_file(Mp3TestStorage::root() . '/' . $relative)) {
                $this->fail();
            }
        }
    }

    public function handle(LogRecord $record): bool
    {
        $this->records[] = $record;
        if ($this->failLogging && self::containsOrigin([$record->message, $record->context])) {
            $this->loggingFailed = true;
            throw $this->loggingError;
        }
        return true;
    }

    public static function containsOrigin(mixed $value): bool
    {
        if ($value instanceof \Throwable) {
            return str_contains($value->getMessage(), 'MP3_TECHNICAL_SENTINEL')
                || ($value->getPrevious() !== null && self::containsOrigin($value->getPrevious()));
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsOrigin($item)) {
                    return true;
                }
            }
        }
        return is_string($value) && str_contains($value, 'MP3_TECHNICAL_SENTINEL');
    }

    /** Accept structured fields nested in a context; no particular logger/channel is required. */
    public static function field(array $context, array $aliases): mixed
    {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $context)) {
                return $context[$alias];
            }
        }
        foreach ($context as $value) {
            if (is_array($value) && ($found = self::field($value, $aliases)) !== null) {
                return $found;
            }
        }
        return null;
    }

    public static function diagnosticText(mixed $value): string
    {
        if ($value instanceof \Throwable) {
            return $value::class . ' ' . $value->getMessage() . ' ' . $value->getTraceAsString()
                . ($value->getPrevious() !== null ? ' ' . self::diagnosticText($value->getPrevious()) : '');
        }
        if (is_array($value)) {
            return implode(' ', array_map([self::class, 'diagnosticText'], $value));
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return is_object($value) ? $value::class : '';
    }

    public static function containsSensitiveObject(mixed $value): bool
    {
        if ($value instanceof \App\Entity\User
            || $value instanceof \Symfony\Component\HttpFoundation\Request
            || $value instanceof \Symfony\Component\Security\Core\Authentication\Token\TokenInterface
            || is_resource($value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsSensitiveObject($item)) {
                    return true;
                }
            }
        }
        return false;
    }
}

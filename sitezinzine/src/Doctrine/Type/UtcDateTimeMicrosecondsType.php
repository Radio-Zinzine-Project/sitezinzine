<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;

/** Local UTC DATETIME(6) conversion for AudioLibrary; does not register or override any type. */
final class UtcDateTimeMicrosecondsType extends DateTimeImmutableType
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'DATETIME(6)';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof \DateTimeInterface) {
            throw InvalidType::new($value, self::class, ['null', \DateTimeInterface::class]);
        }

        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::FORMAT);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string']);
        }

        try {
            $date = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new \DateTimeZone('UTC'));
        } catch (\ValueError $error) {
            throw InvalidFormat::new($value, self::class, self::FORMAT, $error);
        }

        if ($date === false || $date->format(self::FORMAT) !== $value) {
            throw InvalidFormat::new($value, self::class, self::FORMAT);
        }

        return $date;
    }
}

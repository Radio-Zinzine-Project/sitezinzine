<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Type;

use App\Doctrine\Type\UtcDateTimeMicrosecondsType;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;

/** Isolated conversion contract: no registry mutation, kernel or database connection. */
final class UtcDateTimeMicrosecondsTypeTest extends TestCase
{
    public function testMySqlDeclarationPreservesSixDecimalPlaces(): void
    {
        $type = new UtcDateTimeMicrosecondsType();

        self::assertSame('DATETIME(6)', $type->getSQLDeclaration([], new MySQL80Platform()));
    }

    /** @dataProvider dateMutability */
    public function testWritingNormalizesUtcWithoutMutatingTheInput(bool $mutable): void
    {
        $type = new UtcDateTimeMicrosecondsType();
        $value = $mutable
            ? new \DateTime('2026-11-15 01:02:03.123456+05:30')
            : new \DateTimeImmutable('2026-11-15 01:02:03.123456+05:30');
        $before = $value->format('Y-m-d H:i:s.u P');
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Honolulu');

            self::assertSame('2026-11-14 19:32:03.123456', $type->convertToDatabaseValue($value, new MySQL80Platform()));
            self::assertSame($before, $value->format('Y-m-d H:i:s.u P'));
            self::assertSame('Pacific/Honolulu', date_default_timezone_get());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public static function dateMutability(): array
    {
        return ['immutable' => [false], 'mutable' => [true]];
    }

    /** @dataProvider defaultTimezones */
    public function testHydrationUsesExplicitUtcRegardlessOfPhpDefault(string $defaultTimezone): void
    {
        $type = new UtcDateTimeMicrosecondsType();
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set($defaultTimezone);
            $value = $type->convertToPHPValue('2026-11-14 19:32:03.000007', new MySQL80Platform());

            self::assertInstanceOf(\DateTimeImmutable::class, $value);
            self::assertSame('UTC', $value->getTimezone()->getName());
            self::assertSame('2026-11-14 19:32:03.000007', $value->format('Y-m-d H:i:s.u'));
            self::assertSame($defaultTimezone, date_default_timezone_get());
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public static function defaultTimezones(): array
    {
        return [['Europe/Paris'], ['Pacific/Honolulu']];
    }

    public function testNullIsPreservedInBothDirections(): void
    {
        $type = new UtcDateTimeMicrosecondsType();
        $platform = new MySQL80Platform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToPHPValue(null, $platform));
    }

    /** @dataProvider unsupportedWriteValues */
    public function testUnsupportedPhpWriteValueRaisesDbalConversionError(mixed $value): void
    {
        $type = new UtcDateTimeMicrosecondsType();

        $this->expectException(InvalidType::class);
        $type->convertToDatabaseValue($value, new MySQL80Platform());
    }

    public static function unsupportedWriteValues(): array
    {
        return ['string' => ['2026-11-14 19:32:03.123456'], 'integer' => [42], 'array' => [[]], 'object' => [new \stdClass()]];
    }

    /** @dataProvider unsupportedReadValues */
    public function testUnsupportedDatabaseValueRaisesDbalConversionError(mixed $value): void
    {
        $type = new UtcDateTimeMicrosecondsType();

        $this->expectException(InvalidType::class);
        $type->convertToPHPValue($value, new MySQL80Platform());
    }

    public static function unsupportedReadValues(): array
    {
        return ['integer' => [42], 'array' => [[]], 'object' => [new \stdClass()]];
    }

    public function testMalformedDatabaseDateRaisesExplicitFormatError(): void
    {
        $type = new UtcDateTimeMicrosecondsType();

        $this->expectException(InvalidFormat::class);
        $type->convertToPHPValue('not-a-date', new MySQL80Platform());
    }

    public function testRoundTripRetainsTheInstantAndAllMicroseconds(): void
    {
        $type = new UtcDateTimeMicrosecondsType();
        $platform = new MySQL80Platform();
        $original = new \DateTimeImmutable('2026-11-15 22:03:04.987654-03:00');

        $result = $type->convertToPHPValue($type->convertToDatabaseValue($original, $platform), $platform);

        self::assertInstanceOf(\DateTimeImmutable::class, $result);
        self::assertSame($original->format('U.u'), $result->format('U.u'));
        self::assertSame('2026-11-16 01:03:04.987654', $result->format('Y-m-d H:i:s.u'));
        self::assertSame('UTC', $result->getTimezone()->getName());
    }

    public function testConstructingLocalTypeDoesNotReplaceDoctrineBuiltinTypes(): void
    {
        $before = Type::getType(Types::DATETIME_IMMUTABLE);
        new UtcDateTimeMicrosecondsType();

        self::assertSame($before, Type::getType(Types::DATETIME_IMMUTABLE));
        self::assertSame('DATETIME', $before->getSQLDeclaration([], new MySQL80Platform()));
    }
}

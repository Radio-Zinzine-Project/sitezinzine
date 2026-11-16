<?php

declare(strict_types=1);

namespace App\Tests\Entity\AudioLibrary;

use App\Entity\AudioLibrary\Mp3CleanupEvent;
use App\Entity\AudioLibrary\Mp3CleanupIssue;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use PHPUnit\Framework\TestCase;

/** Attribute metadata only: no EntityManager, type registration, schema generation or connection. */
final class Mp3CleanupMappingTest extends TestCase
{
    /** @dataProvider entities */
    public function testTablesAndGeneratedIntegerIdentifiers(string $class, string $table): void
    {
        $metadata = $this->metadata($class);

        self::assertSame($table, $metadata->getTableName());
        self::assertSame(['id'], $metadata->identifier);
        self::assertSame(Types::INTEGER, $metadata->getFieldMapping('id')->type);
        self::assertTrue($metadata->usesIdGenerator());
        self::assertContains($metadata->generatorType, [ClassMetadata::GENERATOR_TYPE_AUTO, ClassMetadata::GENERATOR_TYPE_IDENTITY]);
    }

    public static function entities(): array
    {
        return [[Mp3CleanupIssue::class, 'mp3_cleanup_issue'], [Mp3CleanupEvent::class, 'mp3_cleanup_event']];
    }

    public function testIssueIdentityAndStatusUseVariableLengthBinaryColumns(): void
    {
        $metadata = $this->metadata(Mp3CleanupIssue::class);
        foreach ([['storage', 'storage', 12], ['obsoleteRelativePath', 'obsolete_relative_path', 1020], ['status', 'status', 13]] as [$field, $column, $length]) {
            $mapping = $metadata->getFieldMapping($field);
            self::assertSame($column, $mapping->columnName);
            self::assertSame(Types::BINARY, $mapping->type);
            self::assertSame($length, $mapping->length);
            self::assertFalse($mapping->nullable ?? false);
            self::assertFalse($mapping->options['fixed'] ?? false);
        }
    }

    public function testActiveMarkerIsGeneratedReadOnlyAndPartOfNamedUniqueKey(): void
    {
        $metadata = $this->metadata(Mp3CleanupIssue::class);
        $marker = $metadata->getFieldMapping('activeMarker');

        self::assertSame('active_marker', $marker->columnName);
        self::assertSame(Types::SMALLINT, $marker->type);
        self::assertTrue($marker->nullable);
        self::assertTrue($marker->notInsertable);
        self::assertTrue($marker->notUpdatable);
        self::assertSame(ClassMetadata::GENERATED_ALWAYS, $marker->generated);
        self::assertSame(
            ['storage', 'obsolete_relative_path', 'active_marker'],
            $metadata->table['uniqueConstraints']['uniq_mp3_cleanup_active']['columns']
        );
        // Exact generation expression and CHECK constraints belong to the manual migration.
    }

    /** @dataProvider temporalFields */
    public function testTemporalMappingUsesLocalTypeAndSqlOwnedCreationDates(string $class, string $field, string $column, bool $nullable, bool $sqlGenerated): void
    {
        $metadata = $this->metadata($class);
        $mapping = $metadata->getFieldMapping($field);
        $property = new \ReflectionProperty($class, $field);

        self::assertSame('utc_datetime_microseconds', $mapping->type);
        self::assertSame($column, $mapping->columnName);
        self::assertSame($nullable, $mapping->nullable ?? false);
        self::assertSame(\DateTimeImmutable::class, $property->getType()->getName());
        self::assertSame($nullable, $property->getType()->allowsNull());
        if ($sqlGenerated) {
            self::assertTrue($mapping->notInsertable);
            self::assertTrue($mapping->notUpdatable);
            self::assertSame(ClassMetadata::GENERATED_INSERT, $mapping->generated);
        } else {
            self::assertFalse($mapping->notUpdatable ?? false);
        }
    }

    public static function temporalFields(): array
    {
        return [
            [Mp3CleanupIssue::class, 'createdAt', 'created_at', false, true],
            [Mp3CleanupIssue::class, 'resolvedAt', 'resolved_at', true, false],
            [Mp3CleanupEvent::class, 'occurredAt', 'occurred_at', false, true],
        ];
    }

    public function testIssueEventsAreInverseAndHaveNoDeletionCascadeOrOrphanRemoval(): void
    {
        $metadata = $this->metadata(Mp3CleanupIssue::class);
        $association = $metadata->getAssociationMapping('events');
        self::assertInstanceOf(OneToManyAssociationMapping::class, $association);

        self::assertSame(ClassMetadata::ONE_TO_MANY, $association->type());
        self::assertSame(Mp3CleanupEvent::class, $association->targetEntity);
        self::assertSame('issue', $association->mappedBy);
        self::assertFalse($association->isOwningSide());
        self::assertFalse($association->orphanRemoval);
        self::assertSame([], $association->cascade);
        self::assertContains($association->fetch, [ClassMetadata::FETCH_LAZY, ClassMetadata::FETCH_EXTRA_LAZY]);
    }

    public function testIssueStartsOpenWithAnEmptyEventCollection(): void
    {
        $issue = new Mp3CleanupIssue();

        self::assertSame('open', $issue->getStatus());
        self::assertInstanceOf(Collection::class, $issue->getEvents());
        self::assertCount(0, $issue->getEvents());
    }

    public function testEventPayloadMatchesDbalColumnsWithoutAnEmissionAssociation(): void
    {
        $metadata = $this->metadata(Mp3CleanupEvent::class);
        foreach ([
            ['reason', 'reason', Types::BINARY, 23],
            ['emissionId', 'emission_id', Types::INTEGER, null],
            ['obsoleteRelativePath', 'obsolete_relative_path', Types::BINARY, 1020],
            ['context', 'context', Types::JSON, null],
            ['diagnostic', 'diagnostic', Types::JSON, null],
        ] as [$field, $column, $type, $length]) {
            $mapping = $metadata->getFieldMapping($field);
            self::assertSame($column, $mapping->columnName);
            self::assertSame($type, $mapping->type);
            self::assertFalse($mapping->nullable ?? false);
            if ($length !== null) {
                self::assertSame($length, $mapping->length);
                self::assertFalse($mapping->options['fixed'] ?? false);
            }
        }
        self::assertSame(['issue'], array_keys($metadata->associationMappings));
        self::assertSame('int', (new \ReflectionProperty(Mp3CleanupEvent::class, 'emissionId'))->getType()->getName());
        self::assertSame('array', (new \ReflectionProperty(Mp3CleanupEvent::class, 'context'))->getType()->getName());
        self::assertSame('array', (new \ReflectionProperty(Mp3CleanupEvent::class, 'diagnostic'))->getType()->getName());
    }

    public function testEventRequiresAnIssueAndNeverCascadesDeletion(): void
    {
        $metadata = $this->metadata(Mp3CleanupEvent::class);
        $association = $metadata->getAssociationMapping('issue');
        self::assertInstanceOf(ManyToOneAssociationMapping::class, $association);

        self::assertSame(ClassMetadata::MANY_TO_ONE, $association->type());
        self::assertTrue($association->isOwningSide());
        self::assertSame(Mp3CleanupIssue::class, $association->targetEntity);
        self::assertSame('events', $association->inversedBy);
        self::assertSame([], $association->cascade);
        self::assertCount(1, $association->joinColumns);
        self::assertSame('issue_id', $association->joinColumns[0]->name);
        self::assertSame('id', $association->joinColumns[0]->referencedColumnName);
        self::assertFalse($association->joinColumns[0]->nullable);
        self::assertSame('RESTRICT', $association->joinColumns[0]->onDelete);
    }

    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class, new UnderscoreNamingStrategy());
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new AttributeDriver([]))->loadMetadataForClass($class, $metadata);
        return $metadata;
    }
}

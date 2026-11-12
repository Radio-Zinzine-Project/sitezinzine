<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce accent-sensitive, case-insensitive uniqueness of emission audio URLs.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->assertPreconditions('utf8mb4_unicode_ci', 'idx_emission_url', 1);

        $duplicate = $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM emission
            WHERE url IS NOT NULL
            GROUP BY url COLLATE utf8mb4_0900_as_ci
            HAVING COUNT(*) > 1
            LIMIT 1
            SQL);
        $this->abortIf(false !== $duplicate, 'Duplicate audio URLs exist under the target collation. No data will be corrected automatically.');

        $this->addSql(<<<'SQL'
            ALTER TABLE emission
                MODIFY COLUMN url VARCHAR(250)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_ci NULL DEFAULT NULL,
                DROP INDEX idx_emission_url,
                ADD UNIQUE INDEX uniq_emission_url (url)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->assertPreconditions('utf8mb4_0900_as_ci', 'uniq_emission_url', 0);

        $this->addSql(<<<'SQL'
            ALTER TABLE emission
                MODIFY COLUMN url VARCHAR(250)
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
                DROP INDEX uniq_emission_url,
                ADD INDEX idx_emission_url (url)
            SQL);
    }

    private function assertPreconditions(string $collation, string $indexName, int $nonUnique): void
    {
        $version = $this->connection->getServerVersion();
        $this->abortIf(!str_starts_with($version, '8.0.') || str_contains(strtolower($version), 'mariadb'), 'This migration requires MySQL 8.0.');
        $database = $this->connection->fetchOne('SELECT DATABASE()');
        $this->abortIf(false === $database || null === $database || '' === $database, 'No database selected.');
        $this->abortIf(null === $database || '' === $database, 'No database selected.');

        $table = $this->connection->fetchAssociative(
            'SELECT ENGINE, ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, 'emission'],
        );
        $this->abortIf(false === $table || 'InnoDB' !== $table['ENGINE'] || !in_array($table['ROW_FORMAT'], ['Dynamic', 'Compressed'], true), 'Expected an InnoDB emission table with Dynamic or Compressed row format.');
        $this->abortIf(16384 !== (int) $this->connection->fetchOne('SELECT @@innodb_page_size'), 'Expected 16 KiB InnoDB pages.');

        $column = $this->connection->fetchAssociative(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, 'emission', 'url'],
        );
        $this->abortIf(
            false === $column || 'varchar(250)' !== $column['COLUMN_TYPE']
                || 'YES' !== $column['IS_NULLABLE'] || null !== $column['COLUMN_DEFAULT']
                || 'utf8mb4' !== $column['CHARACTER_SET_NAME'] || $collation !== $column['COLLATION_NAME']
                || '' !== $column['EXTRA'] || '' !== $column['COLUMN_COMMENT'],
            'Unexpected definition of emission.url; refusing to replace its attributes.',
        );

        // Inspect every component of any index involving url, including composite indexes.
        $indexes = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT s.INDEX_NAME, s.NON_UNIQUE, s.COLUMN_NAME, s.SEQ_IN_INDEX,
                   s.SUB_PART, s.INDEX_TYPE, s.IS_VISIBLE, s.COLLATION
            FROM information_schema.STATISTICS s
            WHERE s.TABLE_SCHEMA = ? AND s.TABLE_NAME = 'emission'
              AND s.INDEX_NAME IN (
                  SELECT INDEX_NAME FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'emission'
                    AND (COLUMN_NAME = 'url' OR INDEX_NAME IN ('idx_emission_url', 'uniq_emission_url'))
              )
            SQL, [$database, $database]);
        $index = $indexes[0] ?? [];
        $this->abortIf(
            1 !== count($indexes) || $indexName !== ($index['INDEX_NAME'] ?? null)
                || $nonUnique !== (int) ($index['NON_UNIQUE'] ?? -1)
                || 'url' !== ($index['COLUMN_NAME'] ?? null) || 1 !== (int) ($index['SEQ_IN_INDEX'] ?? 0)
                || null !== ($index['SUB_PART'] ?? null) || 'BTREE' !== ($index['INDEX_TYPE'] ?? null)
                || 'YES' !== ($index['IS_VISIBLE'] ?? null) || 'A' !== ($index['COLLATION'] ?? null),
            'Expected only the full, visible, ascending BTREE URL index with the specified name and uniqueness.',
        );

        $foreignKey = $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
            WHERE (TABLE_SCHEMA = ? AND TABLE_NAME = 'emission' AND COLUMN_NAME = 'url'
                   AND REFERENCED_TABLE_NAME IS NOT NULL)
               OR (REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = 'emission'
                   AND REFERENCED_COLUMN_NAME = 'url')
            LIMIT 1
            SQL, [$database, $database]);
        $this->abortIf(false !== $foreignKey, 'A foreign key depends on emission.url.');
    }
}

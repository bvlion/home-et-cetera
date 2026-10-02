<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataImporter;
use PDO;
use PHPUnit\Framework\TestCase;

final class LegacyDataImporterTest extends TestCase
{
    private PDO $connection;
    private ConnectionFactory $connectionFactory;

    protected function setUp(): void
    {
        $databaseHost = $_ENV['TEST_DB_HOST']
            ?? $_SERVER['TEST_DB_HOST']
            ?? 'database';
        $this->connectionFactory = new ConnectionFactory(
            $databaseHost,
            '3306',
            'example_database',
            'example_database_user',
            'example_database_password',
        );
        $this->connection = $this->connectionFactory->create();
        $this->clearTargetTables();
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }

        $this->clearTargetTables();
    }

    private function clearTargetTables(): void
    {
        $this->connection->exec('DELETE FROM mail_api');
        $this->connection->exec('DELETE FROM dating');
    }

    /**
     * The example fixtures below always contain 2 dating rows and 2
     * mail_api rows (1 enabled with a channel, 1 with a null channel).
     * The expected counts are configured to match, since the
     * production defaults (4 / 44 / 43 / 1 / 31) are specific to
     * the real legacy dataset confirmed for Issue #15.
     */
    private function createImporter(): LegacyDataImporter
    {
        return new LegacyDataImporter(
            $this->connectionFactory,
            expectedDatingCount: 2,
            expectedMailApiCount: 2,
            expectedMailApiEnabledCount: 2,
            expectedMailApiDisabledCount: 0,
            expectedMailApiNullChannelCount: 1,
        );
    }

    /**
     * @return list<array{id: int, target_date: string, message: string}>
     */
    private function exampleDatingRows(): array
    {
        return [
            ['id' => 1, 'target_date' => '0411', 'message' => 'Example annual.'],
            ['id' => 2, 'target_date' => '20260101', 'message' => 'Example: %s'],
        ];
    }

    /**
     * @return list<array{
     *     id: int,
     *     target_from: string,
     *     to_folder: string,
     *     channel: string|null,
     *     user_name: string|null,
     *     icon_url: string|null,
     *     prefix_format: string|null,
     *     enable_flag: int
     * }>
     */
    private function exampleMailApiRows(): array
    {
        return [
            [
                'id' => 40,
                'target_from' => 'example-sender-a',
                'to_folder' => 'ExampleArchiveA',
                'channel' => 'example-legacy-channel',
                'user_name' => 'Example Bot',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
                'enable_flag' => 1,
            ],
            [
                'id' => 41,
                'target_from' => 'example-sender-b',
                'to_folder' => 'ExampleArchiveB',
                'channel' => null,
                'user_name' => null,
                'icon_url' => null,
                'prefix_format' => null,
                'enable_flag' => 1,
            ],
        ];
    }

    /**
     * @return array{dating_channel: string}
     */
    private function exampleSettings(): array
    {
        return [
            'dating_channel' => 'example-legacy-channel',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function exampleChannelMap(): array
    {
        return ['example-legacy-channel' => 'C0000000000'];
    }

    public function testDryRunChecksTablesButWritesNothing(): void
    {
        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            true,
        );

        self::assertTrue($report['valid']);
        self::assertTrue($report['dry_run']);
        self::assertFalse($report['executed']);
        self::assertTrue($report['can_execute']);
        self::assertTrue($report['all_tables_empty']);
        self::assertSame(
            ['dating' => 0, 'mail_api' => 0],
            $report['existing_counts'],
        );
        self::assertTrue($report['expected_counts']['dating']['matches']);
        self::assertSame(2, $report['expected_counts']['dating']['actual']);
        self::assertTrue($report['expected_counts']['mail_api']['matches']);
        self::assertTrue($report['expected_counts']['mail_api_enabled']['matches']);
        self::assertTrue($report['expected_counts']['mail_api_disabled']['matches']);
        self::assertTrue($report['expected_counts']['mail_api_null_channel']['matches']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
        );
    }

    public function testImportsAllTablesInSingleTransaction(): void
    {
        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertTrue($report['can_execute']);
        self::assertTrue($report['executed']);
        self::assertSame(2, $report['dating_inserted']);
        self::assertSame(2, $report['mail_api_inserted']);

        $datingChannelIds = $this->connection
            ->query('SELECT channel_id FROM dating ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['C0000000000', 'C0000000000'], $datingChannelIds);

        $mailApiChannelIds = $this->connection
            ->query('SELECT channel_id FROM mail_api ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['C0000000000', null], $mailApiChannelIds);
    }

    public function testImportPreservesDisplayColumnsIncludingNulls(): void
    {
        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['executed']);

        $mailApiRows = $this->connection
            ->query(
                'SELECT user_name, icon_url, prefix_format '
                    . 'FROM mail_api ORDER BY id',
            )
            ->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(
            [
                'user_name' => 'Example Bot',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
            $mailApiRows[0],
        );
        self::assertSame(
            [
                'user_name' => null,
                'icon_url' => null,
                'prefix_format' => null,
            ],
            $mailApiRows[1],
        );
    }

    public function testAbortsWhenTargetTableIsNotEmpty(): void
    {
        $this->connection->exec(
            "INSERT INTO dating (target_date, message, channel_id) "
                . "VALUES ('0101', 'Existing.', 'C0000000000')",
        );

        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertFalse($report['can_execute']);
        self::assertFalse($report['executed']);
        self::assertFalse($report['all_tables_empty']);
        self::assertSame('dating is not empty.', $report['abort_reason']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
        );
    }

    public function testDryRunAlsoAbortsWhenTargetTableIsNotEmpty(): void
    {
        $this->connection->exec(
            "INSERT INTO dating (target_date, message, channel_id) "
                . "VALUES ('0101', 'Existing.', 'C0000000000')",
        );

        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            true,
        );

        self::assertFalse($report['can_execute']);
        self::assertFalse($report['all_tables_empty']);
        self::assertSame('dating is not empty.', $report['abort_reason']);
    }

    public function testAbortsWhenChannelMapIsMissingAnEntry(): void
    {
        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            [],
            false,
        );

        self::assertFalse($report['valid']);
        self::assertFalse($report['executed']);
        self::assertNotEmpty($report['errors']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
        );
    }

    public function testAbortsWhenInputCountsDoNotMatchExpectations(): void
    {
        $report = $this->createImporter()->import(
            [$this->exampleDatingRows()[0]],
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertFalse($report['valid']);
        self::assertFalse($report['executed']);
        self::assertFalse($report['expected_counts']['dating']['matches']);
        self::assertSame(2, $report['expected_counts']['dating']['expected']);
        self::assertSame(1, $report['expected_counts']['dating']['actual']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
        );
    }

    public function testTransactionRollsBackWhenAnInsertFails(): void
    {
        // Application-level validation already rejects malformed
        // values (see the channel_map tests below), so to exercise the
        // rollback path itself, a real database-level failure is
        // forced by temporarily narrowing mail_api.to_folder. dating
        // (inserted first) succeeds, and mail_api (inserted second, in
        // the same transaction) fails, proving the already-inserted
        // dating rows are rolled back together with it.
        $this->connection->exec(
            'ALTER TABLE mail_api MODIFY COLUMN to_folder VARCHAR(5) NOT NULL',
        );

        try {
            $report = $this->createImporter()->import(
                $this->exampleDatingRows(),
                $this->exampleMailApiRows(),
                $this->exampleSettings(),
                $this->exampleChannelMap(),
                false,
            );
        } finally {
            $this->connection->exec(
                'ALTER TABLE mail_api MODIFY COLUMN to_folder VARCHAR(255) NOT NULL',
            );
        }

        self::assertTrue($report['can_execute']);
        self::assertFalse($report['valid']);
        self::assertFalse($report['executed']);
        self::assertSame(0, $report['dating_inserted']);
        self::assertSame(0, $report['mail_api_inserted']);
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
        );
    }

    public function testOversizedUserNameIsRejectedWithAnError(): void
    {
        $rows = $this->exampleMailApiRows();
        $rows[0]['user_name'] = str_repeat('a', 256);

        $resolved = $this->createImporter()->resolve(
            [],
            $rows,
            $this->exampleSettings(),
            $this->exampleChannelMap(),
        );

        self::assertNotEmpty($resolved['errors']);
        self::assertSame([], array_filter(
            $resolved['mail_api'],
            static fn (array $row): bool => $row['id'] === 40,
        ));
    }

    public function testOversizedIconUrlIsRejectedWithAnError(): void
    {
        $rows = $this->exampleMailApiRows();
        $rows[0]['icon_url'] = 'https://example.test/' . str_repeat('a', 512);

        $resolved = $this->createImporter()->resolve(
            [],
            $rows,
            $this->exampleSettings(),
            $this->exampleChannelMap(),
        );

        self::assertNotEmpty($resolved['errors']);
    }

    public function testChannelMapNullValueIsRejectedWithAnError(): void
    {
        $resolved = $this->createImporter()->resolve(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            ['example-legacy-channel' => null],
        );

        self::assertNotEmpty($resolved['errors']);
    }

    public function testChannelMapNumericValueIsRejectedWithAnError(): void
    {
        $resolved = $this->createImporter()->resolve(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            ['example-legacy-channel' => 12345],
        );

        self::assertNotEmpty($resolved['errors']);
    }

    public function testChannelMapEmptyStringValueIsRejectedWithAnError(): void
    {
        $resolved = $this->createImporter()->resolve(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            ['example-legacy-channel' => ''],
        );

        self::assertNotEmpty($resolved['errors']);
    }

    public function testChannelMapOversizedValueIsRejectedWithAnError(): void
    {
        $resolved = $this->createImporter()->resolve(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            ['example-legacy-channel' => str_repeat('C', 256)],
        );

        self::assertNotEmpty($resolved['errors']);
    }

    public function testInvalidChannelMapValueDoesNotSilentlyDropMailApiRow(): void
    {
        $resolved = $this->createImporter()->resolve(
            [],
            [$this->exampleMailApiRows()[0]],
            [
                'dating_channel' => 'unused-channel',
            ],
            [
                'unused-channel' => 'C0000000000',
                'example-legacy-channel' => str_repeat('C', 256),
            ],
        );

        // The one mail_api row (id 40) references
        // "example-legacy-channel", whose mapped value is oversized.
        // It must be excluded from the resolved output, but only with
        // a matching validation error -- never silently.
        self::assertSame([], $resolved['mail_api']);
        self::assertNotEmpty($resolved['errors']);
        self::assertTrue(
            array_any(
                $resolved['errors'],
                static fn (string $error): bool => str_contains(
                    $error,
                    'mail_api.json id 40 is mapped to an invalid channel ID.',
                ),
            ),
        );
    }

    public function testIsSuccessfulForDryRunWithAllTablesEmpty(): void
    {
        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            true,
        );

        self::assertTrue(LegacyDataImporter::isSuccessful($report));
    }

    public function testIsNotSuccessfulForDryRunWhenATableIsNotEmpty(): void
    {
        $this->connection->exec(
            "INSERT INTO dating (target_date, message, channel_id) "
                . "VALUES ('0101', 'Existing.', 'C0000000000')",
        );

        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            true,
        );

        self::assertTrue($report['valid']);
        self::assertFalse(LegacyDataImporter::isSuccessful($report));
    }

    public function testIsSuccessfulForARealRunThatExecuted(): void
    {
        $report = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue(LegacyDataImporter::isSuccessful($report));
    }

    public function testIsNotSuccessfulWhenARealRunIsAbortedOrFails(): void
    {
        $this->connection->exec(
            "INSERT INTO dating (target_date, message, channel_id) "
                . "VALUES ('0101', 'Existing.', 'C0000000000')",
        );

        $abortedReport = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertFalse(LegacyDataImporter::isSuccessful($abortedReport));

        $invalidReport = $this->createImporter()->import(
            $this->exampleDatingRows(),
            $this->exampleMailApiRows(),
            $this->exampleSettings(),
            [],
            false,
        );

        self::assertFalse(LegacyDataImporter::isSuccessful($invalidReport));
    }
}

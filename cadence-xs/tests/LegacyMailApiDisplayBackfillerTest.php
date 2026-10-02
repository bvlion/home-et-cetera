<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataImporter;
use BvlionBatch5\Migration\LegacyMailApiDisplayBackfiller;
use PDO;
use PHPUnit\Framework\TestCase;

final class LegacyMailApiDisplayBackfillerTest extends TestCase
{
    private PDO $connection;
    private ConnectionFactory $connectionFactory;
    private LegacyMailApiDisplayBackfiller $backfiller;

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
        $this->connection->exec('DELETE FROM mail_api');
        $this->backfiller = new LegacyMailApiDisplayBackfiller(
            $this->connectionFactory,
            new LegacyDataImporter($this->connectionFactory),
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }

        $this->connection->exec('DELETE FROM mail_api');
    }

    /**
     * Simulates 2 rows already imported by Issue #15's ordinary
     * import, before the user_name / icon_url / prefix_format columns
     * existed: their display columns are still NULL.
     */
    private function insertPreExistingRows(): void
    {
        $this->connection->exec(
            <<<'SQL'
                INSERT INTO mail_api (
                    id, target_from, to_folder, channel_id, enable_flag
                ) VALUES
                    (40, 'example-sender-a', 'ExampleArchiveA', 'C0000000000', 1),
                    (41, 'example-sender-b', 'ExampleArchiveB', NULL, 1)
                SQL,
        );
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
     * @return array<string, string>
     */
    private function exampleChannelMap(): array
    {
        return ['example-legacy-channel' => 'C0000000000'];
    }

    public function testDryRunDoesNotWriteAndReportsPlannedUpdates(): void
    {
        $this->insertPreExistingRows();

        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            true,
        );

        self::assertTrue($report['valid']);
        self::assertTrue($report['dry_run']);
        self::assertFalse($report['executed']);
        self::assertSame(2, $report['input_count']);
        self::assertSame(2, $report['db_count']);
        self::assertSame(2, $report['matched_count']);
        self::assertSame(0, $report['mismatched_count']);
        self::assertSame(1, $report['already_set_count']);
        self::assertSame(1, $report['planned_update_count']);
        self::assertSame(0, $report['conflict_count']);
        self::assertTrue($report['can_execute']);
        self::assertSame(0, $report['updated_count']);

        $userName = $this->connection
            ->query('SELECT user_name FROM mail_api WHERE id = 40')
            ->fetchColumn();
        self::assertNull($userName);
    }

    public function testRealRunUpdatesOnlyRowsThatNeedIt(): void
    {
        $this->insertPreExistingRows();

        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertTrue($report['can_execute']);
        self::assertTrue($report['executed']);
        self::assertSame(1, $report['updated_count']);

        $row40 = $this->connection
            ->query(
                'SELECT user_name, icon_url, prefix_format '
                    . 'FROM mail_api WHERE id = 40',
            )
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(
            [
                'user_name' => 'Example Bot',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
            $row40,
        );

        $row41 = $this->connection
            ->query(
                'SELECT user_name, icon_url, prefix_format '
                    . 'FROM mail_api WHERE id = 41',
            )
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(
            ['user_name' => null, 'icon_url' => null, 'prefix_format' => null],
            $row41,
        );
    }

    public function testRerunAfterSuccessIsASafeNoOp(): void
    {
        $this->insertPreExistingRows();
        $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            false,
        );

        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertTrue($report['can_execute']);
        self::assertTrue($report['executed']);
        self::assertSame(2, $report['already_set_count']);
        self::assertSame(0, $report['planned_update_count']);
        self::assertSame(0, $report['updated_count']);
    }

    public function testMismatchedBaseIdentityAbortsWithoutWriting(): void
    {
        $this->insertPreExistingRows();
        $this->connection->exec(
            "UPDATE mail_api SET to_folder = 'UnexpectedFolder' WHERE id = 40",
        );

        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertFalse($report['can_execute']);
        self::assertFalse($report['executed']);
        self::assertSame(1, $report['mismatched_count']);
        self::assertNotNull($report['abort_reason']);

        $userName = $this->connection
            ->query('SELECT user_name FROM mail_api WHERE id = 40')
            ->fetchColumn();
        self::assertNull($userName);
    }

    public function testConflictingExistingValueAbortsEntireRunWithoutPartialUpdate(): void
    {
        $this->connection->exec(
            <<<'SQL'
                INSERT INTO mail_api (
                    id, target_from, to_folder, channel_id,
                    user_name, enable_flag
                ) VALUES
                    (
                        40, 'example-sender-a', 'ExampleArchiveA',
                        'C0000000000', 'Already Different Bot', 1
                    ),
                    (41, 'example-sender-b', 'ExampleArchiveB', NULL, NULL, 1)
                SQL,
        );

        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertFalse($report['can_execute']);
        self::assertFalse($report['executed']);
        self::assertSame(1, $report['conflict_count']);
        self::assertSame(0, $report['updated_count']);

        // Row 41 would have been a safe no-op update on its own, but
        // the whole run must still abort because row 40 conflicts.
        $row40UserName = $this->connection
            ->query('SELECT user_name FROM mail_api WHERE id = 40')
            ->fetchColumn();
        self::assertSame('Already Different Bot', $row40UserName);
    }

    public function testExtraDatabaseRowPreventsExecutionEvenWhenAllInputRowsMatch(): void
    {
        $this->insertPreExistingRows();
        $this->connection->exec(
            <<<'SQL'
                INSERT INTO mail_api (
                    id, target_from, to_folder, channel_id, enable_flag
                ) VALUES (42, 'example-sender-c', 'ExampleArchiveC', NULL, 1)
                SQL,
        );

        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            false,
        );

        self::assertTrue($report['valid']);
        self::assertSame(2, $report['input_count']);
        self::assertSame(3, $report['db_count']);
        self::assertSame(0, $report['mismatched_count']);
        self::assertSame(0, $report['conflict_count']);
        self::assertFalse($report['can_execute']);
        self::assertFalse($report['executed']);
        self::assertNotNull($report['abort_reason']);
        self::assertSame(0, $report['updated_count']);

        $userName = $this->connection
            ->query('SELECT user_name FROM mail_api WHERE id = 40')
            ->fetchColumn();
        self::assertNull($userName);
    }

    public function testInputRowNotFoundInDatabaseIsMismatched(): void
    {
        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            $this->exampleChannelMap(),
            true,
        );

        self::assertSame(0, $report['db_count']);
        self::assertSame(2, $report['mismatched_count']);
        self::assertFalse($report['can_execute']);
    }

    public function testInvalidChannelMapReturnsErrorsWithoutQueryingDatabase(): void
    {
        $this->insertPreExistingRows();

        $report = $this->backfiller->run(
            $this->exampleMailApiRows(),
            [],
            true,
        );

        self::assertFalse($report['valid']);
        self::assertNotEmpty($report['errors']);
        self::assertSame(0, $report['db_count']);
    }
}

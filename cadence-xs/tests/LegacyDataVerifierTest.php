<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataImporter;
use BvlionBatch5\Migration\LegacyDataVerifier;
use PDO;
use PHPUnit\Framework\TestCase;

final class LegacyDataVerifierTest extends TestCase
{
    private PDO $connection;
    private ConnectionFactory $connectionFactory;
    private LegacyDataImporter $importer;
    private LegacyDataVerifier $verifier;

    /**
     * @var list<array{id: int, target_date: string, message: string}>
     */
    private array $datingRows;

    /**
     * @var list<array{
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
    private array $mailApiRows;

    /** @var array{dating_channel: string} */
    private array $settings;

    /**
     * @var array<string, string>
     */
    private array $channelMap;

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
        $this->importer = new LegacyDataImporter(
            $this->connectionFactory,
            expectedDatingCount: 2,
            expectedMailApiCount: 2,
            expectedMailApiEnabledCount: 1,
            expectedMailApiDisabledCount: 1,
            expectedMailApiNullChannelCount: 1,
        );
        $this->verifier = new LegacyDataVerifier(
            $this->connectionFactory,
            $this->importer,
        );

        $this->datingRows = [
            ['id' => 1, 'target_date' => '0411', 'message' => 'Example annual.'],
            ['id' => 2, 'target_date' => '20260101', 'message' => 'Example: %s'],
        ];
        $this->mailApiRows = [
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
                'enable_flag' => 0,
            ],
        ];
        $this->settings = [
            'dating_channel' => 'example-legacy-channel',
        ];
        $this->channelMap = ['example-legacy-channel' => 'C0000000000'];
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

    private function importFixture(): void
    {
        $report = $this->importer->import(
            $this->datingRows,
            $this->mailApiRows,
            $this->settings,
            $this->channelMap,
            false,
        );
        self::assertTrue($report['executed']);
    }

    public function testMatchedDataReportsNoMismatches(): void
    {
        $this->importFixture();

        $report = $this->verifier->verify(
            $this->datingRows,
            $this->mailApiRows,
            $this->settings,
            $this->channelMap,
        );

        self::assertTrue($report['valid']);
        self::assertSame(2, $report['dating']['input_count']);
        self::assertSame(2, $report['dating']['db_count']);
        self::assertSame(2, $report['dating']['matched_count']);
        self::assertSame(0, $report['dating']['mismatched_count']);
        self::assertSame(0, $report['dating']['order_mismatch_count']);
        self::assertSame(0, $report['dating']['required_field_violation_count']);
        self::assertSame(2, $report['mail_api']['matched_count']);
        self::assertSame(0, $report['mail_api']['mismatched_count']);
        self::assertSame(0, $report['mail_api']['required_field_violation_count']);
        self::assertSame(1, $report['mail_api']['enabled_count_expected']);
        self::assertSame(1, $report['mail_api']['enabled_count_actual']);
        self::assertSame(1, $report['mail_api']['disabled_count_expected']);
        self::assertSame(1, $report['mail_api']['disabled_count_actual']);
        self::assertSame(
            1,
            $report['mail_api']['expected_null_channel_id_count'],
        );
        self::assertSame(
            1,
            $report['mail_api']['actual_null_channel_id_count'],
        );
    }

    public function testContentDifferenceIsDetectedAtItsPosition(): void
    {
        $this->importFixture();
        $this->connection->exec(
            "UPDATE dating SET message = 'Changed.' WHERE id = 1",
        );

        $report = $this->verifier->verify(
            $this->datingRows,
            $this->mailApiRows,
            $this->settings,
            $this->channelMap,
        );

        self::assertSame(1, $report['dating']['matched_count']);
        self::assertSame(2, $report['dating']['mismatched_count']);
        self::assertSame(1, $report['dating']['order_mismatch_count']);
    }

    public function testMissingDatabaseRowIsCountedAsMismatch(): void
    {
        $this->importFixture();
        $this->connection->exec('DELETE FROM dating WHERE id = 2');

        $report = $this->verifier->verify(
            $this->datingRows,
            $this->mailApiRows,
            $this->settings,
            $this->channelMap,
        );

        self::assertSame(2, $report['dating']['input_count']);
        self::assertSame(1, $report['dating']['db_count']);
        self::assertSame(1, $report['dating']['matched_count']);
        self::assertSame(1, $report['dating']['mismatched_count']);
    }

    public function testDisplayColumnDifferenceIsDetectedAsMismatch(): void
    {
        $this->importFixture();
        $this->connection->exec(
            "UPDATE mail_api SET user_name = 'Changed Bot' WHERE id = 40",
        );

        $report = $this->verifier->verify(
            $this->datingRows,
            $this->mailApiRows,
            $this->settings,
            $this->channelMap,
        );

        self::assertSame(1, $report['mail_api']['matched_count']);
        self::assertSame(2, $report['mail_api']['mismatched_count']);
    }

    public function testInvalidChannelMapReturnsErrorsWithoutQueryingDatabase(): void
    {
        $report = $this->verifier->verify(
            $this->datingRows,
            $this->mailApiRows,
            $this->settings,
            [],
        );

        self::assertFalse($report['valid']);
        self::assertNotEmpty($report['errors']);
        self::assertNull($report['dating']);
        self::assertNull($report['mail_api']);
    }
}

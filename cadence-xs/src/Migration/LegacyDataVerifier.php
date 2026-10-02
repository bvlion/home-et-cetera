<?php

declare(strict_types=1);

namespace BvlionBatch5\Migration;

use BvlionBatch5\Database\ConnectionFactory;
use PDO;

final class LegacyDataVerifier
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private LegacyDataImporter $importer,
    ) {
    }

    /**
     * Compares the legacy export files against the current database
     * content. Only counts, match/mismatch numbers, and booleans are
     * returned; no field value is ever included in the result.
     *
     * @param list<array<string, mixed>> $datingRows
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $channelMap
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     dating: array{
     *         input_count: int,
     *         db_count: int,
     *         matched_count: int,
     *         mismatched_count: int,
     *         order_mismatch_count: int,
     *         required_field_violation_count: int
     *     }|null,
     *     mail_api: array{
     *         input_count: int,
     *         db_count: int,
     *         matched_count: int,
     *         mismatched_count: int,
     *         order_mismatch_count: int,
     *         required_field_violation_count: int,
     *         enabled_count_expected: int,
     *         enabled_count_actual: int,
     *         disabled_count_expected: int,
     *         disabled_count_actual: int,
     *         expected_null_channel_id_count: int,
     *         actual_null_channel_id_count: int
     *     }|null
     * }
     */
    public function verify(
        array $datingRows,
        array $mailApiRows,
        array $settings,
        array $channelMap,
    ): array {
        $resolved = $this->importer->resolve(
            $datingRows,
            $mailApiRows,
            $settings,
            $channelMap,
        );

        if ($resolved['errors'] !== []) {
            return [
                'valid' => false,
                'errors' => $resolved['errors'],
                'dating' => null,
                'mail_api' => null,
            ];
        }

        $connection = $this->connectionFactory->create();

        $datingComparison = $this->compare(
            $resolved['dating'],
            $connection->query(
                <<<'SQL'
                    SELECT id, target_date, message, channel_id
                    FROM dating
                    ORDER BY id
                    SQL,
            )->fetchAll(PDO::FETCH_ASSOC),
            ['id', 'target_date', 'message', 'channel_id'],
        );
        $datingComparison['required_field_violation_count'] = (int) $connection->query(
            <<<'SQL'
                SELECT COUNT(*) FROM dating
                WHERE target_date IS NULL OR target_date = ''
                   OR message IS NULL OR message = ''
                   OR channel_id IS NULL OR channel_id = ''
                SQL,
        )->fetchColumn();

        $mailApiRowsFromDb = $connection->query(
            <<<'SQL'
                SELECT
                    id, target_from, to_folder, channel_id,
                    user_name, icon_url, prefix_format, enable_flag
                FROM mail_api
                ORDER BY id
                SQL,
        )->fetchAll(PDO::FETCH_ASSOC);
        $mailApiComparison = $this->compare(
            $resolved['mail_api'],
            $mailApiRowsFromDb,
            [
                'id', 'target_from', 'to_folder', 'channel_id',
                'user_name', 'icon_url', 'prefix_format', 'enable_flag',
            ],
        );
        $mailApiComparison['required_field_violation_count'] = (int) $connection->query(
            <<<'SQL'
                SELECT COUNT(*) FROM mail_api
                WHERE target_from IS NULL OR target_from = ''
                   OR to_folder IS NULL OR to_folder = ''
                   OR enable_flag IS NULL
                SQL,
        )->fetchColumn();

        $expectedEnabledCount = count(array_filter(
            $resolved['mail_api'],
            static fn (array $row): bool => $row['enable_flag'] === 1,
        ));

        return [
            'valid' => true,
            'errors' => [],
            'dating' => $datingComparison,
            'mail_api' => [
                ...$mailApiComparison,
                'enabled_count_expected' => $expectedEnabledCount,
                'enabled_count_actual' => (int) $connection->query(
                    'SELECT COUNT(*) FROM mail_api WHERE enable_flag = 1',
                )->fetchColumn(),
                'disabled_count_expected' =>
                    count($resolved['mail_api']) - $expectedEnabledCount,
                'disabled_count_actual' => (int) $connection->query(
                    'SELECT COUNT(*) FROM mail_api WHERE enable_flag = 0',
                )->fetchColumn(),
                'expected_null_channel_id_count' =>
                    $resolved['mail_api_null_channel_count'],
                'actual_null_channel_id_count' => (int) $connection->query(
                    'SELECT COUNT(*) FROM mail_api WHERE channel_id IS NULL',
                )->fetchColumn(),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $expectedRows
     * @param list<array<string, mixed>> $actualRows
     * @param list<string> $fields
     * @return array{
     *     input_count: int,
     *     db_count: int,
     *     matched_count: int,
     *     mismatched_count: int,
     *     order_mismatch_count: int
     * }
     */
    private function compare(
        array $expectedRows,
        array $actualRows,
        array $fields,
    ): array {
        $expectedHashes = array_map(
            fn (array $row): string => $this->hash($row, $fields),
            $expectedRows,
        );
        $actualHashes = array_map(
            fn (array $row): string => $this->hash($row, $fields),
            $actualRows,
        );

        $expectedCounts = array_count_values($expectedHashes);
        $actualCounts = array_count_values($actualHashes);
        $matchedCount = 0;

        foreach ($expectedCounts as $hash => $count) {
            $matchedCount += min($count, $actualCounts[$hash] ?? 0);
        }

        $mismatchedCount = (count($expectedHashes) - $matchedCount)
            + (count($actualHashes) - $matchedCount);

        $orderMismatchCount = 0;
        $positionCount = min(count($expectedHashes), count($actualHashes));

        for ($index = 0; $index < $positionCount; $index++) {
            if ($expectedHashes[$index] !== $actualHashes[$index]) {
                $orderMismatchCount++;
            }
        }

        return [
            'input_count' => count($expectedRows),
            'db_count' => count($actualRows),
            'matched_count' => $matchedCount,
            'mismatched_count' => $mismatchedCount,
            'order_mismatch_count' => $orderMismatchCount,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private function hash(array $row, array $fields): string
    {
        $normalized = [];

        foreach ($fields as $field) {
            $normalized[$field] = $row[$field] ?? null;
        }

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}

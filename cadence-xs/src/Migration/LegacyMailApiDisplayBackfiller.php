<?php

declare(strict_types=1);

namespace BvlionBatch5\Migration;

use BvlionBatch5\Database\ConnectionFactory;
use PDO;
use Throwable;

/**
 * Fills in user_name, icon_url and prefix_format for mail_api rows
 * that were already imported before those columns existed (Issue
 * #15's 44 production rows). Existing rows are never deleted or
 * re-inserted; only the three display columns are updated, and only
 * after each row has been matched against mail_api.json by its other
 * already-imported fields (id, target_from, to_folder, channel_id,
 * enable_flag), reusing LegacyDataImporter::resolveMailApiRows() so
 * legacy channel names resolve exactly the same way a normal import
 * would. A row whose display columns already hold a different,
 * non-null value is treated as a conflict and aborts the whole run
 * rather than being silently overwritten.
 */
final class LegacyMailApiDisplayBackfiller
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private LegacyDataImporter $importer,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $channelMap
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     dry_run: bool,
     *     executed: bool,
     *     input_count: int,
     *     db_count: int,
     *     matched_count: int,
     *     mismatched_count: int,
     *     already_set_count: int,
     *     planned_update_count: int,
     *     conflict_count: int,
     *     can_execute: bool,
     *     abort_reason: string|null,
     *     updated_count: int
     * }
     */
    public function run(
        array $mailApiRows,
        array $channelMap,
        bool $dryRun,
    ): array {
        $usedChannelNames = [];
        $errors = [];
        [$resolvedRows] = $this->importer->resolveMailApiRows(
            $mailApiRows,
            $channelMap,
            $usedChannelNames,
            $errors,
        );

        $report = [
            'valid' => $errors === [],
            'errors' => $errors,
            'dry_run' => $dryRun,
            'executed' => false,
            'input_count' => count($resolvedRows),
            'db_count' => 0,
            'matched_count' => 0,
            'mismatched_count' => 0,
            'already_set_count' => 0,
            'planned_update_count' => 0,
            'conflict_count' => 0,
            'can_execute' => false,
            'abort_reason' => null,
            'updated_count' => 0,
        ];

        if (!$report['valid']) {
            return $report;
        }

        $connection = $this->connectionFactory->create();
        $dbRows = $connection->query(
            <<<'SQL'
                SELECT
                    id, target_from, to_folder, channel_id,
                    user_name, icon_url, prefix_format, enable_flag
                FROM mail_api
                SQL,
        )->fetchAll(PDO::FETCH_ASSOC);
        $report['db_count'] = count($dbRows);

        $dbRowsById = [];

        foreach ($dbRows as $dbRow) {
            $dbRowsById[(int) $dbRow['id']] = $dbRow;
        }

        $updates = $this->planUpdates($resolvedRows, $dbRowsById, $report);

        $report['can_execute'] =
            $report['mismatched_count'] === 0
            && $report['conflict_count'] === 0
            && $report['input_count'] > 0
            && $report['input_count'] === $report['db_count'];

        if (!$report['can_execute']) {
            $report['abort_reason'] = sprintf(
                'Input rows did not safely match the database: '
                    . '%d mismatched, %d conflicting, '
                    . '%d input rows vs %d database rows.',
                $report['mismatched_count'],
                $report['conflict_count'],
                $report['input_count'],
                $report['db_count'],
            );

            return $report;
        }

        if ($dryRun) {
            return $report;
        }

        if ($updates === []) {
            $report['executed'] = true;

            return $report;
        }

        $connection->beginTransaction();

        try {
            $updateStatement = $connection->prepare(
                <<<'SQL'
                    UPDATE mail_api
                    SET user_name = :user_name,
                        icon_url = :icon_url,
                        prefix_format = :prefix_format
                    WHERE id = :id
                    SQL,
            );

            foreach ($updates as $id => $values) {
                $updateStatement->execute([
                    'id' => $id,
                    'user_name' => $values['user_name'],
                    'icon_url' => $values['icon_url'],
                    'prefix_format' => $values['prefix_format'],
                ]);
                $report['updated_count']++;
            }

            $connection->commit();
            $report['executed'] = true;
        } catch (Throwable) {
            $connection->rollBack();
            $report['valid'] = false;
            $report['updated_count'] = 0;
            $report['abort_reason'] =
                'Backfill transaction failed and was rolled back.';
        }

        return $report;
    }

    /**
     * @param list<array{
     *     id: int,
     *     target_from: string,
     *     to_folder: string,
     *     channel_id: string|null,
     *     user_name: string|null,
     *     icon_url: string|null,
     *     prefix_format: string|null,
     *     enable_flag: int
     * }> $resolvedRows
     * @param array<int, array<string, mixed>> $dbRowsById
     * @param array<string, mixed> $report
     * @return array<int, array{
     *     user_name: string|null,
     *     icon_url: string|null,
     *     prefix_format: string|null
     * }>
     */
    private function planUpdates(
        array $resolvedRows,
        array $dbRowsById,
        array &$report,
    ): array {
        $updates = [];

        foreach ($resolvedRows as $row) {
            $dbRow = $dbRowsById[$row['id']] ?? null;

            if (
                $dbRow === null
                || $dbRow['target_from'] !== $row['target_from']
                || $dbRow['to_folder'] !== $row['to_folder']
                || $dbRow['channel_id'] !== $row['channel_id']
                || (int) $dbRow['enable_flag'] !== $row['enable_flag']
            ) {
                $report['mismatched_count']++;

                continue;
            }

            $report['matched_count']++;

            $expected = [
                'user_name' => $row['user_name'],
                'icon_url' => $row['icon_url'],
                'prefix_format' => $row['prefix_format'],
            ];
            $current = [
                'user_name' => $dbRow['user_name'],
                'icon_url' => $dbRow['icon_url'],
                'prefix_format' => $dbRow['prefix_format'],
            ];

            if ($current === $expected) {
                $report['already_set_count']++;

                continue;
            }

            $hasConflict = false;

            foreach ($expected as $key => $value) {
                if ($current[$key] !== null && $current[$key] !== $value) {
                    $hasConflict = true;
                }
            }

            if ($hasConflict) {
                $report['conflict_count']++;

                continue;
            }

            $report['planned_update_count']++;
            $updates[$row['id']] = $expected;
        }

        return $updates;
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function isSuccessful(array $report): bool
    {
        return $report['valid']
            && $report['can_execute']
            && ($report['dry_run'] || $report['executed']);
    }
}

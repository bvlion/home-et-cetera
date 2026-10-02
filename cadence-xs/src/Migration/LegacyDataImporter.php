<?php

declare(strict_types=1);

namespace BvlionBatch5\Migration;

use BvlionBatch5\Database\ConnectionFactory;
use Throwable;

final class LegacyDataImporter
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
        private int $expectedDatingCount = 4,
        private int $expectedMailApiCount = 44,
        private int $expectedMailApiEnabledCount = 43,
        private int $expectedMailApiDisabledCount = 1,
        private int $expectedMailApiNullChannelCount = 31,
    ) {
    }

    /**
     * Validates the legacy export files against the new schema and
     * resolves legacy Slack channel names to new channel IDs. No
     * database is touched.
     *
     * @param list<array<string, mixed>> $datingRows
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $channelMap
     * @return array{
     *     errors: list<string>,
     *     warnings: list<string>,
     *     dating: list<array{
     *         id: int,
     *         target_date: string,
     *         message: string,
     *         channel_id: string|null
     *     }>,
     *     mail_api: list<array{
     *         id: int,
     *         target_from: string,
     *         to_folder: string,
     *         channel_id: string|null,
     *         user_name: string|null,
     *         icon_url: string|null,
     *         prefix_format: string|null,
     *         enable_flag: int
     *     }>,
     *     mail_api_null_channel_count: int,
     *     expected_counts: array<string, array{
     *         expected: int,
     *         actual: int,
     *         matches: bool
     *     }>
     * }
     */
    public function resolve(
        array $datingRows,
        array $mailApiRows,
        array $settings,
        array $channelMap,
    ): array {
        $errors = [];
        $usedChannelNames = [];

        $datingChannelId = $this->resolveSettings(
            $settings,
            $channelMap,
            $usedChannelNames,
            $errors,
        );

        $resolvedDating = $this->resolveDatingRows(
            $datingRows,
            $datingChannelId,
            $errors,
        );

        [$resolvedMailApi, $nullChannelCount] = $this->resolveMailApiRows(
            $mailApiRows,
            $channelMap,
            $usedChannelNames,
            $errors,
        );

        $unusedChannelCount = 0;

        foreach (array_keys($channelMap) as $channelName) {
            if (!isset($usedChannelNames[$channelName])) {
                $unusedChannelCount++;
            }
        }

        $warnings = [];

        if ($unusedChannelCount > 0) {
            $warnings[] = sprintf(
                'channel_map.json contains %d unused entr%s.',
                $unusedChannelCount,
                $unusedChannelCount === 1 ? 'y' : 'ies',
            );
        }

        usort(
            $resolvedDating,
            static fn (array $a, array $b): int => $a['id'] <=> $b['id'],
        );
        usort(
            $resolvedMailApi,
            static fn (array $a, array $b): int => $a['id'] <=> $b['id'],
        );

        $mailApiEnabledCount = count(array_filter(
            $resolvedMailApi,
            static fn (array $row): bool => $row['enable_flag'] === 1,
        ));

        $expectedCounts = [
            'dating' => $this->countCheck(
                'dating.json row count',
                count($resolvedDating),
                $this->expectedDatingCount,
                $errors,
            ),
            'mail_api' => $this->countCheck(
                'mail_api.json row count',
                count($resolvedMailApi),
                $this->expectedMailApiCount,
                $errors,
            ),
            'mail_api_enabled' => $this->countCheck(
                'mail_api.json enabled row count',
                $mailApiEnabledCount,
                $this->expectedMailApiEnabledCount,
                $errors,
            ),
            'mail_api_disabled' => $this->countCheck(
                'mail_api.json disabled row count',
                count($resolvedMailApi) - $mailApiEnabledCount,
                $this->expectedMailApiDisabledCount,
                $errors,
            ),
            'mail_api_null_channel' => $this->countCheck(
                'mail_api.json rows resolving to a null channel_id',
                $nullChannelCount,
                $this->expectedMailApiNullChannelCount,
                $errors,
            ),
        ];

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'dating' => $resolvedDating,
            'mail_api' => $resolvedMailApi,
            'mail_api_null_channel_count' => $nullChannelCount,
            'expected_counts' => $expectedCounts,
        ];
    }

    /**
     * Validates, and unless dry-run, imports the legacy data into the
     * new schema. Whether or not this is a dry run, the target tables
     * are checked for emptiness and the run is aborted without writing
     * anything if any of them already has data. All inserts happen in
     * a single transaction, which is rolled back on any failure.
     *
     * @param list<array<string, mixed>> $datingRows
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $channelMap
     * @return array{
     *     valid: bool,
     *     errors: list<string>,
     *     warnings: list<string>,
     *     expected_counts: array<string, array{
     *         expected: int,
     *         actual: int,
     *         matches: bool
     *     }>,
     *     dry_run: bool,
     *     executed: bool,
     *     existing_counts: array<string, int>|null,
     *     all_tables_empty: bool|null,
     *     can_execute: bool,
     *     abort_reason: string|null,
     *     dating_inserted: int,
     *     mail_api_inserted: int
     * }
     */
    public function import(
        array $datingRows,
        array $mailApiRows,
        array $settings,
        array $channelMap,
        bool $dryRun,
    ): array {
        $resolved = $this->resolve(
            $datingRows,
            $mailApiRows,
            $settings,
            $channelMap,
        );

        $report = [
            'valid' => $resolved['errors'] === [],
            'errors' => $resolved['errors'],
            'warnings' => $resolved['warnings'],
            'expected_counts' => $resolved['expected_counts'],
            'dry_run' => $dryRun,
            'executed' => false,
            'existing_counts' => null,
            'all_tables_empty' => null,
            'can_execute' => false,
            'abort_reason' => null,
            'dating_inserted' => 0,
            'mail_api_inserted' => 0,
        ];

        if (!$report['valid']) {
            return $report;
        }

        $connection = $this->connectionFactory->create();
        $existingCounts = [
            'dating' => (int) $connection
                ->query('SELECT COUNT(*) FROM dating')
                ->fetchColumn(),
            'mail_api' => (int) $connection
                ->query('SELECT COUNT(*) FROM mail_api')
                ->fetchColumn(),
        ];
        $report['existing_counts'] = $existingCounts;
        $allTablesEmpty = array_sum($existingCounts) === 0;
        $report['all_tables_empty'] = $allTablesEmpty;

        if (!$allTablesEmpty) {
            $nonEmptyTables = array_keys(array_filter(
                $existingCounts,
                static fn (int $count): bool => $count > 0,
            ));
            $report['abort_reason'] = sprintf(
                '%s is not empty.',
                implode(', ', $nonEmptyTables),
            );

            return $report;
        }

        $report['can_execute'] = true;

        if ($dryRun) {
            return $report;
        }

        $connection->beginTransaction();

        try {
            $datingStatement = $connection->prepare(
                <<<'SQL'
                    INSERT INTO dating (
                        id, target_date, message, channel_id
                    ) VALUES (
                        :id, :target_date, :message, :channel_id
                    )
                    SQL,
            );

            foreach ($resolved['dating'] as $row) {
                $datingStatement->execute($row);
                $report['dating_inserted']++;
            }

            $mailApiStatement = $connection->prepare(
                <<<'SQL'
                    INSERT INTO mail_api (
                        id, target_from, to_folder, channel_id,
                        user_name, icon_url, prefix_format, enable_flag
                    ) VALUES (
                        :id, :target_from, :to_folder, :channel_id,
                        :user_name, :icon_url, :prefix_format, :enable_flag
                    )
                    SQL,
            );

            foreach ($resolved['mail_api'] as $row) {
                $mailApiStatement->execute($row);
                $report['mail_api_inserted']++;
            }

            $connection->commit();
            $report['executed'] = true;
        } catch (Throwable) {
            $connection->rollBack();
            $report['valid'] = false;
            $report['dating_inserted'] = 0;
            $report['mail_api_inserted'] = 0;
            $report['abort_reason'] =
                'Import transaction failed and was rolled back.';
        }

        return $report;
    }

    /**
     * Determines whether an import() report represents a successful
     * outcome for the mode it ran in: for a dry run, the pre-checks
     * must have passed (valid input, all target tables empty); for a
     * real run, the insert must actually have executed.
     *
     * @param array<string, mixed> $report the array returned by import(),
     *     read for its 'valid', 'can_execute', 'dry_run' and 'executed' keys
     */
    public static function isSuccessful(array $report): bool
    {
        return $report['valid']
            && $report['can_execute']
            && ($report['dry_run'] || $report['executed']);
    }

    /**
     * @param list<string> $errors
     * @return array{expected: int, actual: int, matches: bool}
     */
    private function countCheck(
        string $label,
        int $actual,
        int $expected,
        array &$errors,
    ): array {
        $matches = $actual === $expected;

        if (!$matches) {
            $errors[] = sprintf(
                '%s: expected %d but found %d.',
                $label,
                $expected,
                $actual,
            );
        }

        return [
            'expected' => $expected,
            'actual' => $actual,
            'matches' => $matches,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $channelMap
     * @param array<string, true> $usedChannelNames
     * @param list<string> $errors
     * @return string|null
     */
    private function resolveSettings(
        array $settings,
        array $channelMap,
        array &$usedChannelNames,
        array &$errors,
    ): ?string {
        $datingChannelName = $settings['dating_channel'] ?? null;

        if (!is_string($datingChannelName) || $datingChannelName === '') {
            $errors[] = 'migration-settings.json: dating_channel must be '
                . 'a non-empty string.';
            $datingChannelName = null;
        }

        $datingChannelId = $this->resolveChannelId(
            $datingChannelName,
            $channelMap,
            $usedChannelNames,
            $errors,
            'migration-settings.json: dating_channel is not mapped to '
                . 'a channel ID.',
            'migration-settings.json: dating_channel is mapped to an '
                . 'invalid channel ID.',
        );
        return $datingChannelId;
    }

    /**
     * Resolves a legacy channel name to a new channel ID via
     * $channelMap. Every code path that drops a row from the
     * resolved output because a channel name could not be resolved
     * also appends a matching entry to $errors, so a row is never
     * silently excluded.
     *
     * @param array<string, mixed> $channelMap
     * @param array<string, true> $usedChannelNames
     * @param list<string> $errors
     */
    private function resolveChannelId(
        ?string $channelName,
        array $channelMap,
        array &$usedChannelNames,
        array &$errors,
        string $unmappedErrorMessage,
        string $invalidValueErrorMessage,
    ): ?string {
        if ($channelName === null) {
            return null;
        }

        $usedChannelNames[$channelName] = true;

        if (!array_key_exists($channelName, $channelMap)) {
            $errors[] = $unmappedErrorMessage;

            return null;
        }

        $channelId = $channelMap[$channelName];

        if (
            !is_string($channelId)
            || $channelId === ''
            || strlen($channelId) > 255
        ) {
            $errors[] = $invalidValueErrorMessage;

            return null;
        }

        return $channelId;
    }

    /**
     * @param list<array<string, mixed>> $datingRows
     * @param list<string> $errors
     * @return list<array{
     *     id: int,
     *     target_date: string,
     *     message: string,
     *     channel_id: string|null
     * }>
     */
    private function resolveDatingRows(
        array $datingRows,
        ?string $channelId,
        array &$errors,
    ): array {
        $resolved = [];
        $seenIds = [];

        foreach ($datingRows as $index => $row) {
            $id = $row['id'] ?? null;
            $targetDate = $row['target_date'] ?? null;
            $message = $row['message'] ?? null;

            if (!is_int($id) || $id <= 0) {
                $errors[] = sprintf(
                    'dating.json[%d]: id must be a positive integer.',
                    $index,
                );

                continue;
            }

            if (isset($seenIds[$id])) {
                $errors[] = sprintf(
                    'dating.json: id %d is duplicated.',
                    $id,
                );

                continue;
            }

            $seenIds[$id] = true;

            if (
                !is_string($targetDate)
                || $targetDate === ''
                || strlen($targetDate) > 8
            ) {
                $errors[] = sprintf(
                    'dating.json id %d: target_date must be a non-empty '
                        . 'string of at most 8 characters.',
                    $id,
                );

                continue;
            }

            if (!is_string($message) || $message === '') {
                $errors[] = sprintf(
                    'dating.json id %d: message must be a non-empty '
                        . 'string.',
                    $id,
                );

                continue;
            }

            $resolved[] = [
                'id' => $id,
                'target_date' => $targetDate,
                'message' => $message,
                'channel_id' => $channelId,
            ];
        }

        return $resolved;
    }

    /**
     * @param list<array<string, mixed>> $mailApiRows
     * @param array<string, mixed> $channelMap
     * @param array<string, true> $usedChannelNames
     * @param list<string> $errors
     * @return array{
     *     0: list<array{
     *         id: int,
     *         target_from: string,
     *         to_folder: string,
     *         channel_id: string|null,
     *         user_name: string|null,
     *         icon_url: string|null,
     *         prefix_format: string|null,
     *         enable_flag: int
     *     }>,
     *     1: int
     * }
     */
    public function resolveMailApiRows(
        array $mailApiRows,
        array $channelMap,
        array &$usedChannelNames,
        array &$errors,
    ): array {
        $resolved = [];
        $seenIds = [];
        $nullChannelCount = 0;

        foreach ($mailApiRows as $index => $row) {
            $id = $row['id'] ?? null;
            $targetFrom = $row['target_from'] ?? null;
            $toFolder = $row['to_folder'] ?? null;
            $channel = $row['channel'] ?? null;
            $userName = $row['user_name'] ?? null;
            $iconUrl = $row['icon_url'] ?? null;
            $prefixFormat = $row['prefix_format'] ?? null;
            $enableFlag = $row['enable_flag'] ?? null;

            if (!is_int($id) || $id <= 0) {
                $errors[] = sprintf(
                    'mail_api.json[%d]: id must be a positive integer.',
                    $index,
                );

                continue;
            }

            if (isset($seenIds[$id])) {
                $errors[] = sprintf(
                    'mail_api.json: id %d is duplicated.',
                    $id,
                );

                continue;
            }

            $seenIds[$id] = true;

            if (
                !is_string($targetFrom)
                || $targetFrom === ''
                || strlen($targetFrom) > 255
            ) {
                $errors[] = sprintf(
                    'mail_api.json id %d: target_from must be a '
                        . 'non-empty string of at most 255 characters.',
                    $id,
                );

                continue;
            }

            if (
                !is_string($toFolder)
                || $toFolder === ''
                || strlen($toFolder) > 255
            ) {
                $errors[] = sprintf(
                    'mail_api.json id %d: to_folder must be a non-empty '
                        . 'string of at most 255 characters.',
                    $id,
                );

                continue;
            }

            if ($enableFlag !== 0 && $enableFlag !== 1) {
                $errors[] = sprintf(
                    'mail_api.json id %d: enable_flag must be 0 or 1.',
                    $id,
                );

                continue;
            }

            if (
                ($channel !== null && !is_string($channel))
                || ($userName !== null && !is_string($userName))
                || ($iconUrl !== null && !is_string($iconUrl))
                || ($prefixFormat !== null && !is_string($prefixFormat))
            ) {
                $errors[] = sprintf(
                    'mail_api.json id %d: channel, user_name, icon_url '
                        . 'and prefix_format must each be a string or '
                        . 'null.',
                    $id,
                );

                continue;
            }

            if ($userName !== null && strlen($userName) > 255) {
                $errors[] = sprintf(
                    'mail_api.json id %d: user_name must be at most 255 '
                        . 'characters.',
                    $id,
                );

                continue;
            }

            if ($iconUrl !== null && strlen($iconUrl) > 512) {
                $errors[] = sprintf(
                    'mail_api.json id %d: icon_url must be at most 512 '
                        . 'characters.',
                    $id,
                );

                continue;
            }

            if ($prefixFormat !== null && strlen($prefixFormat) > 255) {
                $errors[] = sprintf(
                    'mail_api.json id %d: prefix_format must be at most '
                        . '255 characters.',
                    $id,
                );

                continue;
            }

            $channelId = null;

            if (
                $channel !== null
                && $userName !== null
                && $iconUrl !== null
                && $prefixFormat !== null
            ) {
                $channelId = $this->resolveChannelId(
                    $channel,
                    $channelMap,
                    $usedChannelNames,
                    $errors,
                    sprintf(
                        'channel_map.json: mail_api.json id %d '
                            . 'references an unmapped channel name.',
                        $id,
                    ),
                    sprintf(
                        'channel_map.json: mail_api.json id %d is '
                            . 'mapped to an invalid channel ID.',
                        $id,
                    ),
                );

                if ($channelId === null) {
                    continue;
                }
            } else {
                $nullChannelCount++;
            }

            $resolved[] = [
                'id' => $id,
                'target_from' => $targetFrom,
                'to_folder' => $toFolder,
                'channel_id' => $channelId,
                'user_name' => $userName,
                'icon_url' => $iconUrl,
                'prefix_format' => $prefixFormat,
                'enable_flag' => $enableFlag,
            ];
        }

        return [$resolved, $nullChannelCount];
    }
}

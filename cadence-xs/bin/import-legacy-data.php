<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataFileDecoder;
use BvlionBatch5\Migration\LegacyDataImporter;

require_once __DIR__ . '/../vendor/autoload.php';

$readFile = static function (string $path): string {
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Input file could not be read.');
    }

    return $contents;
};

$printReport = static function (array $report): void {
    $lines = [
        sprintf('valid: %s', $report['valid'] ? 'true' : 'false'),
        sprintf('dry_run: %s', $report['dry_run'] ? 'true' : 'false'),
        sprintf('executed: %s', $report['executed'] ? 'true' : 'false'),
    ];

    foreach ($report['expected_counts'] as $label => $countReport) {
        $lines[] = sprintf(
            'expected_counts.%s: expected=%d actual=%d matches=%s',
            $label,
            $countReport['expected'],
            $countReport['actual'],
            $countReport['matches'] ? 'true' : 'false',
        );
    }

    if ($report['existing_counts'] !== null) {
        foreach ($report['existing_counts'] as $table => $count) {
            $lines[] = sprintf('existing_counts.%s: %d', $table, $count);
        }
    }

    $lines[] = sprintf(
        'all_tables_empty: %s',
        $report['all_tables_empty'] === null
            ? 'unknown'
            : ($report['all_tables_empty'] ? 'true' : 'false'),
    );
    $lines[] = sprintf(
        'can_execute: %s',
        $report['can_execute'] ? 'true' : 'false',
    );
    $lines[] = sprintf('dating_inserted: %d', $report['dating_inserted']);
    $lines[] = sprintf('mail_api_inserted: %d', $report['mail_api_inserted']);

    if ($report['abort_reason'] !== null) {
        $lines[] = sprintf('abort_reason: %s', $report['abort_reason']);
    }

    foreach ($report['warnings'] as $warning) {
        $lines[] = sprintf('warning: %s', $warning);
    }

    foreach ($report['errors'] as $error) {
        $lines[] = sprintf('error: %s', $error);
    }

    fwrite(STDOUT, implode("\n", $lines) . "\n");
};

$options = getopt('', [
    'dating:',
    'mail-api:',
    'settings:',
    'channel-map:',
    'dry-run',
]);
$datingPath = $options['dating'] ?? null;
$mailApiPath = $options['mail-api'] ?? null;
$settingsPath = $options['settings'] ?? null;
$channelMapPath = $options['channel-map'] ?? null;
$dryRun = array_key_exists('dry-run', $options);

if (
    !is_string($datingPath)
    || !is_string($mailApiPath)
    || !is_string($settingsPath)
    || !is_string($channelMapPath)
) {
    fwrite(
        STDERR,
        "Usage: import-legacy-data.php --dating=<path> "
            . "--mail-api=<path> --settings=<path> "
            . "--channel-map=<path> [--dry-run]\n",
    );
    exit(1);
}

$decoder = new LegacyDataFileDecoder();

try {
    $datingDecoded = $decoder->decodeRowListFile(
        'dating.json',
        $readFile($datingPath),
    );
    $mailApiDecoded = $decoder->decodeRowListFile(
        'mail_api.json',
        $readFile($mailApiPath),
    );
    $settingsDecoded = $decoder->decodeObjectFile(
        'migration-settings.json',
        $readFile($settingsPath),
    );
    $channelMapDecoded = $decoder->decodeObjectFile(
        'channel_map.json',
        $readFile($channelMapPath),
    );
} catch (Throwable) {
    fwrite(STDERR, "Input files could not be read.\n");
    exit(1);
}

$decodeErrors = [
    ...$datingDecoded['errors'],
    ...$mailApiDecoded['errors'],
    ...$settingsDecoded['errors'],
    ...$channelMapDecoded['errors'],
];

if ($decodeErrors !== []) {
    foreach ($decodeErrors as $error) {
        fwrite(STDOUT, sprintf("error: %s\n", $error));
    }

    exit(1);
}

$configuration = require __DIR__ . '/../bootstrap/config.php';
$databaseConfiguration = $configuration['database'];
$importer = new LegacyDataImporter(new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
));

$report = $importer->import(
    $datingDecoded['rows'],
    $mailApiDecoded['rows'],
    $settingsDecoded['data'],
    $channelMapDecoded['data'],
    $dryRun,
);
$printReport($report);

exit(LegacyDataImporter::isSuccessful($report) ? 0 : 1);

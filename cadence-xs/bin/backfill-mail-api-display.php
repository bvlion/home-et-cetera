<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDataFileDecoder;
use BvlionBatch5\Migration\LegacyDataImporter;
use BvlionBatch5\Migration\LegacyMailApiDisplayBackfiller;

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
        sprintf('input_count: %d', $report['input_count']),
        sprintf('db_count: %d', $report['db_count']),
        sprintf('matched_count: %d', $report['matched_count']),
        sprintf('mismatched_count: %d', $report['mismatched_count']),
        sprintf('already_set_count: %d', $report['already_set_count']),
        sprintf(
            'planned_update_count: %d',
            $report['planned_update_count'],
        ),
        sprintf('conflict_count: %d', $report['conflict_count']),
        sprintf('can_execute: %s', $report['can_execute'] ? 'true' : 'false'),
        sprintf('updated_count: %d', $report['updated_count']),
    ];

    if ($report['abort_reason'] !== null) {
        $lines[] = sprintf('abort_reason: %s', $report['abort_reason']);
    }

    foreach ($report['errors'] as $error) {
        $lines[] = sprintf('error: %s', $error);
    }

    fwrite(STDOUT, implode("\n", $lines) . "\n");
};

$options = getopt('', ['mail-api:', 'channel-map:', 'dry-run']);
$mailApiPath = $options['mail-api'] ?? null;
$channelMapPath = $options['channel-map'] ?? null;
$dryRun = array_key_exists('dry-run', $options);

if (!is_string($mailApiPath) || !is_string($channelMapPath)) {
    fwrite(
        STDERR,
        "Usage: backfill-mail-api-display.php --mail-api=<path> "
            . "--channel-map=<path> [--dry-run]\n",
    );
    exit(1);
}

$decoder = new LegacyDataFileDecoder();

try {
    $mailApiDecoded = $decoder->decodeRowListFile(
        'mail_api.json',
        $readFile($mailApiPath),
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
    ...$mailApiDecoded['errors'],
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
$connectionFactory = new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
);
$backfiller = new LegacyMailApiDisplayBackfiller(
    $connectionFactory,
    new LegacyDataImporter($connectionFactory),
);

$report = $backfiller->run(
    $mailApiDecoded['rows'],
    $channelMapDecoded['data'],
    $dryRun,
);
$printReport($report);

exit(LegacyMailApiDisplayBackfiller::isSuccessful($report) ? 0 : 1);

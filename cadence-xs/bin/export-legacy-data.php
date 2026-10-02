<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Migration\LegacyDatabaseEnvironmentLoader;
use BvlionBatch5\Migration\LegacyJsonFileWriter;

require_once __DIR__ . '/../vendor/autoload.php';

const LEGACY_EXPORT_QUERIES = [
    'dating' => <<<'SQL'
        SELECT
            pk AS id,
            target_date,
            message
        FROM dating
        ORDER BY pk
        SQL,
    'mail_api' => <<<'SQL'
        SELECT
            pk AS id,
            target_from,
            to_folder,
            channel,
            user_name,
            icon_url,
            prefix_format,
            enable_flag
        FROM mail_api
        ORDER BY pk
        SQL,
];

$options = getopt('', ['env-file:', 'table:', 'output:']);
$envFile = $options['env-file'] ?? null;
$table = $options['table'] ?? null;
$outputPath = $options['output'] ?? null;

if (
    !is_string($envFile)
    || !is_string($table)
    || !is_string($outputPath)
    || !array_key_exists($table, LEGACY_EXPORT_QUERIES)
) {
    fwrite(
        STDERR,
        "Usage: export-legacy-data.php --env-file=<path> "
            . "--table=<dating|mail_api> --output=<path>\n",
    );
    exit(1);
}

try {
    $legacyDatabase = (new LegacyDatabaseEnvironmentLoader())->load($envFile);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

try {
    $connection = (new ConnectionFactory(
        $legacyDatabase['host'],
        $legacyDatabase['port'],
        $legacyDatabase['name'],
        $legacyDatabase['user'],
        $legacyDatabase['password'],
    ))->create();

    $rows = $connection
        ->query(LEGACY_EXPORT_QUERIES[$table])
        ->fetchAll(PDO::FETCH_ASSOC);
    $rowCount = (new LegacyJsonFileWriter())->write($rows, $outputPath);
} catch (Throwable) {
    fwrite(STDERR, sprintf("Legacy export failed: %s.\n", $table));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Exported %d row(s) for %s to %s\n",
    $rowCount,
    $table,
    $outputPath,
));

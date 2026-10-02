<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$configuration = require __DIR__ . '/../bootstrap/config.php';
$databaseConfiguration = $configuration['database'];
$connection = (new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
))->create();

$migrationMetadataSql = file_get_contents(
    __DIR__ . '/../database/schema_migrations.sql',
);

if ($migrationMetadataSql === false) {
    throw new RuntimeException('Migration metadata could not be read.');
}

$connection->exec($migrationMetadataSql);

$migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql');

if ($migrationFiles === false) {
    throw new RuntimeException('Migration files could not be read.');
}

$migrationStatusStatement = $connection->prepare(
    'SELECT COUNT(*) FROM schema_migrations WHERE version = :version',
);
$migrationRecordStatement = $connection->prepare(
    'INSERT INTO schema_migrations (version) VALUES (:version)',
);
$appliedMigrationCount = 0;

foreach ($migrationFiles as $migrationFile) {
    $migrationVersion = basename($migrationFile);
    $migrationStatusStatement->execute(['version' => $migrationVersion]);

    if ((int) $migrationStatusStatement->fetchColumn() > 0) {
        continue;
    }

    $migrationSql = file_get_contents($migrationFile);

    if ($migrationSql === false) {
        throw new RuntimeException(
            sprintf('Migration could not be read: %s', $migrationVersion),
        );
    }

    $connection->exec($migrationSql);
    $migrationRecordStatement->execute(['version' => $migrationVersion]);
    $appliedMigrationCount++;
    fwrite(STDOUT, sprintf("Applied migration: %s\n", $migrationVersion));
}

if ($appliedMigrationCount === 0) {
    fwrite(STDOUT, "No pending migrations.\n");
}

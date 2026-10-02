<?php

declare(strict_types=1);

namespace BvlionBatch5\Migration;

use Dotenv\Dotenv;
use RuntimeException;
use Throwable;

final class LegacyDatabaseEnvironmentLoader
{
    private const REQUIRED_KEYS = [
        'LEGACY_DB_HOST',
        'LEGACY_DB_PORT',
        'LEGACY_DB_NAME',
        'LEGACY_DB_USER',
        'LEGACY_DB_PASSWORD',
    ];

    /**
     * Reads legacy database connection values from exactly the given
     * file. Values are parsed locally with Dotenv::parse(), which
     * never mutates $_ENV/$_SERVER/putenv(), so there is no fallback
     * to the process environment: a variable that only exists as an
     * OS environment variable is never picked up here.
     *
     * @return array{
     *     host: string,
     *     port: string,
     *     name: string,
     *     user: string,
     *     password: string
     * }
     */
    public function load(string $envFilePath): array
    {
        if (!is_file($envFilePath) || !is_readable($envFilePath)) {
            throw new RuntimeException(
                'Legacy database environment file could not be read.',
            );
        }

        $contents = file_get_contents($envFilePath);

        if ($contents === false) {
            throw new RuntimeException(
                'Legacy database environment file could not be read.',
            );
        }

        try {
            $values = Dotenv::parse($contents);
        } catch (Throwable) {
            throw new RuntimeException(
                'Legacy database environment file could not be parsed.',
            );
        }

        $missingKeys = [];

        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($values[$key]) || $values[$key] === '') {
                $missingKeys[] = $key;
            }
        }

        if ($missingKeys !== []) {
            throw new RuntimeException(sprintf(
                'Legacy database environment file is missing: %s.',
                implode(', ', $missingKeys),
            ));
        }

        return [
            'host' => $values['LEGACY_DB_HOST'],
            'port' => $values['LEGACY_DB_PORT'],
            'name' => $values['LEGACY_DB_NAME'],
            'user' => $values['LEGACY_DB_USER'],
            'password' => $values['LEGACY_DB_PASSWORD'],
        ];
    }
}

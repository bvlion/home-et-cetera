<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Migration\LegacyDatabaseEnvironmentLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyDatabaseEnvironmentLoaderTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir() . '/'
            . uniqid('legacy-db-env-loader-test-', true);
        mkdir($this->workingDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workingDirectory . '/*') as $file) {
            unlink($file);
        }

        rmdir($this->workingDirectory);
    }

    public function testLoadsAllValuesFromTheGivenFile(): void
    {
        $envFilePath = $this->workingDirectory . '/legacy-db.env';
        file_put_contents(
            $envFilePath,
            "LEGACY_DB_HOST=legacy-database.example.test\n"
                . "LEGACY_DB_PORT=3306\n"
                . "LEGACY_DB_NAME=example_legacy_database\n"
                . "LEGACY_DB_USER=example_legacy_user\n"
                . "LEGACY_DB_PASSWORD=example_legacy_password\n",
        );

        $values = (new LegacyDatabaseEnvironmentLoader())->load($envFilePath);

        self::assertSame(
            [
                'host' => 'legacy-database.example.test',
                'port' => '3306',
                'name' => 'example_legacy_database',
                'user' => 'example_legacy_user',
                'password' => 'example_legacy_password',
            ],
            $values,
        );
    }

    public function testThrowsWhenTheFileDoesNotExist(): void
    {
        $this->expectException(RuntimeException::class);

        (new LegacyDatabaseEnvironmentLoader())->load(
            $this->workingDirectory . '/does-not-exist.env',
        );
    }

    public function testDoesNotFallBackToProcessEnvironmentWhenFileIsMissing(): void
    {
        // Even though the process environment already has every
        // required LEGACY_DB_* variable set, a missing --env-file
        // must still fail: this loader must never read from
        // $_ENV/$_SERVER/getenv() as a fallback.
        $keys = [
            'LEGACY_DB_HOST',
            'LEGACY_DB_PORT',
            'LEGACY_DB_NAME',
            'LEGACY_DB_USER',
            'LEGACY_DB_PASSWORD',
        ];

        foreach ($keys as $key) {
            putenv($key . '=process-environment-value');
            $_ENV[$key] = 'process-environment-value';
            $_SERVER[$key] = 'process-environment-value';
        }

        try {
            $this->expectException(RuntimeException::class);

            (new LegacyDatabaseEnvironmentLoader())->load(
                $this->workingDirectory . '/does-not-exist.env',
            );
        } finally {
            foreach ($keys as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    public function testThrowsWhenARequiredKeyIsMissingFromTheFile(): void
    {
        $envFilePath = $this->workingDirectory . '/legacy-db.env';
        file_put_contents(
            $envFilePath,
            "LEGACY_DB_HOST=legacy-database.example.test\n"
                . "LEGACY_DB_PORT=3306\n"
                . "LEGACY_DB_NAME=example_legacy_database\n"
                . "LEGACY_DB_USER=example_legacy_user\n",
        );

        try {
            (new LegacyDatabaseEnvironmentLoader())->load($envFilePath);
            self::fail('An exception was expected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'LEGACY_DB_PASSWORD',
                $exception->getMessage(),
            );
        }
    }

    public function testThrowsWhenGivenADirectoryInsteadOfAFile(): void
    {
        $this->expectException(RuntimeException::class);

        (new LegacyDatabaseEnvironmentLoader())->load($this->workingDirectory);
    }
}

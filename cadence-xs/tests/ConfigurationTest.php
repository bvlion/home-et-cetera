<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use Dotenv\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testConfigurationIsLoadedFromEnvironmentVariables(): void
    {
        $configuration = require __DIR__ . '/../bootstrap/config.php';

        self::assertSame(
            [
                'app' => [
                    'timezone' => 'Asia/Tokyo',
                ],
                'database' => [
                    'host' => 'database',
                    'port' => '3306',
                    'name' => 'example_database',
                    'user' => 'example_database_user',
                    'password' => 'example_database_password',
                ],
                'slack' => [
                    'bot_token' => 'example_slack_bot_token',
                ],
                'imap' => [
                    'host' => 'imap.example.test',
                    'port' => '993',
                    'username' => 'user@example.test',
                    'password' => 'example_imap_password',
                ],
                'bearer_token' => [
                    'scheduler' => 'example_scheduler_bearer_token',
                ],
            ],
            $configuration,
        );
    }

    public function testEachRequiredEnvironmentVariableIsValidated(): void
    {
        $requiredEnvironmentVariables = [
            'APP_TIMEZONE',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'SLACK_BOT_TOKEN',
            'IMAP_HOST',
            'IMAP_PORT',
            'IMAP_USERNAME',
            'IMAP_PASSWORD',
            'SCHEDULER_BEARER_TOKEN',
        ];

        foreach ($requiredEnvironmentVariables as $environmentVariable) {
            $environmentVariableValue = $_ENV[$environmentVariable];
            $serverEnvironmentVariableValue = $_SERVER[$environmentVariable]
                ?? null;
            $_ENV[$environmentVariable] = '';
            $_SERVER[$environmentVariable] = '';

            try {
                require __DIR__ . '/../bootstrap/config.php';
                self::fail(
                    sprintf(
                        '%sの欠落が検出されませんでした。',
                        $environmentVariable,
                    ),
                );
            } catch (ValidationException $exception) {
                self::assertStringContainsString(
                    $environmentVariable,
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    $environmentVariableValue,
                    $exception->getMessage(),
                );
            } finally {
                $_ENV[$environmentVariable] = $environmentVariableValue;

                if ($serverEnvironmentVariableValue === null) {
                    unset($_SERVER[$environmentVariable]);
                } else {
                    $_SERVER[$environmentVariable] =
                        $serverEnvironmentVariableValue;
                }
            }
        }
    }
}

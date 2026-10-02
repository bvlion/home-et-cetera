<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

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

$dotenv->required($requiredEnvironmentVariables)->notEmpty();

return [
    'app' => [
        'timezone' => $_ENV['APP_TIMEZONE'] ?? $_SERVER['APP_TIMEZONE'],
    ],
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'],
        'port' => $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'],
        'name' => $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'],
        'user' => $_ENV['DB_USER'] ?? $_SERVER['DB_USER'],
        'password' => $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'],
    ],
    'slack' => [
        'bot_token' => $_ENV['SLACK_BOT_TOKEN'] ?? $_SERVER['SLACK_BOT_TOKEN'],
    ],
    'imap' => [
        'host' => $_ENV['IMAP_HOST'] ?? $_SERVER['IMAP_HOST'],
        'port' => $_ENV['IMAP_PORT'] ?? $_SERVER['IMAP_PORT'],
        'username' => $_ENV['IMAP_USERNAME'] ?? $_SERVER['IMAP_USERNAME'],
        'password' => $_ENV['IMAP_PASSWORD'] ?? $_SERVER['IMAP_PASSWORD'],
    ],
    'bearer_token' => [
        'scheduler' => $_ENV['SCHEDULER_BEARER_TOKEN']
            ?? $_SERVER['SCHEDULER_BEARER_TOKEN'],
    ],
];

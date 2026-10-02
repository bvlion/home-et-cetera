<?php

declare(strict_types=1);

use BvlionBatch5\Mail\ImapConnectivityCheckCommand;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));

try {
    $dotenv->safeLoad();
} catch (Throwable) {
    fwrite(STDERR, "Environment configuration could not be loaded.\n");
    exit(1);
}

$host = $_ENV['IMAP_HOST'] ?? $_SERVER['IMAP_HOST'] ?? null;
$port = $_ENV['IMAP_PORT'] ?? $_SERVER['IMAP_PORT'] ?? null;
$username = $_ENV['IMAP_USERNAME'] ?? $_SERVER['IMAP_USERNAME'] ?? null;
$password = $_ENV['IMAP_PASSWORD'] ?? $_SERVER['IMAP_PASSWORD'] ?? null;

try {
    (new ImapConnectivityCheckCommand())->run(
        $host,
        $port,
        $username,
        $password,
    );
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "IMAP connectivity check failed.\n");
    exit(1);
}

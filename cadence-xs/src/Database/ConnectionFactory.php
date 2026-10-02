<?php

declare(strict_types=1);

namespace BvlionBatch5\Database;

use PDO;
use PDOException;
use Pdo\Mysql;
use RuntimeException;

final class ConnectionFactory
{
    public function __construct(
        private string $host,
        private string $port,
        private string $databaseName,
        private string $username,
        private string $password,
    ) {
    }

    public function create(): PDO
    {
        $dataSourceName = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->host,
            $this->port,
            $this->databaseName,
        );

        try {
            return new PDO(
                $dataSourceName,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    Mysql::ATTR_INIT_COMMAND =>
                        "SET time_zone = '+09:00'",
                ],
            );
        } catch (PDOException) {
            throw new RuntimeException('Database connection failed.');
        }
    }
}

<?php

declare(strict_types=1);

namespace BvlionBatch5\Dating;

use BvlionBatch5\Database\ConnectionFactory;
use PDO;

class DatingRepository
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @return list<array{
     *     target_date: string,
     *     message: string,
     *     channel_id: string
     * }>
     */
    public function findAll(): array
    {
        $statement = $this->connectionFactory->create()->query(
            <<<'SQL'
                SELECT
                    target_date,
                    message,
                    channel_id
                FROM dating
                ORDER BY id
                SQL,
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

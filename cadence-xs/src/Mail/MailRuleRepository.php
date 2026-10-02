<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use BvlionBatch5\Database\ConnectionFactory;
use PDO;

class MailRuleRepository
{
    public function __construct(
        private ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @return list<string>
     */
    public function findEnabledTargets(): array
    {
        $statement = $this->connectionFactory->create()->query(
            <<<'SQL'
                SELECT
                    target_from
                FROM mail_api
                WHERE enable_flag = 1
                ORDER BY id
                SQL,
        );

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return list<array{
     *     target_from: string,
     *     to_folder: string,
     *     channel_id: string|null,
     *     user_name: string|null,
     *     icon_url: string|null,
     *     prefix_format: string|null
     * }>
     */
    public function findEnabledRules(): array
    {
        $statement = $this->connectionFactory->create()->query(
            <<<'SQL'
                SELECT
                    target_from,
                    to_folder,
                    channel_id,
                    user_name,
                    icon_url,
                    prefix_format
                FROM mail_api
                WHERE enable_flag = 1
                ORDER BY id
                SQL,
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}

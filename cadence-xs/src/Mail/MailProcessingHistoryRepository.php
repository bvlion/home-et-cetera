<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use BvlionBatch5\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

class MailProcessingHistoryRepository
{
    private const COMPLETED_RETENTION_DAYS = 90;

    public function __construct(
        private ConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @return array{
     *     slack_posted: bool,
     *     completed: bool,
     *     slack_timestamp: string|null
     * }|null
     */
    public function find(
        string $mailboxIdentifier,
        int $uidValidity,
        int $uid,
    ): ?array {
        $statement = $this->connectionFactory->create()->prepare(
            <<<'SQL'
                SELECT
                    slack_posted,
                    completed,
                    slack_timestamp
                FROM mail_processing_history
                WHERE mailbox_identifier = :mailbox_identifier
                  AND uid_validity = :uid_validity
                  AND uid = :uid
                SQL,
        );
        $statement->execute([
            'mailbox_identifier' => $mailboxIdentifier,
            'uid_validity' => $uidValidity,
            'uid' => $uid,
        ]);
        $history = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($history)) {
            return null;
        }

        return [
            'slack_posted' => (bool) $history['slack_posted'],
            'completed' => (bool) $history['completed'],
            'slack_timestamp' => is_string($history['slack_timestamp'])
                ? $history['slack_timestamp']
                : null,
        ];
    }

    public function recordSlackPosted(
        string $mailboxIdentifier,
        int $uidValidity,
        int $uid,
        string $slackTimestamp,
    ): void {
        $statement = $this->connectionFactory->create()->prepare(
            <<<'SQL'
                INSERT INTO mail_processing_history (
                    mailbox_identifier,
                    uid_validity,
                    uid,
                    slack_posted,
                    slack_timestamp
                ) VALUES (
                    :mailbox_identifier,
                    :uid_validity,
                    :uid,
                    1,
                    :slack_timestamp
                )
                ON DUPLICATE KEY UPDATE
                    slack_posted = 1,
                    slack_timestamp = COALESCE(
                        slack_timestamp,
                        VALUES(slack_timestamp)
                    ),
                    updated_at = CURRENT_TIMESTAMP(6)
                SQL,
        );
        $statement->execute([
            'mailbox_identifier' => $mailboxIdentifier,
            'uid_validity' => $uidValidity,
            'uid' => $uid,
            'slack_timestamp' => $slackTimestamp,
        ]);
    }

    public function markCompleted(
        string $mailboxIdentifier,
        int $uidValidity,
        int $uid,
    ): void {
        $statement = $this->connectionFactory->create()->prepare(
            <<<'SQL'
                INSERT INTO mail_processing_history (
                    mailbox_identifier,
                    uid_validity,
                    uid,
                    completed,
                    completed_at
                ) VALUES (
                    :mailbox_identifier,
                    :uid_validity,
                    :uid,
                    1,
                    CURRENT_TIMESTAMP(6)
                )
                ON DUPLICATE KEY UPDATE
                    completed = 1,
                    completed_at = COALESCE(
                        completed_at,
                        VALUES(completed_at)
                    ),
                    updated_at = CURRENT_TIMESTAMP(6)
                SQL,
        );
        $statement->execute([
            'mailbox_identifier' => $mailboxIdentifier,
            'uid_validity' => $uidValidity,
            'uid' => $uid,
        ]);
    }

    public function deleteExpiredCompleted(
        DateTimeImmutable $currentDateTime,
    ): int {
        $completedBefore = $currentDateTime->modify(
            sprintf('-%d days', self::COMPLETED_RETENTION_DAYS),
        );
        $statement = $this->connectionFactory->create()->prepare(
            <<<'SQL'
                DELETE FROM mail_processing_history
                WHERE completed = 1
                  AND completed_at < :completed_before
                SQL,
        );
        $statement->execute([
            'completed_before' => $completedBefore->format(
                'Y-m-d H:i:s.u',
            ),
        ]);

        return $statement->rowCount();
    }
}

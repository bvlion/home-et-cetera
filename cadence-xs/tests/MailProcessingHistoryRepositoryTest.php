<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Mail\MailProcessingHistoryRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailProcessingHistoryRepositoryTest extends TestCase
{
    private PDO $connection;
    private ConnectionFactory $connectionFactory;
    private bool $shouldDropTable = false;

    protected function setUp(): void
    {
        $databaseHost = $_ENV['TEST_DB_HOST']
            ?? $_SERVER['TEST_DB_HOST']
            ?? 'database';
        $this->connectionFactory = new ConnectionFactory(
            $databaseHost,
            '3306',
            'example_database',
            'example_database_user',
            'example_database_password',
        );
        $this->connection = $this->connectionFactory->create();
        $tableStatus = $this->connection->query(
            "SHOW TABLES LIKE 'mail_processing_history'",
        );
        $this->shouldDropTable = $tableStatus->fetchColumn() === false;

        if ($this->shouldDropTable) {
            $migrationSql = file_get_contents(
                __DIR__ . '/../database/migrations/'
                . '20260803020000_create_mail_processing_history_table.sql',
            );

            if ($migrationSql === false) {
                throw new RuntimeException(
                    'Mail processing history migration could not be read.',
                );
            }

            $this->connection->exec($migrationSql);
        }

        $this->connection->exec(
            <<<'SQL'
                DELETE FROM mail_processing_history
                WHERE mailbox_identifier LIKE 'example-mailbox-%'
                SQL,
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }

        if ($this->shouldDropTable) {
            $this->connection->exec(
                'DROP TABLE mail_processing_history',
            );
        } else {
            $this->connection->exec(
                <<<'SQL'
                    DELETE FROM mail_processing_history
                    WHERE mailbox_identifier LIKE 'example-mailbox-%'
                    SQL,
            );
        }
    }

    public function testRetryStateDistinguishesSlackPostedAndCompleted(): void
    {
        $repository = new MailProcessingHistoryRepository(
            $this->connectionFactory,
        );
        $mailboxIdentifier = 'example-mailbox-retry';

        self::assertNull($repository->find(
            $mailboxIdentifier,
            123456,
            101,
        ));

        $repository->recordSlackPosted(
            $mailboxIdentifier,
            123456,
            101,
            '1234567890.123456',
        );

        self::assertSame(
            [
                'slack_posted' => true,
                'completed' => false,
                'slack_timestamp' => '1234567890.123456',
            ],
            $repository->find($mailboxIdentifier, 123456, 101),
        );

        $repository->recordSlackPosted(
            $mailboxIdentifier,
            123456,
            101,
            '1234567890.999999',
        );

        self::assertSame(
            '1234567890.123456',
            $repository->find(
                $mailboxIdentifier,
                123456,
                101,
            )['slack_timestamp'],
        );

        $repository->markCompleted(
            $mailboxIdentifier,
            123456,
            101,
        );

        self::assertSame(
            [
                'slack_posted' => true,
                'completed' => true,
                'slack_timestamp' => '1234567890.123456',
            ],
            $repository->find($mailboxIdentifier, 123456, 101),
        );
    }

    public function testUidValiditySeparatesReassignedUid(): void
    {
        $repository = new MailProcessingHistoryRepository(
            $this->connectionFactory,
        );
        $mailboxIdentifier = 'example-mailbox-uid-validity';

        $repository->recordSlackPosted(
            $mailboxIdentifier,
            123456,
            101,
            '1234567890.123456',
        );

        self::assertNull($repository->find(
            $mailboxIdentifier,
            654321,
            101,
        ));

        $repository->recordSlackPosted(
            $mailboxIdentifier,
            654321,
            101,
            '1234567890.654321',
        );

        self::assertSame(
            '1234567890.123456',
            $repository->find(
                $mailboxIdentifier,
                123456,
                101,
            )['slack_timestamp'],
        );
        self::assertSame(
            '1234567890.654321',
            $repository->find(
                $mailboxIdentifier,
                654321,
                101,
            )['slack_timestamp'],
        );
    }

    public function testOnlyExpiredCompletedHistoriesAreDeleted(): void
    {
        $repository = new MailProcessingHistoryRepository(
            $this->connectionFactory,
        );
        $oldCompletedMailbox = 'example-mailbox-old-completed';
        $recentCompletedMailbox = 'example-mailbox-recent-completed';
        $incompleteMailbox = 'example-mailbox-incomplete';

        $repository->recordSlackPosted(
            $oldCompletedMailbox,
            123456,
            101,
            '1234567890.100001',
        );
        $repository->markCompleted(
            $oldCompletedMailbox,
            123456,
            101,
        );
        $repository->recordSlackPosted(
            $recentCompletedMailbox,
            123456,
            102,
            '1234567890.100002',
        );
        $repository->markCompleted(
            $recentCompletedMailbox,
            123456,
            102,
        );
        $repository->recordSlackPosted(
            $incompleteMailbox,
            123456,
            103,
            '1234567890.100003',
        );

        $completedAtStatement = $this->connection->prepare(
            <<<'SQL'
                UPDATE mail_processing_history
                SET completed_at = :completed_at
                WHERE mailbox_identifier = :mailbox_identifier
                SQL,
        );
        $completedAtStatement->execute([
            'completed_at' => '2026-01-01 00:00:00.000000',
            'mailbox_identifier' => $oldCompletedMailbox,
        ]);
        $completedAtStatement->execute([
            'completed_at' => '2026-07-01 00:00:00.000000',
            'mailbox_identifier' => $recentCompletedMailbox,
        ]);

        $deletedCount = $repository->deleteExpiredCompleted(
            new DateTimeImmutable(
                '2026-08-03 12:00:00',
                new DateTimeZone('Asia/Tokyo'),
            ),
        );

        self::assertSame(1, $deletedCount);
        self::assertNull($repository->find(
            $oldCompletedMailbox,
            123456,
            101,
        ));
        self::assertNotNull($repository->find(
            $recentCompletedMailbox,
            123456,
            102,
        ));
        self::assertSame(
            [
                'slack_posted' => true,
                'completed' => false,
                'slack_timestamp' => '1234567890.100003',
            ],
            $repository->find($incompleteMailbox, 123456, 103),
        );
    }

    public function testMarkCompletedWithoutPriorHistoryCreatesCompletedOnlyRecord(): void
    {
        $repository = new MailProcessingHistoryRepository(
            $this->connectionFactory,
        );
        $mailboxIdentifier = 'example-mailbox-skip';

        self::assertNull($repository->find(
            $mailboxIdentifier,
            123456,
            101,
        ));

        $repository->markCompleted(
            $mailboxIdentifier,
            123456,
            101,
        );

        self::assertSame(
            [
                'slack_posted' => false,
                'completed' => true,
                'slack_timestamp' => null,
            ],
            $repository->find($mailboxIdentifier, 123456, 101),
        );
    }

    public function testMarkCompletedTwiceKeepsFirstCompletedAt(): void
    {
        $repository = new MailProcessingHistoryRepository(
            $this->connectionFactory,
        );
        $mailboxIdentifier = 'example-mailbox-completed-at';

        $repository->markCompleted(
            $mailboxIdentifier,
            123456,
            101,
        );

        $completedAtStatement = $this->connection->prepare(
            <<<'SQL'
                SELECT completed_at
                FROM mail_processing_history
                WHERE mailbox_identifier = :mailbox_identifier
                  AND uid_validity = :uid_validity
                  AND uid = :uid
                SQL,
        );
        $completedAtStatement->execute([
            'mailbox_identifier' => $mailboxIdentifier,
            'uid_validity' => 123456,
            'uid' => 101,
        ]);
        $firstCompletedAt = $completedAtStatement->fetchColumn();

        $repository->markCompleted(
            $mailboxIdentifier,
            123456,
            101,
        );

        $completedAtStatement->execute([
            'mailbox_identifier' => $mailboxIdentifier,
            'uid_validity' => 123456,
            'uid' => 101,
        ]);

        self::assertSame(
            $firstCompletedAt,
            $completedAtStatement->fetchColumn(),
        );
    }

    public function testSchemaStoresNoMailContent(): void
    {
        $columns = $this->connection->query(
            'SHOW COLUMNS FROM mail_processing_history',
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(
            [
                'id',
                'mailbox_identifier',
                'uid_validity',
                'uid',
                'slack_posted',
                'completed',
                'slack_timestamp',
                'created_at',
                'updated_at',
                'completed_at',
            ],
            $columns,
        );
    }
}

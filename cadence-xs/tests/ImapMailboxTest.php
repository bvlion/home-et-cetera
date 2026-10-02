<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail {
    function imap_open(
        string $mailbox,
        string $username,
        string $password,
        int $flags = 0,
        int $retries = 0,
        array $options = [],
    ): object|false {
        $GLOBALS['bvlion_batch5_imap_errors']
            = $GLOBALS['bvlion_batch5_next_imap_errors'] ?? false;
        $GLOBALS['bvlion_batch5_imap_alerts']
            = $GLOBALS['bvlion_batch5_next_imap_alerts'] ?? false;
        $connection = $GLOBALS['bvlion_batch5_next_imap_connection']
            ?? false;
        unset(
            $GLOBALS['bvlion_batch5_next_imap_connection'],
            $GLOBALS['bvlion_batch5_next_imap_errors'],
            $GLOBALS['bvlion_batch5_next_imap_alerts'],
        );

        return $connection;
    }

    function imap_errors(): array|false
    {
        $errors = $GLOBALS['bvlion_batch5_imap_errors'] ?? false;
        $GLOBALS['bvlion_batch5_imap_errors'] = false;

        return $errors;
    }

    function imap_alerts(): array|false
    {
        $alerts = $GLOBALS['bvlion_batch5_imap_alerts'] ?? false;
        $GLOBALS['bvlion_batch5_imap_alerts'] = false;

        return $alerts;
    }

    function imap_mail_move(
        object $connection,
        string $messageNumbers,
        string $mailbox,
        int $flags = 0,
    ): bool {
        $GLOBALS['bvlion_batch5_imap_move_arguments'] = [
            'message_numbers' => $messageNumbers,
            'mailbox' => $mailbox,
            'flags' => $flags,
        ];
        $moveErrors = $GLOBALS['bvlion_batch5_next_imap_move_errors']
            ?? false;
        $moveAlerts = $GLOBALS['bvlion_batch5_next_imap_move_alerts']
            ?? false;

        if (is_array($moveErrors)) {
            $currentErrors = $GLOBALS['bvlion_batch5_imap_errors'] ?? false;
            $GLOBALS['bvlion_batch5_imap_errors'] = array_merge(
                is_array($currentErrors) ? $currentErrors : [],
                $moveErrors,
            );
        }

        if (is_array($moveAlerts)) {
            $currentAlerts = $GLOBALS['bvlion_batch5_imap_alerts'] ?? false;
            $GLOBALS['bvlion_batch5_imap_alerts'] = array_merge(
                is_array($currentAlerts) ? $currentAlerts : [],
                $moveAlerts,
            );
        }

        $result = $GLOBALS['bvlion_batch5_next_imap_move_result'] ?? true;
        unset(
            $GLOBALS['bvlion_batch5_next_imap_move_result'],
            $GLOBALS['bvlion_batch5_next_imap_move_errors'],
            $GLOBALS['bvlion_batch5_next_imap_move_alerts'],
        );

        return $result;
    }

    function imap_expunge(object $connection): true
    {
        $GLOBALS['bvlion_batch5_imap_expunge_call_count']
            = ($GLOBALS['bvlion_batch5_imap_expunge_call_count'] ?? 0) + 1;
        $expungeErrors = $GLOBALS['bvlion_batch5_next_imap_expunge_errors']
            ?? false;
        $expungeAlerts = $GLOBALS['bvlion_batch5_next_imap_expunge_alerts']
            ?? false;

        if (is_array($expungeErrors)) {
            $currentErrors = $GLOBALS['bvlion_batch5_imap_errors'] ?? false;
            $GLOBALS['bvlion_batch5_imap_errors'] = array_merge(
                is_array($currentErrors) ? $currentErrors : [],
                $expungeErrors,
            );
        }

        if (is_array($expungeAlerts)) {
            $currentAlerts = $GLOBALS['bvlion_batch5_imap_alerts'] ?? false;
            $GLOBALS['bvlion_batch5_imap_alerts'] = array_merge(
                is_array($currentAlerts) ? $currentAlerts : [],
                $expungeAlerts,
            );
        }

        unset(
            $GLOBALS['bvlion_batch5_next_imap_expunge_errors'],
            $GLOBALS['bvlion_batch5_next_imap_expunge_alerts'],
        );

        return true;
    }

    function imap_close(object $connection): true
    {
        return true;
    }

    function imap_fetch_overview(
        object $connection,
        string $sequence,
        int $options = 0,
    ): array|false {
        return $GLOBALS['bvlion_batch5_next_imap_overview'] ?? false;
    }

    function imap_fetchstructure(
        object $connection,
        int $uid,
        int $options = 0,
    ): object|false {
        return $GLOBALS['bvlion_batch5_next_imap_structure'] ?? false;
    }

    function imap_fetchbody(
        object $connection,
        int $uid,
        string $section,
        int $options = 0,
    ): string|false {
        return $GLOBALS['bvlion_batch5_next_imap_body'] ?? false;
    }
}

namespace BvlionBatch5\Tests {
    use BvlionBatch5\Mail\ImapMailbox;
    use BvlionBatch5\Mail\MimeMessageDecoder;
    use DateTimeImmutable;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    final class ImapMailboxTest extends TestCase
    {
        public function testManualDiagnosticsRemoveConfigurationValues(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_errors'] = [
                'No such host as imap.example.test.',
                'TLS certificate failure for imap.example.test with '
                . 'password=example-password.',
                'Unexpected server text with token=example-token.',
            ];
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = [
                'Authentication failed for user@example.test.',
            ];
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            try {
                $mailbox->connect(true);
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith(
                    "IMAP connection failed.\nIMAP diagnostics: ",
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'Host resolution failed.',
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'TLS negotiation or certificate validation failed.',
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'Authentication failed.',
                    $exception->getMessage(),
                );
                self::assertStringContainsString(
                    'IMAP server returned an error.',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'imap.example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'user@example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'example-password',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'example-token',
                    $exception->getMessage(),
                );
            }

            self::assertFalse(\BvlionBatch5\Mail\imap_errors());
            self::assertFalse(\BvlionBatch5\Mail\imap_alerts());
        }

        public function testEmptyDiagnosticsUseOnlyFixedError(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            try {
                $mailbox->connect(true);
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP connection failed.',
                    $exception->getMessage(),
                );
            }
        }

        public function testNormalConnectionFailureDoesNotExposeDiagnostics(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_errors'] = [
                'Unable to connect to imap.example.test for '
                . 'user@example.test with password=example-password.',
            ];
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            try {
                $mailbox->connect();
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP connection failed.',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'imap.example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'user@example.test',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'example-password',
                    $exception->getMessage(),
                );
            }
        }

        public function testReadMessageWithoutConnectionFailsSafely(): void
        {
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('IMAP message read failed.');

            $mailbox->readMessage(101, new MimeMessageDecoder());
        }

        public function testMarkMessageAsSeenWithoutConnectionFailsSafely(): void
        {
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('IMAP message update failed.');

            $mailbox->markMessageAsSeen(101);
        }

        public function testMoveMessageWithoutConnectionFailsSafely(): void
        {
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('IMAP message move failed.');

            $mailbox->moveMessage(101, 'ExampleArchive');
        }

        public function testMoveMessageFailsWhenMoveReturnsFalse(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_result'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_errors'] = [
                'Example move error.',
            ];
            $GLOBALS['bvlion_batch5_next_imap_move_alerts'] = false;
            $GLOBALS['bvlion_batch5_imap_expunge_call_count'] = 0;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $mailbox->moveMessage(101, 'ExampleArchive');
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP message move failed.',
                    $exception->getMessage(),
                );
                self::assertSame(
                    0,
                    $GLOBALS['bvlion_batch5_imap_expunge_call_count'],
                );
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testMoveMessageFailsWhenMoveReportsError(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_result'] = true;
            $GLOBALS['bvlion_batch5_next_imap_move_errors'] = [
                'Example move error.',
            ];
            $GLOBALS['bvlion_batch5_next_imap_move_alerts'] = false;
            $GLOBALS['bvlion_batch5_imap_expunge_call_count'] = 0;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $mailbox->moveMessage(101, 'ExampleArchive');
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP message move failed.',
                    $exception->getMessage(),
                );
                self::assertSame(
                    0,
                    $GLOBALS['bvlion_batch5_imap_expunge_call_count'],
                );
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testMoveMessageFailsWhenExpungeReportsError(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_result'] = true;
            $GLOBALS['bvlion_batch5_next_imap_move_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_expunge_errors'] = [
                'Example expunge error.',
            ];
            $GLOBALS['bvlion_batch5_next_imap_expunge_alerts'] = false;
            $GLOBALS['bvlion_batch5_imap_expunge_call_count'] = 0;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $mailbox->moveMessage(101, 'ExampleArchive');
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP message move failed.',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    '101',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    'ExampleArchive',
                    $exception->getMessage(),
                );
                self::assertSame(
                    1,
                    $GLOBALS['bvlion_batch5_imap_expunge_call_count'],
                );
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testMoveMessageUsesUidAndCompletesExpunge(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_result'] = true;
            $GLOBALS['bvlion_batch5_next_imap_move_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_expunge_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_expunge_alerts'] = false;
            $GLOBALS['bvlion_batch5_imap_expunge_call_count'] = 0;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);
            $GLOBALS['bvlion_batch5_imap_errors'] = [
                'Example stale error.',
            ];
            $GLOBALS['bvlion_batch5_imap_alerts'] = [
                'Example stale alert.',
            ];

            try {
                $mailbox->moveMessage(101, 'ExampleArchive');

                self::assertSame(
                    [
                        'message_numbers' => '101',
                        'mailbox' => 'INBOX.ExampleArchive',
                        'flags' => CP_UID,
                    ],
                    $GLOBALS['bvlion_batch5_imap_move_arguments'],
                );
                self::assertSame(
                    1,
                    $GLOBALS['bvlion_batch5_imap_expunge_call_count'],
                );
                self::assertFalse(\BvlionBatch5\Mail\imap_errors());
                self::assertFalse(\BvlionBatch5\Mail\imap_alerts());
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testMoveMessageConvertsJapaneseFolderNameToUtf7Imap(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_result'] = true;
            $GLOBALS['bvlion_batch5_next_imap_move_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_move_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_expunge_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_expunge_alerts'] = false;
            $GLOBALS['bvlion_batch5_imap_expunge_call_count'] = 0;
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $mailbox->moveMessage(101, '日本語フォルダ');

                self::assertSame(
                    [
                        'message_numbers' => '101',
                        'mailbox' => 'INBOX.&ZeVnLIqeMNUwqTDrMMA-',
                        'flags' => CP_UID,
                    ],
                    $GLOBALS['bvlion_batch5_imap_move_arguments'],
                );
                self::assertSame(
                    1,
                    $GLOBALS['bvlion_batch5_imap_expunge_call_count'],
                );
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testReadMessageIncludesReceivedAtFromUdate(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_overview'] = [
                (object) [
                    'subject' => 'Example subject.',
                    'udate' => 1739750942,
                ],
            ];
            $GLOBALS['bvlion_batch5_next_imap_structure'] = (object) [
                'type' => TYPETEXT,
                'subtype' => 'PLAIN',
                'encoding' => ENC7BIT,
            ];
            $GLOBALS['bvlion_batch5_next_imap_body'] = 'Example body.';
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $result = $mailbox->readMessage(
                    101,
                    new MimeMessageDecoder(),
                );

                self::assertInstanceOf(
                    DateTimeImmutable::class,
                    $result['received_at'],
                );
                self::assertSame(
                    1739750942,
                    $result['received_at']->getTimestamp(),
                );
                self::assertSame(
                    'Asia/Tokyo',
                    $result['received_at']->getTimezone()->getName(),
                );
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testReadMessageIncludesHtmlBody(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_overview'] = [
                (object) [
                    'subject' => 'Example subject.',
                    'udate' => 1739750942,
                ],
            ];
            $GLOBALS['bvlion_batch5_next_imap_structure'] = (object) [
                'type' => TYPETEXT,
                'subtype' => 'HTML',
                'encoding' => ENC7BIT,
                'bytes' => 100,
            ];
            $GLOBALS['bvlion_batch5_next_imap_body']
                = '<p>Example HTML body.</p>';
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $result = $mailbox->readMessage(
                    101,
                    new MimeMessageDecoder(),
                );

                self::assertSame('', $result['body']);
                self::assertSame(
                    '<p>Example HTML body.</p>',
                    $result['html_body'],
                );
                self::assertSame([], $result['inline_images']);
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testReadMessagePlainOnlyReturnsEmptyHtmlBody(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_overview'] = [
                (object) [
                    'subject' => 'Example subject.',
                    'udate' => 1739750942,
                ],
            ];
            $GLOBALS['bvlion_batch5_next_imap_structure'] = (object) [
                'type' => TYPETEXT,
                'subtype' => 'PLAIN',
                'encoding' => ENC7BIT,
            ];
            $GLOBALS['bvlion_batch5_next_imap_body'] = 'Example body.';
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $result = $mailbox->readMessage(
                    101,
                    new MimeMessageDecoder(),
                );

                self::assertSame('Example body.', $result['body']);
                self::assertSame('', $result['html_body']);
                self::assertSame([], $result['inline_images']);
            } finally {
                $mailbox->disconnect();
            }
        }

        public function testReadMessageReturnsNullReceivedAtWhenUdateMissing(): void
        {
            $GLOBALS['bvlion_batch5_next_imap_connection'] = (object) [];
            $GLOBALS['bvlion_batch5_next_imap_errors'] = false;
            $GLOBALS['bvlion_batch5_next_imap_alerts'] = false;
            $GLOBALS['bvlion_batch5_next_imap_overview'] = [
                (object) ['subject' => 'Example subject.'],
            ];
            $GLOBALS['bvlion_batch5_next_imap_structure'] = (object) [
                'type' => TYPETEXT,
                'subtype' => 'PLAIN',
                'encoding' => ENC7BIT,
            ];
            $GLOBALS['bvlion_batch5_next_imap_body'] = 'Example body.';
            $mailbox = new ImapMailbox(
                'imap.example.test',
                993,
                'user@example.test',
                'example-password',
            );
            $mailbox->connect(false, false);

            try {
                $result = $mailbox->readMessage(
                    101,
                    new MimeMessageDecoder(),
                );

                self::assertNull($result['received_at']);
            } finally {
                $mailbox->disconnect();
            }
        }
    }
}

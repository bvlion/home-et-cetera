<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use DateTimeImmutable;
use DateTimeZone;
use IMAP\Connection;
use RuntimeException;

class ImapMailbox
{
    private const FOLDER = 'INBOX';
    private const RECEIVED_AT_TIMEZONE = 'Asia/Tokyo';

    /** @var Connection|null */
    private ?object $connection = null;
    private string $mailbox;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
    ) {
        $this->mailbox = sprintf(
            '{%s:%d/imap/ssl}%s',
            $this->host,
            $this->port,
            self::FOLDER,
        );
    }

    public function connect(
        bool $shouldIncludeDiagnostics = false,
        bool $shouldUseReadOnly = true,
    ): void {
        imap_errors();
        imap_alerts();

        $connection = @imap_open(
            $this->mailbox,
            $this->username,
            $this->password,
            $shouldUseReadOnly ? OP_READONLY : 0,
            1,
        );

        if ($connection === false) {
            $errors = imap_errors();
            $alerts = imap_alerts();

            imap_errors();
            imap_alerts();

            if ($shouldIncludeDiagnostics) {
                $diagnostics = [];

                foreach ([$errors, $alerts] as $messages) {
                    if (!is_array($messages)) {
                        continue;
                    }

                    foreach ($messages as $message) {
                        $message = trim((string) $message);

                        if ($message === '') {
                            continue;
                        }

                        $safeDiagnostic = match (true) {
                            preg_match(
                                '~no such host|name or service not known|'
                                . 'name resolution|getaddrinfo|'
                                . 'nodename nor servname~i',
                                $message,
                            ) === 1 => 'Host resolution failed.',
                            preg_match(
                                '~ssl|tls|certificate|x509~i',
                                $message,
                            ) === 1 => 'TLS negotiation or certificate '
                                . 'validation failed.',
                            preg_match(
                                '~auth|login|credential|password|username~i',
                                $message,
                            ) === 1 => 'Authentication failed.',
                            preg_match(
                                '~timed?\s*out|timeout~i',
                                $message,
                            ) === 1 => 'Network connection timed out.',
                            preg_match(
                                '~connection refused|refused connection~i',
                                $message,
                            ) === 1 => 'Network connection was refused.',
                            preg_match(
                                '~network|connect|socket|stream|host~i',
                                $message,
                            ) === 1 => 'Network connection failed.',
                            default => 'IMAP server returned an error.',
                        };

                        if (
                            $safeDiagnostic !== ''
                            && !in_array(
                                $safeDiagnostic,
                                $diagnostics,
                                true,
                            )
                        ) {
                            $diagnostics[] = $safeDiagnostic;
                        }
                    }
                }

                if ($diagnostics !== []) {
                    throw new RuntimeException(
                        "IMAP connection failed.\nIMAP diagnostics: "
                        . implode(' | ', $diagnostics),
                    );
                }
            }

            throw new RuntimeException('IMAP connection failed.');
        }

        $this->connection = $connection;
    }

    public function getUidValidity(): int
    {
        if ($this->connection === null) {
            throw new RuntimeException('IMAP folder reference failed.');
        }

        $status = @imap_status(
            $this->connection,
            $this->mailbox,
            SA_UIDVALIDITY,
        );

        if (
            $status === false
            || !isset($status->uidvalidity)
            || (int) $status->uidvalidity <= 0
        ) {
            imap_errors();
            imap_alerts();

            throw new RuntimeException('IMAP folder reference failed.');
        }

        return (int) $status->uidvalidity;
    }

    /**
     * @return list<array{uid: int, sender: string, subject: string}>
     */
    public function searchMessages(): array
    {
        if ($this->connection === null) {
            throw new RuntimeException('IMAP search failed.');
        }

        $messageCount = @imap_num_msg($this->connection);

        if ($messageCount === false) {
            imap_errors();
            imap_alerts();

            throw new RuntimeException('IMAP search failed.');
        }

        if ($messageCount === 0) {
            return [];
        }

        $messageUids = @imap_search(
            $this->connection,
            'ALL',
            SE_UID,
        );

        if ($messageUids === false) {
            imap_errors();
            imap_alerts();

            throw new RuntimeException('IMAP search failed.');
        }

        $messages = [];

        foreach ($messageUids as $messageUid) {
            $overview = @imap_fetch_overview(
                $this->connection,
                (string) $messageUid,
                FT_UID,
            );

            if ($overview === false || !isset($overview[0])) {
                imap_errors();
                imap_alerts();

                throw new RuntimeException('IMAP search failed.');
            }

            $messages[] = [
                'uid' => (int) $messageUid,
                'sender' => imap_utf8((string) ($overview[0]->from ?? '')),
                'subject' => imap_utf8(
                    (string) ($overview[0]->subject ?? ''),
                ),
            ];
        }

        return $messages;
    }

    /**
     * @return array{
     *     subject: string,
     *     body: string,
     *     html_body: string,
     *     inline_images: array<
     *         string,
     *         array{content_type: string, content: string}
     *     >,
     *     received_at: DateTimeImmutable|null
     * }
     */
    public function readMessage(
        int $uid,
        MimeMessageDecoder $decoder,
    ): array {
        if ($this->connection === null) {
            throw new RuntimeException('IMAP message read failed.');
        }

        $overview = @imap_fetch_overview(
            $this->connection,
            (string) $uid,
            FT_UID,
        );
        $structure = @imap_fetchstructure(
            $this->connection,
            $uid,
            FT_UID,
        );

        if (
            $overview === false
            || !isset($overview[0])
            || $structure === false
        ) {
            imap_errors();
            imap_alerts();

            throw new RuntimeException('IMAP message read failed.');
        }

        $bodyFetcher = fn (string $section): string|false => @imap_fetchbody(
            $this->connection,
            $uid,
            $section,
            FT_UID | FT_PEEK,
        );
        $body = $decoder->decodeBody($structure, $bodyFetcher);
        $inlineImages = [];
        $htmlBody = $decoder->decodeHtmlBody(
            $structure,
            $bodyFetcher,
            $inlineImages,
        );

        return [
            'subject' => $decoder->decodeSubject(
                (string) ($overview[0]->subject ?? ''),
            ),
            'body' => $body,
            'html_body' => $htmlBody,
            'inline_images' => $inlineImages,
            'received_at' => $this->extractReceivedAt($overview[0]),
        ];
    }

    /**
     * Extracts the message arrival date. imap_fetch_overview()'s
     * `udate` is documented as the UNIX timestamp of the arrival
     * date, i.e. the IMAP server's INTERNALDATE -- the same value
     * JavaMail's Message::getReceivedDate() reads for IMAP messages.
     * The `date` field (from the Date header) is a different value
     * and is not used here.
     */
    private function extractReceivedAt(object $overview): ?DateTimeImmutable
    {
        $udate = $overview->udate ?? null;

        if (!is_numeric($udate)) {
            return null;
        }

        return (new DateTimeImmutable('@' . (int) $udate))
            ->setTimezone(new DateTimeZone(self::RECEIVED_AT_TIMEZONE));
    }

    public function markMessageAsSeen(int $uid): void
    {
        if ($this->connection === null) {
            throw new RuntimeException('IMAP message update failed.');
        }

        imap_errors();
        imap_alerts();

        @imap_setflag_full(
            $this->connection,
            (string) $uid,
            '\\Seen',
            ST_UID,
        );

        $updateErrors = imap_errors();
        imap_alerts();
        $overview = @imap_fetch_overview(
            $this->connection,
            (string) $uid,
            FT_UID,
        );
        $verificationErrors = imap_errors();
        imap_alerts();

        if (
            is_array($updateErrors)
            || is_array($verificationErrors)
            || $overview === false
            || !isset($overview[0])
            || (int) ($overview[0]->seen ?? 0) !== 1
        ) {
            throw new RuntimeException('IMAP message update failed.');
        }
    }

    public function moveMessage(int $uid, string $toFolder): void
    {
        if ($this->connection === null) {
            throw new RuntimeException('IMAP message move failed.');
        }

        imap_errors();
        imap_alerts();

        $encodedToFolder = mb_convert_encoding(
            $toFolder,
            'UTF7-IMAP',
            'UTF-8',
        );

        $moved = @imap_mail_move(
            $this->connection,
            (string) $uid,
            self::FOLDER . '.' . $encodedToFolder,
            CP_UID,
        );
        $moveErrors = imap_errors();
        imap_alerts();

        if ($moved === false || is_array($moveErrors)) {
            throw new RuntimeException('IMAP message move failed.');
        }

        @imap_expunge($this->connection);
        $expungeErrors = imap_errors();
        imap_alerts();

        if (is_array($expungeErrors)) {
            throw new RuntimeException('IMAP message move failed.');
        }
    }

    public function disconnect(): void
    {
        if ($this->connection !== null) {
            @imap_close($this->connection);
            $this->connection = null;
        }

        imap_errors();
        imap_alerts();
    }
}

<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use RuntimeException;

final class ImapConnectivityCheckCommand
{
    public function run(
        ?string $host,
        ?string $port,
        ?string $username,
        ?string $password,
        ?ImapMailbox $mailbox = null,
    ): void {
        if ($host === null || trim($host) === '') {
            throw new RuntimeException('IMAP_HOST is required.');
        }

        if ($port === null || trim($port) === '') {
            throw new RuntimeException('IMAP_PORT is required.');
        }

        $validatedPort = filter_var(
            trim($port),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 65535,
                ],
            ],
        );

        if ($validatedPort === false) {
            throw new RuntimeException(
                'IMAP_PORT must be an integer between 1 and 65535.',
            );
        }

        if ($username === null || trim($username) === '') {
            throw new RuntimeException('IMAP_USERNAME is required.');
        }

        if ($password === null || trim($password) === '') {
            throw new RuntimeException('IMAP_PASSWORD is required.');
        }

        $mailbox ??= new ImapMailbox(
            $host,
            $validatedPort,
            $username,
            $password,
        );

        try {
            $mailbox->connect(true);
            $mailbox->getUidValidity();
            $mailbox->searchMessages();
            echo "IMAP connectivity check succeeded.\n";
        } finally {
            $mailbox->disconnect();
        }
    }
}

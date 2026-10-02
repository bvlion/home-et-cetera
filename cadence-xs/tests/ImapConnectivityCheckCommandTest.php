<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\ImapConnectivityCheckCommand;
use BvlionBatch5\Mail\ImapMailbox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImapConnectivityCheckCommandTest extends TestCase
{
    public function testMissingAndEmptyValuesFailBeforeConnection(): void
    {
        $configurationCases = [
            [
                null,
                '993',
                'user@example.test',
                'example-password',
                'IMAP_HOST is required.',
            ],
            [
                '',
                '993',
                'user@example.test',
                'example-password',
                'IMAP_HOST is required.',
            ],
            [
                'imap.example.test',
                null,
                'user@example.test',
                'example-password',
                'IMAP_PORT is required.',
            ],
            [
                'imap.example.test',
                '',
                'user@example.test',
                'example-password',
                'IMAP_PORT is required.',
            ],
            [
                'imap.example.test',
                '993',
                null,
                'example-password',
                'IMAP_USERNAME is required.',
            ],
            [
                'imap.example.test',
                '993',
                '',
                'example-password',
                'IMAP_USERNAME is required.',
            ],
            [
                'imap.example.test',
                '993',
                'user@example.test',
                null,
                'IMAP_PASSWORD is required.',
            ],
            [
                'imap.example.test',
                '993',
                'user@example.test',
                '',
                'IMAP_PASSWORD is required.',
            ],
        ];

        foreach (
            $configurationCases as [
                $host,
                $port,
                $username,
                $password,
                $expectedMessage,
            ]
        ) {
            try {
                (new ImapConnectivityCheckCommand())->run(
                    $host,
                    $port,
                    $username,
                    $password,
                );
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    $expectedMessage,
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
    }

    public function testInvalidPortsFailBeforeConnection(): void
    {
        $invalidPorts = [
            'example-port',
            '1.5',
            '0',
            '65536',
        ];

        foreach ($invalidPorts as $invalidPort) {
            try {
                (new ImapConnectivityCheckCommand())->run(
                    'imap.example.test',
                    $invalidPort,
                    'user@example.test',
                    'example-password',
                );
                self::fail('RuntimeException was not thrown.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'IMAP_PORT must be an integer between 1 and 65535.',
                    $exception->getMessage(),
                );
                self::assertStringNotContainsString(
                    $invalidPort,
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
    }

    public function testConnectionFailureDiagnosticsArePropagated(): void
    {
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox
            ->expects(self::once())
            ->method('connect')
            ->with(true)
            ->willThrowException(
                new RuntimeException(
                    "IMAP connection failed.\n"
                    . 'IMAP diagnostics: TLS negotiation or certificate '
                    . 'validation failed.',
                ),
            );
        $mailbox->expects(self::once())->method('disconnect');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "IMAP connection failed.\n"
            . 'IMAP diagnostics: TLS negotiation or certificate '
            . 'validation failed.',
        );

        (new ImapConnectivityCheckCommand())->run(
            'imap.example.test',
            '993',
            'user@example.test',
            'example-password',
            $mailbox,
        );
    }

    public function testConnectionFailureDiagnosticsUseStandardError(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/check-imap.php'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__),
            [
                'IMAP_HOST' => 'imap.example.invalid',
                'IMAP_PORT' => '993',
                'IMAP_USERNAME' => 'user@example.test',
                'IMAP_PASSWORD' => 'example-password',
            ],
        );

        self::assertIsResource($process);

        $standardOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $standardError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(1, $exitCode);
        self::assertSame('', $standardOutput);
        self::assertStringStartsWith(
            "IMAP connection failed.\nIMAP diagnostics: ",
            $standardError,
        );
        self::assertStringNotContainsString(
            'imap.example.invalid',
            $standardError,
        );
        self::assertStringNotContainsString(
            'user@example.test',
            $standardError,
        );
        self::assertStringNotContainsString(
            'example-password',
            $standardError,
        );
    }

    public function testSuccessOutputRemainsUnchanged(): void
    {
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox
            ->expects(self::once())
            ->method('connect')
            ->with(true);
        $mailbox
            ->expects(self::once())
            ->method('getUidValidity')
            ->willReturn(123456);
        $mailbox
            ->expects(self::once())
            ->method('searchMessages')
            ->willReturn([]);
        $mailbox->expects(self::once())->method('disconnect');

        $this->expectOutputString(
            "IMAP connectivity check succeeded.\n",
        );

        (new ImapConnectivityCheckCommand())->run(
            'imap.example.test',
            '993',
            'user@example.test',
            'example-password',
            $mailbox,
        );
    }
}

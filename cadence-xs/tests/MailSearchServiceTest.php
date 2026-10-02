<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\ImapMailbox;
use BvlionBatch5\Mail\MailRuleRepository;
use BvlionBatch5\Mail\MailSearchService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailSearchServiceTest extends TestCase
{
    public function testEnabledTargetsMatchSenderOrSubjectWithoutCase(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledTargets')->willReturn([
            'EXAMPLE-SENDER',
            'SPECIAL KEYWORD',
            '',
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox
            ->expects(self::once())
            ->method('getUidValidity')
            ->willReturn(123456);
        $mailbox
            ->expects(self::once())
            ->method('searchMessages')
            ->willReturn([
                [
                    'uid' => 101,
                    'sender' => 'example-sender@example.test',
                    'subject' => 'Example first subject.',
                ],
                [
                    'uid' => 102,
                    'sender' => 'other-sender@example.test',
                    'subject' => 'Example special keyword subject.',
                ],
                [
                    'uid' => 103,
                    'sender' => 'other-sender@example.test',
                    'subject' => 'Example unmatched subject.',
                ],
                [
                    'uid' => 104,
                    'sender' => 'example-sender@example.test',
                    'subject' => 'Example special keyword subject.',
                ],
            ]);
        $mailbox->expects(self::once())->method('disconnect');
        $service = new MailSearchService($mailRuleRepository, $mailbox);

        $messages = $service->search();

        self::assertSame(
            [
                ['uid' => 101, 'uid_validity' => 123456],
                ['uid' => 102, 'uid_validity' => 123456],
                ['uid' => 104, 'uid_validity' => 123456],
            ],
            $messages,
        );
    }

    public function testNoEnabledTargetsDoesNotConnect(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledTargets')->willReturn([]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::never())->method('connect');
        $mailbox->expects(self::never())->method('getUidValidity');
        $mailbox->expects(self::never())->method('searchMessages');
        $mailbox->expects(self::never())->method('disconnect');
        $service = new MailSearchService($mailRuleRepository, $mailbox);

        self::assertSame([], $service->search());
    }

    public function testConnectionFailureIsDistinguished(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledTargets')->willReturn([
            'EXAMPLE-SENDER',
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox
            ->expects(self::once())
            ->method('connect')
            ->willThrowException(
                new RuntimeException('IMAP connection failed.'),
            );
        $mailbox->expects(self::never())->method('disconnect');
        $service = new MailSearchService($mailRuleRepository, $mailbox);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IMAP connection failed.');

        $service->search();
    }

    public function testFolderReferenceFailureIsDistinguished(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledTargets')->willReturn([
            'EXAMPLE-SENDER',
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox
            ->expects(self::once())
            ->method('getUidValidity')
            ->willThrowException(
                new RuntimeException('IMAP folder reference failed.'),
            );
        $mailbox->expects(self::never())->method('searchMessages');
        $mailbox->expects(self::once())->method('disconnect');
        $service = new MailSearchService($mailRuleRepository, $mailbox);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IMAP folder reference failed.');

        $service->search();
    }

    public function testSearchFailureIsDistinguished(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledTargets')->willReturn([
            'EXAMPLE-SENDER',
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox
            ->expects(self::once())
            ->method('getUidValidity')
            ->willReturn(123456);
        $mailbox
            ->expects(self::once())
            ->method('searchMessages')
            ->willThrowException(
                new RuntimeException('IMAP search failed.'),
            );
        $mailbox->expects(self::once())->method('disconnect');
        $service = new MailSearchService($mailRuleRepository, $mailbox);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IMAP search failed.');

        $service->search();
    }
}

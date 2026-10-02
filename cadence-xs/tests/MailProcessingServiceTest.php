<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\HtmlToPdfConverter;
use BvlionBatch5\Mail\ImapMailbox;
use BvlionBatch5\Mail\MailProcessingHistoryRepository;
use BvlionBatch5\Mail\MailProcessingService;
use BvlionBatch5\Mail\MailRuleRepository;
use BvlionBatch5\Mail\MimeMessageDecoder;
use BvlionBatch5\Slack\SlackClient;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailProcessingServiceTest extends TestCase
{
    private function exampleReceivedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-03-05 07:09:02',
            new DateTimeZone('Asia/Tokyo'),
        );
    }

    public function testSuccessfulMessageIsPostedSeenMovedAndCompleted(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'EXAMPLE-SENDER',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox
            ->expects(self::once())
            ->method('connect')
            ->with(false, false);
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
                    'subject' => 'Example received message.',
                ],
            ]);
        $mailbox
            ->expects(self::once())
            ->method('readMessage')
            ->with(101, self::isInstanceOf(MimeMessageDecoder::class))
            ->willReturn([
                'subject' => 'Example subject.',
                'body' => 'Example body.',
                'html_body' => '',
                'received_at' => $this->exampleReceivedAt(),
            ]);
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository
            ->expects(self::once())
            ->method('find')
            ->with('example-mailbox', 123456, 101)
            ->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                101,
                '1234567890.123456',
            );
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.123456"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        $result = $service->process();

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $result,
        );
        self::assertCount(1, $requestHistory);
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => "件名：Example subject.\n"
                    . "----------\nExample body.\n----------",
                'username' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
            ],
            json_decode(
                (string) $requestHistory[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testDisplayNameIncludesFormattedReceivedDate(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => "'受信 'yyyy/MM/dd HH:mm",
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
            'html_body' => '',
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox->method('markMessageAsSeen');
        $mailbox->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createStub(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.123456"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        $result = $service->process();

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $result,
        );
        $payload = json_decode(
            (string) $requestHistory[0]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            'Example Forwarder受信 2026/03/05 07:09',
            $payload['username'],
        );
    }

    public function testMissingReceivedDateWithNonEmptyPrefixFormatFallsBackToPlainUserName(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => 'yyyy/MM/dd HH:mm',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
            'html_body' => '',
            'received_at' => null,
        ]);
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                101,
                '1234567890.123456',
            );
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.123456"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        $result = $service->process();

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $result,
        );
        $payload = json_decode(
            (string) $requestHistory[0]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('Example Forwarder', $payload['username']);
    }

    public function testMissingSlackDisplayDataFailsMessage(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => null,
                'icon_url' => null,
                'prefix_format' => null,
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
            'html_body' => '',
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox->expects(self::never())->method('markMessageAsSeen');
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository->expects(self::never())->method('markCompleted');
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    public function testNoEnabledRuleDoesNotConnectOrPost(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::never())->method('connect');
        $historyRepository = $this->createStub(
            MailProcessingHistoryRepository::class,
        );
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    public function testNoTargetMessageReturnsSuccessWithoutPosting(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([]);
        $mailbox->expects(self::never())->method('readMessage');
        $mailbox->expects(self::never())->method('markMessageAsSeen');
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createStub(
            MailProcessingHistoryRepository::class,
        );
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    public function testSlackFailureDoesNotMoveMessage(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
            'html_body' => '',
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox->expects(self::never())->method('markMessageAsSeen');
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository->expects(self::never())->method('markCompleted');
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client([
                    'handler' => new MockHandler([
                        new Response(
                            200,
                            [],
                            '{"ok":false,"error":"channel_not_found"}',
                        ),
                    ]),
                ]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
    }

    public function testBodyFetchFailureDoesNotProcessMessageAndContinues(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example first message.',
            ],
            [
                'uid' => 102,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example second message.',
            ],
        ]);
        $mailbox
            ->expects(self::exactly(2))
            ->method('readMessage')
            ->willReturnCallback(
                function (
                    int $uid,
                    MimeMessageDecoder $decoder,
                ): array {
                    self::assertInstanceOf(
                        MimeMessageDecoder::class,
                        $decoder,
                    );

                    if ($uid === 101) {
                        throw new RuntimeException(
                            'IMAP message body fetch failed.',
                        );
                    }

                    self::assertSame(102, $uid);

                    return [
                        'subject' => 'Example successful subject.',
                        'body' => 'Example successful body.',
                        'html_body' => '',
                        'received_at' => $this->exampleReceivedAt(),
                    ];
                },
            );
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(102);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(102, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository
            ->expects(self::exactly(2))
            ->method('find')
            ->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                102,
                '1234567890.123456',
            );
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 102);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.123456"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
        self::assertCount(1, $requestHistory);
    }

    public function testSeenFailureKeepsSlackPostedHistoryIncomplete(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
            'html_body' => '',
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101)
            ->willThrowException(
                new RuntimeException('IMAP message update failed.'),
            );
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                101,
                '1234567890.123456',
            );
        $historyRepository->expects(self::never())->method('markCompleted');
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.123456"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
        self::assertCount(1, $requestHistory);
    }

    public function testExpungeFailureKeepsSlackPostedHistoryIncomplete(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example body.',
            'html_body' => '',
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox->expects(self::once())->method('markMessageAsSeen');
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->willThrowException(
                new RuntimeException('IMAP message move failed.'),
            );
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted');
        $historyRepository->expects(self::never())->method('markCompleted');
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client([
                    'handler' => new MockHandler([
                        new Response(
                            200,
                            [],
                            '{"ok":true,"ts":"1234567890.123456"}',
                        ),
                    ]),
                ]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
    }

    public function testRetryAfterMoveFailureDoesNotPostAgain(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->expects(self::never())->method('readMessage');
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn([
            'slack_posted' => true,
            'completed' => false,
            'slack_timestamp' => '1234567890.123456',
        ]);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    public function testNullChannelIdSkipsSlackPostButMarksSeenMovedAndCompleted(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => null,
                'user_name' => null,
                'icon_url' => null,
                'prefix_format' => null,
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox
            ->expects(self::once())
            ->method('connect')
            ->with(false, false);
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
                    'subject' => 'Example received message.',
                ],
            ]);
        $mailbox->expects(self::never())->method('readMessage');
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository
            ->expects(self::once())
            ->method('find')
            ->with('example-mailbox', 123456, 101)
            ->willReturn(null);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        $result = $service->process();

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $result,
        );
        self::assertCount(0, $requestHistory);
    }

    public function testNullChannelIdRetriesMoveWithoutPostingToSlack(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => null,
                'user_name' => null,
                'icon_url' => null,
                'prefix_format' => null,
            ],
        ]);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $slackClient = new SlackClient(
            new Client(['handler' => $handlerStack]),
            'xoxb-example-bot-token',
        );

        $firstMailbox = $this->createMock(ImapMailbox::class);
        $firstMailbox->expects(self::once())->method('connect');
        $firstMailbox->method('getUidValidity')->willReturn(123456);
        $firstMailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $firstMailbox->expects(self::never())->method('readMessage');
        $firstMailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $firstMailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->willThrowException(
                new RuntimeException('IMAP message move failed.'),
            );
        $firstMailbox->expects(self::once())->method('disconnect');
        $firstHistoryRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $firstHistoryRepository->method('find')->willReturn(null);
        $firstHistoryRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $firstHistoryRepository
            ->expects(self::never())
            ->method('markCompleted');
        $firstService = new MailProcessingService(
            $mailRuleRepository,
            $firstMailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $firstHistoryRepository,
            $slackClient,
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $firstService->process(),
        );

        $secondMailbox = $this->createMock(ImapMailbox::class);
        $secondMailbox->expects(self::once())->method('connect');
        $secondMailbox->method('getUidValidity')->willReturn(123456);
        $secondMailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $secondMailbox->expects(self::never())->method('readMessage');
        $secondMailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $secondMailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $secondMailbox->expects(self::once())->method('disconnect');
        $secondHistoryRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $secondHistoryRepository->method('find')->willReturn(null);
        $secondHistoryRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $secondHistoryRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $secondService = new MailProcessingService(
            $mailRuleRepository,
            $secondMailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $secondHistoryRepository,
            $slackClient,
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $secondService->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    /**
     * @return MockHandler
     */
    private function successfulPdfUploadHandler(): MockHandler
    {
        return new MockHandler([
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => true,
                        'upload_url' => 'https://files.slack.com/upload/v1/'
                            . 'example-upload',
                        'file_id' => 'F0000000001',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
            new Response(200, [], 'OK'),
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => true,
                        'files' => [
                            ['id' => 'F0000000001', 'title' => 'mail.pdf'],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
    }

    public function testHtmlAndPlainMailIsPostedAsPdfFile(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => 'Example plain body.',
            'html_body' => '<p><img src="cid:logo@example.test">'
                . 'Example HTML body.</p>',
            'inline_images' => [
                'logo@example.test' => [
                    'content_type' => 'image/png',
                    'content' => $imageContent,
                ],
            ],
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with('example-mailbox', 123456, 101, 'F0000000001');
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(
            $this->successfulPdfUploadHandler(),
        );
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        $result = $service->process();

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $result,
        );
        self::assertCount(3, $requestHistory);
        self::assertSame(
            'https://slack.com/api/files.getUploadURLExternal',
            (string) $requestHistory[0]['request']->getUri(),
        );
        self::assertSame(
            'https://files.slack.com/upload/v1/example-upload',
            (string) $requestHistory[1]['request']->getUri(),
        );
        self::assertStringStartsWith(
            '%PDF-',
            (string) $requestHistory[1]['request']->getBody(),
        );
        self::assertStringContainsString(
            '/Subtype /Image',
            (string) $requestHistory[1]['request']->getBody(),
        );
        self::assertSame(
            'https://slack.com/api/files.completeUploadExternal',
            (string) $requestHistory[2]['request']->getUri(),
        );
        $completeUploadBody = (string) $requestHistory[2]['request']
            ->getBody();
        self::assertStringContainsString(
            '件名：Example subject.',
            $completeUploadBody,
        );
        self::assertStringContainsString(
            'Example Forwarder',
            $completeUploadBody,
        );
        self::assertStringContainsString(
            'C0000000000',
            $completeUploadBody,
        );
    }

    public function testHtmlOnlyMailIsPostedAsPdfFile(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => '',
            'html_body' => '<p>Example HTML only body.</p>',
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with('example-mailbox', 123456, 101, 'F0000000001');
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(
            $this->successfulPdfUploadHandler(),
        );
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(3, $requestHistory);
    }

    public function testPdfUploadFailureDoesNotMoveMessage(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->method('readMessage')->willReturn([
            'subject' => 'Example subject.',
            'body' => '',
            'html_body' => '<p>Example HTML body.</p>',
            'received_at' => $this->exampleReceivedAt(),
        ]);
        $mailbox->expects(self::never())->method('markMessageAsSeen');
        $mailbox->expects(self::never())->method('moveMessage');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn(null);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository->expects(self::never())->method('markCompleted');
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client([
                    'handler' => new MockHandler([
                        new Response(
                            200,
                            [],
                            '{"ok":false,"error":"invalid_auth"}',
                        ),
                    ]),
                ]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
    }

    public function testRetryAfterMoveFailureDoesNotRepostPdf(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example received message.',
            ],
        ]);
        $mailbox->expects(self::never())->method('readMessage');
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(101);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(101, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository->method('find')->willReturn([
            'slack_posted' => true,
            'completed' => false,
            'slack_timestamp' => 'F0000000001',
        ]);
        $historyRepository
            ->expects(self::never())
            ->method('recordSlackPosted');
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 101);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => true, 'failure_count' => 0],
            $service->process(),
        );
        self::assertCount(0, $requestHistory);
    }

    /**
     * An HTML body far larger than HtmlToPdfConverter::MAX_HTML_BYTES
     * must fail only that mail (no read/move/completion, no Slack
     * post attempt) and must not stop the batch: a later, normally
     * sized mail is still processed. Uses the real
     * HtmlToPdfConverter so the size check runs exactly as in
     * production, rather than asserting the interaction with a mock.
     */
    public function testOversizedHtmlBodyFailsWithoutMovingAndContinuesProcessing(): void
    {
        $mailRuleRepository = $this->createStub(MailRuleRepository::class);
        $mailRuleRepository->method('findEnabledRules')->willReturn([
            [
                'target_from' => 'example-sender',
                'to_folder' => 'ExampleArchive',
                'channel_id' => 'C0000000000',
                'user_name' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
                'prefix_format' => '',
            ],
        ]);
        $mailbox = $this->createMock(ImapMailbox::class);
        $mailbox->expects(self::once())->method('connect');
        $mailbox->method('getUidValidity')->willReturn(123456);
        $mailbox->method('searchMessages')->willReturn([
            [
                'uid' => 101,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example oversized message.',
            ],
            [
                'uid' => 102,
                'sender' => 'example-sender@example.test',
                'subject' => 'Example normal message.',
            ],
        ]);
        $mailbox
            ->expects(self::exactly(2))
            ->method('readMessage')
            ->willReturnCallback(
                function (
                    int $uid,
                    MimeMessageDecoder $decoder,
                ): array {
                    if ($uid === 101) {
                        return [
                            'subject' => 'Example oversized subject.',
                            'body' => '',
                            'html_body' => '<html><body><p>'
                                . str_repeat('a', 5_000_000)
                                . '</p></body></html>',
                            'received_at' => $this->exampleReceivedAt(),
                        ];
                    }

                    self::assertSame(102, $uid);

                    return [
                        'subject' => 'Example normal subject.',
                        'body' => 'Example normal body.',
                        'html_body' => '',
                        'received_at' => $this->exampleReceivedAt(),
                    ];
                },
            );
        $mailbox
            ->expects(self::once())
            ->method('markMessageAsSeen')
            ->with(102);
        $mailbox
            ->expects(self::once())
            ->method('moveMessage')
            ->with(102, 'ExampleArchive');
        $mailbox->expects(self::once())->method('disconnect');
        $historyRepository = $this->createMock(
            MailProcessingHistoryRepository::class,
        );
        $historyRepository
            ->expects(self::exactly(2))
            ->method('find')
            ->willReturn(null);
        $historyRepository
            ->expects(self::once())
            ->method('recordSlackPosted')
            ->with(
                'example-mailbox',
                123456,
                102,
                '1234567890.123456',
            );
        $historyRepository
            ->expects(self::once())
            ->method('markCompleted')
            ->with('example-mailbox', 123456, 102);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.123456"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new MailProcessingService(
            $mailRuleRepository,
            $mailbox,
            new MimeMessageDecoder(),
            new HtmlToPdfConverter(),
            $historyRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            'example-mailbox',
        );

        self::assertSame(
            ['success' => false, 'failure_count' => 1],
            $service->process(),
        );
        // Only message 102's plain-text postCustomMessage() call: the
        // oversized message 101 never reaches Slack at all.
        self::assertCount(1, $requestHistory);
        self::assertStringContainsString(
            'Example normal subject.',
            (string) $requestHistory[0]['request']->getBody(),
        );
    }
}

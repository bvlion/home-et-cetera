<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Dating\DatingNotificationService;
use BvlionBatch5\Dating\DatingRepository;
use BvlionBatch5\Slack\SlackClient;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DatingNotificationServiceTest extends TestCase
{
    public function testFourDigitTargetDateMatchesEveryYearInJapan(): void
    {
        $datingRepository = $this->createStub(DatingRepository::class);
        $datingRepository->method('findAll')->willReturn([
            [
                'target_date' => '0820',
                'message' => 'Example annual notification.',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.000001"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new DatingNotificationService(
            $datingRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            new DateTimeZone('Asia/Tokyo'),
        );

        $service->notify(
            new DateTimeImmutable(
                '2026-08-19 15:30:00',
                new DateTimeZone('UTC'),
            ),
        );

        self::assertCount(1, $requestHistory);
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => 'Example annual notification.',
                'username' => 'dating-bot-20260820',
                'icon_url' => 'https://4s.ambitious-i.net/icon/life118.png',
            ],
            json_decode(
                (string) $requestHistory[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testEightDigitTargetDateNotifiesEveryHundredDays(): void
    {
        $datingRepository = $this->createStub(DatingRepository::class);
        $datingRepository->method('findAll')->willReturn([
            [
                'target_date' => '20260101',
                'message' => 'Example milestone: %s days.',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.000002"}',
            ),
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.000003"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new DatingNotificationService(
            $datingRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            new DateTimeZone('Asia/Tokyo'),
        );

        $service->notify(
            new DateTimeImmutable(
                '2026-01-01 12:00:00',
                new DateTimeZone('Asia/Tokyo'),
            ),
        );
        $service->notify(
            new DateTimeImmutable(
                '2026-04-11 12:00:00',
                new DateTimeZone('Asia/Tokyo'),
            ),
        );

        self::assertCount(2, $requestHistory);
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => 'Example milestone: 0 days.',
                'username' => 'dating-bot-20260101',
                'icon_url' => 'https://4s.ambitious-i.net/icon/life118.png',
            ],
            json_decode(
                (string) $requestHistory[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => 'Example milestone: 100 days.',
                'username' => 'dating-bot-20260411',
                'icon_url' => 'https://4s.ambitious-i.net/icon/life118.png',
            ],
            json_decode(
                (string) $requestHistory[1]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testFutureEightDigitTargetDateDoesNotPostMessage(): void
    {
        $datingRepository = $this->createStub(DatingRepository::class);
        $datingRepository->method('findAll')->willReturn([
            [
                'target_date' => '20260411',
                'message' => 'Example milestone: %s days.',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new DatingNotificationService(
            $datingRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            new DateTimeZone('Asia/Tokyo'),
        );

        $service->notify(
            new DateTimeImmutable(
                '2026-01-01 12:00:00',
                new DateTimeZone('Asia/Tokyo'),
            ),
        );

        self::assertCount(0, $requestHistory);
    }

    public function testNonMatchingTargetsDoNotPostMessage(): void
    {
        $datingRepository = $this->createStub(DatingRepository::class);
        $datingRepository->method('findAll')->willReturn([
            [
                'target_date' => '0819',
                'message' => 'Example annual notification.',
                'channel_id' => 'C0000000000',
            ],
            [
                'target_date' => '20260101',
                'message' => 'Example milestone: %s days.',
                'channel_id' => 'C0000000000',
            ],
            [
                'target_date' => '20260230',
                'message' => 'Example invalid date: %s days.',
                'channel_id' => 'C0000000000',
            ],
        ]);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler());
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new DatingNotificationService(
            $datingRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            new DateTimeZone('Asia/Tokyo'),
        );

        $service->notify(
            new DateTimeImmutable(
                '2026-04-12 12:00:00',
                new DateTimeZone('Asia/Tokyo'),
            ),
        );

        self::assertCount(0, $requestHistory);
    }

    public function testMultipleMatchesAreGroupedByChannel(): void
    {
        $datingRepository = $this->createStub(DatingRepository::class);
        $datingRepository->method('findAll')->willReturn([
            [
                'target_date' => '0411',
                'message' => 'Example annual notification.',
                'channel_id' => 'C0000000000',
            ],
            [
                'target_date' => '20260101',
                'message' => 'Example milestone: %s days.',
                'channel_id' => 'C0000000000',
            ],
            [
                'target_date' => '0411',
                'message' => 'Example other channel notification.',
                'channel_id' => 'C1111111111',
            ],
        ]);
        $requestHistory = [];
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.000003"}',
            ),
            new Response(
                200,
                [],
                '{"ok":true,"ts":"1234567890.000004"}',
            ),
        ]);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $service = new DatingNotificationService(
            $datingRepository,
            new SlackClient(
                new Client(['handler' => $handlerStack]),
                'xoxb-example-bot-token',
            ),
            new DateTimeZone('Asia/Tokyo'),
        );

        $service->notify(
            new DateTimeImmutable(
                '2026-04-11 12:00:00',
                new DateTimeZone('Asia/Tokyo'),
            ),
        );

        self::assertCount(2, $requestHistory);
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => "Example annual notification.\n"
                    . 'Example milestone: 100 days.',
                'username' => 'dating-bot-20260411',
                'icon_url' => 'https://4s.ambitious-i.net/icon/life118.png',
            ],
            json_decode(
                (string) $requestHistory[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(
            [
                'channel' => 'C1111111111',
                'text' => 'Example other channel notification.',
                'username' => 'dating-bot-20260411',
                'icon_url' => 'https://4s.ambitious-i.net/icon/life118.png',
            ],
            json_decode(
                (string) $requestHistory[1]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }
}

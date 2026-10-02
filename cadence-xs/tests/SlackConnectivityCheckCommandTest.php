<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Slack\SlackConnectivityCheckCommand;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SlackConnectivityCheckCommandTest extends TestCase
{
    public function testMissingBotTokenFailsWithEnvironmentName(): void
    {
        $command = new SlackConnectivityCheckCommand(
            new Client(['handler' => new MockHandler()]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SLACK_BOT_TOKEN is required.');

        $command->run(null, 'C0000000000');
    }

    public function testEmptyBotTokenFailsWithEnvironmentName(): void
    {
        $command = new SlackConnectivityCheckCommand(
            new Client(['handler' => new MockHandler()]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SLACK_BOT_TOKEN is required.');

        $command->run('', 'C0000000000');
    }

    public function testMissingChannelIdFailsWithEnvironmentName(): void
    {
        $command = new SlackConnectivityCheckCommand(
            new Client(['handler' => new MockHandler()]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'SLACK_TEST_CHANNEL_ID is required.',
        );

        $command->run('xoxb-example-bot-token', null);
    }

    public function testEmptyChannelIdFailsWithEnvironmentName(): void
    {
        $command = new SlackConnectivityCheckCommand(
            new Client(['handler' => new MockHandler()]),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'SLACK_TEST_CHANNEL_ID is required.',
        );

        $command->run('xoxb-example-bot-token', '');
    }

    public function testSuccessOutputsOnlySuccessMessage(): void
    {
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
        $command = new SlackConnectivityCheckCommand(
            new Client(['handler' => $handlerStack]),
        );

        $this->expectOutputString(
            "Slack connectivity check succeeded.\n",
        );

        $command->run(
            'xoxb-example-bot-token',
            'C0000000000',
        );

        self::assertCount(1, $requestHistory);
        self::assertSame(
            'Bearer xoxb-example-bot-token',
            $requestHistory[0]['request']->getHeaderLine('Authorization'),
        );
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => 'Slack API connectivity test.',
            ],
            json_decode(
                (string) $requestHistory[0]['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }
}

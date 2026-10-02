<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Slack\SlackClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SlackClientTest extends TestCase
{
    public function testPostMessageReturnsTimestamp(): void
    {
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => true,
                        'channel' => 'C0000000000',
                        'ts' => '1234567890.123456',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $requestHistory = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $slackClient = new SlackClient(
            new Client(['handler' => $handlerStack]),
            'xoxb-example-bot-token',
        );

        $timestamp = $slackClient->postMessage(
            'C0000000000',
            'Example notification.',
        );

        self::assertSame('1234567890.123456', $timestamp);
        self::assertCount(1, $requestHistory);
        $request = $requestHistory[0]['request'];
        $options = $requestHistory[0]['options'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            'https://slack.com/api/chat.postMessage',
            (string) $request->getUri(),
        );
        self::assertSame(
            'Bearer xoxb-example-bot-token',
            $request->getHeaderLine('Authorization'),
        );
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => 'Example notification.',
            ],
            json_decode(
                (string) $request->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(10.0, $options['timeout']);
    }

    public function testTimeoutDoesNotExposeRequestValues(): void
    {
        $mockHandler = new MockHandler([
            new ConnectException(
                'Example timeout.',
                new Request(
                    'POST',
                    'https://slack.com/api/chat.postMessage',
                ),
            ),
        ]);
        $slackClient = new SlackClient(
            new Client([
                'handler' => HandlerStack::create($mockHandler),
            ]),
            'xoxb-example-bot-token',
        );

        try {
            $slackClient->postMessage(
                'C0000000000',
                'Example notification.',
            );
            self::fail('Slackリクエストが失敗しませんでした。');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Slack request failed.',
                $exception->getMessage(),
            );
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(
                'xoxb-example-bot-token',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'C0000000000',
                $exception->getMessage(),
            );
        }
    }

    public function testHttpErrorIsHandled(): void
    {
        $mockHandler = new MockHandler([
            new Response(500),
        ]);
        $slackClient = new SlackClient(
            new Client([
                'handler' => HandlerStack::create($mockHandler),
            ]),
            'xoxb-example-bot-token',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Slack request failed.');

        $slackClient->postMessage(
            'C0000000000',
            'Example notification.',
        );
    }

    public function testSlackApiErrorIsHandled(): void
    {
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => false,
                        'error' => 'channel_not_found',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $slackClient = new SlackClient(
            new Client(['handler' => $mockHandler]),
            'xoxb-example-bot-token',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Slack API request failed.');

        $slackClient->postMessage(
            'C0000000000',
            'Example notification.',
        );
    }

    public function testPostCustomMessageIncludesUsernameAndIconUrl(): void
    {
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => true,
                        'channel' => 'C0000000000',
                        'ts' => '1234567890.123456',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $requestHistory = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $slackClient = new SlackClient(
            new Client(['handler' => $handlerStack]),
            'xoxb-example-bot-token',
        );

        $timestamp = $slackClient->postCustomMessage(
            'C0000000000',
            'Example notification.',
            'Example Forwarder',
            'https://example.test/icon.png',
        );

        self::assertSame('1234567890.123456', $timestamp);
        self::assertCount(1, $requestHistory);
        $request = $requestHistory[0]['request'];
        self::assertSame(
            [
                'channel' => 'C0000000000',
                'text' => 'Example notification.',
                'username' => 'Example Forwarder',
                'icon_url' => 'https://example.test/icon.png',
            ],
            json_decode(
                (string) $request->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testPostCustomMessageSlackApiErrorIsHandled(): void
    {
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => false,
                        'error' => 'channel_not_found',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $slackClient = new SlackClient(
            new Client(['handler' => $mockHandler]),
            'xoxb-example-bot-token',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Slack API request failed.');

        $slackClient->postCustomMessage(
            'C0000000000',
            'Example notification.',
            'Example Forwarder',
            'https://example.test/icon.png',
        );
    }

    public function testPostPdfFileUploadsInThreeStepsAndReturnsFileId(): void
    {
        $mockHandler = new MockHandler([
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
            new Response(200, [], 'OK - 8'),
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => true,
                        'files' => [
                            ['id' => 'F0000000001', 'title' => 'Example'],
                        ],
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $requestHistory = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $slackClient = new SlackClient(
            new Client(['handler' => $handlerStack]),
            'xoxb-example-bot-token',
        );

        $fileId = $slackClient->postPdfFile(
            'C0000000000',
            'mail.pdf',
            'example-pdf',
            '件名：Example subject.',
            'Example Forwarder',
            'https://example.test/icon.png',
        );

        self::assertSame('F0000000001', $fileId);
        self::assertCount(3, $requestHistory);

        $uploadUrlRequest = $requestHistory[0]['request'];
        self::assertSame(
            'https://slack.com/api/files.getUploadURLExternal',
            (string) $uploadUrlRequest->getUri(),
        );
        self::assertSame(
            'Bearer xoxb-example-bot-token',
            $uploadUrlRequest->getHeaderLine('Authorization'),
        );
        // Guzzleのmultipart出力は、Content-Dispositionと空行の間に
        // バージョンによって任意のpart headerが0行または複数行入る
        // （Guzzle 7は入ることがあるが、Guzzle 8では入らない）ため、
        // その行数に依存しない正規表現でfilenameとlengthの値を検証する。
        $uploadUrlBody = (string) $uploadUrlRequest->getBody();
        self::assertMatchesRegularExpression(
            '/name="filename"\r\n(?:[^\r\n]+\r\n)*\r\nmail\.pdf\r\n/',
            $uploadUrlBody,
        );
        self::assertMatchesRegularExpression(
            '/name="length"\r\n(?:[^\r\n]+\r\n)*\r\n11\r\n/',
            $uploadUrlBody,
        );

        $uploadRequest = $requestHistory[1]['request'];
        self::assertSame(
            'https://files.slack.com/upload/v1/example-upload',
            (string) $uploadRequest->getUri(),
        );
        self::assertSame(
            '',
            $uploadRequest->getHeaderLine('Authorization'),
        );
        self::assertSame(
            'application/octet-stream',
            $uploadRequest->getHeaderLine('Content-Type'),
        );
        self::assertSame(
            'example-pdf',
            (string) $uploadRequest->getBody(),
        );

        $completeUploadRequest = $requestHistory[2]['request'];
        self::assertSame(
            'https://slack.com/api/files.completeUploadExternal',
            (string) $completeUploadRequest->getUri(),
        );
        self::assertSame(
            'Bearer xoxb-example-bot-token',
            $completeUploadRequest->getHeaderLine('Authorization'),
        );
        $completeUploadBody = (string) $completeUploadRequest->getBody();
        self::assertStringContainsString(
            '[{"id":"F0000000001","title":"mail.pdf"}]',
            $completeUploadBody,
        );
        self::assertStringContainsString('C0000000000', $completeUploadBody);
        self::assertStringContainsString(
            '件名：Example subject.',
            $completeUploadBody,
        );
        self::assertStringContainsString(
            'Example Forwarder',
            $completeUploadBody,
        );
        self::assertStringContainsString(
            'https://example.test/icon.png',
            $completeUploadBody,
        );
    }

    public function testPostPdfFileUploadStepHttpErrorIsHandled(): void
    {
        $mockHandler = new MockHandler([
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
            new Response(500, [], 'Internal Server Error'),
        ]);
        $slackClient = new SlackClient(
            new Client(['handler' => $mockHandler]),
            'xoxb-example-bot-token',
        );

        try {
            $slackClient->postPdfFile(
                'C0000000000',
                'mail.pdf',
                'example-pdf',
                '件名：Example subject.',
                'Example Forwarder',
                'https://example.test/icon.png',
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Slack file upload failed.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'xoxb-example-bot-token',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'C0000000000',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                'F0000000001',
                $exception->getMessage(),
            );
        }
    }

    public function testPostPdfFileCompleteUploadStepSlackApiErrorIsHandled(): void
    {
        $mockHandler = new MockHandler([
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
            new Response(200, [], 'OK - 8'),
            new Response(
                200,
                [],
                json_encode(
                    [
                        'ok' => false,
                        'error' => 'channel_not_found',
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $slackClient = new SlackClient(
            new Client(['handler' => $mockHandler]),
            'xoxb-example-bot-token',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Slack API request failed.');

        $slackClient->postPdfFile(
            'C0000000000',
            'mail.pdf',
            'example-pdf',
            '件名：Example subject.',
            'Example Forwarder',
            'https://example.test/icon.png',
        );
    }

    public function testPostPdfFileRequestUploadUrlFailureDoesNotUpload(): void
    {
        $mockHandler = new MockHandler([
            new Response(
                200,
                [],
                json_encode(
                    ['ok' => false, 'error' => 'invalid_auth'],
                    JSON_THROW_ON_ERROR,
                ),
            ),
        ]);
        $requestHistory = [];
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($requestHistory));
        $slackClient = new SlackClient(
            new Client(['handler' => $handlerStack]),
            'xoxb-example-bot-token',
        );

        try {
            $slackClient->postPdfFile(
                'C0000000000',
                'mail.pdf',
                'example-pdf',
                '件名：Example subject.',
                'Example Forwarder',
                'https://example.test/icon.png',
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Slack API request failed.',
                $exception->getMessage(),
            );
        }

        self::assertCount(1, $requestHistory);
    }
}

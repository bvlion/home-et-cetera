<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Middleware\BearerTokenMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class BearerTokenMiddlewareTest extends TestCase
{
    public function testConfiguredTokensAllowProtectedRouteProcessing(): void
    {
        $configuration = require __DIR__ . '/../bootstrap/config.php';
        $responseFactory = new ResponseFactory();
        $protectedRoutes = [
            '/api/mail/process' =>
                $configuration['bearer_token']['scheduler'],
            '/api/dating/notify' =>
                $configuration['bearer_token']['scheduler'],
        ];

        foreach ($protectedRoutes as $path => $token) {
            $middleware = new BearerTokenMiddleware(
                $token,
                $responseFactory,
            );
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler
                ->expects(self::once())
                ->method('handle')
                ->willReturn($responseFactory->createResponse(204));
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', $path)
                ->withHeader('Authorization', 'Bearer ' . $token);

            $response = $middleware->process($request, $handler);

            self::assertSame(204, $response->getStatusCode());
        }
    }

    public function testMissingMalformedAndMismatchedTokensReturnUnauthorized(): void
    {
        $configuration = require __DIR__ . '/../bootstrap/config.php';
        $expectedToken = $configuration['bearer_token']['scheduler'];
        $authorizationHeaders = [
            null,
            'Basic example_credentials',
            'Bearer',
            'Bearer example_token extra',
            'Bearer different_token',
        ];
        $responseFactory = new ResponseFactory();

        foreach ($authorizationHeaders as $authorizationHeader) {
            $middleware = new BearerTokenMiddleware(
                $expectedToken,
                $responseFactory,
            );
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::never())->method('handle');
            $request = (new ServerRequestFactory())
                ->createServerRequest('POST', '/api/mail/process');

            if ($authorizationHeader !== null) {
                $request = $request->withHeader(
                    'Authorization',
                    $authorizationHeader,
                );
            }

            $response = $middleware->process($request, $handler);
            $responseBody = (string) $response->getBody();
            $responsePayload = json_decode(
                $responseBody,
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            self::assertSame(401, $response->getStatusCode());
            self::assertSame(
                'application/json; charset=utf-8',
                $response->getHeaderLine('Content-Type'),
            );
            self::assertSame(
                'Bearer',
                $response->getHeaderLine('WWW-Authenticate'),
            );
            self::assertSame(
                ['message' => '401 Unauthorized'],
                $responsePayload,
            );
            self::assertStringNotContainsString(
                $expectedToken,
                $responseBody,
            );

            if ($authorizationHeader !== null) {
                self::assertStringNotContainsString(
                    $authorizationHeader,
                    $responseBody,
                );
            }
        }
    }
}

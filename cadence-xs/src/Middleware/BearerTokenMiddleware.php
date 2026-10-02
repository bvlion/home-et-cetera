<?php

declare(strict_types=1);

namespace BvlionBatch5\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BearerTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $expectedToken,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $authorizationHeader = $request->getHeaderLine('Authorization');
        $matches = [];
        $hasBearerToken = preg_match(
            '#\ABearer +([A-Za-z0-9\-._~+/]+=*)\z#i',
            $authorizationHeader,
            $matches,
        ) === 1;

        if (
            !$hasBearerToken
            || !hash_equals($this->expectedToken, $matches[1])
        ) {
            $response = $this->responseFactory->createResponse(401);
            $response->getBody()->write(
                json_encode(
                    ['message' => '401 Unauthorized'],
                    JSON_THROW_ON_ERROR,
                ),
            );

            return $response
                ->withHeader(
                    'Content-Type',
                    'application/json; charset=utf-8',
                )
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        return $handler->handle($request);
    }
}

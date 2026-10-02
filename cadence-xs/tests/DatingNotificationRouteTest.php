<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DatingNotificationRouteTest extends TestCase
{
    public function testDatingNotificationRouteRequiresAuthentication(): void
    {
        /** @var App<null> $app */
        $app = require __DIR__ . '/../bootstrap/app.php';
        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/api/dating/notify',
        );

        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            'Bearer',
            $response->getHeaderLine('WWW-Authenticate'),
        );
    }
}

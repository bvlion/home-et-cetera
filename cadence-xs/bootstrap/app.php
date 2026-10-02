<?php

declare(strict_types=1);

use BvlionBatch5\Database\ConnectionFactory;
use BvlionBatch5\Dating\DatingNotificationService;
use BvlionBatch5\Dating\DatingRepository;
use BvlionBatch5\Mail\HtmlToPdfConverter;
use BvlionBatch5\Mail\ImapMailbox;
use BvlionBatch5\Mail\MailProcessingHistoryRepository;
use BvlionBatch5\Mail\MailProcessingService;
use BvlionBatch5\Mail\MailRuleRepository;
use BvlionBatch5\Mail\MimeMessageDecoder;
use BvlionBatch5\Middleware\BearerTokenMiddleware;
use BvlionBatch5\Slack\SlackClient;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';
$configuration = require __DIR__ . '/config.php';

$app = AppFactory::create();
$app->addRoutingMiddleware();

$databaseConfiguration = $configuration['database'];
$datingNotificationService = new DatingNotificationService(
    new DatingRepository(
        new ConnectionFactory(
            $databaseConfiguration['host'],
            $databaseConfiguration['port'],
            $databaseConfiguration['name'],
            $databaseConfiguration['user'],
            $databaseConfiguration['password'],
        ),
    ),
    new SlackClient(
        new Client(),
        $configuration['slack']['bot_token'],
    ),
    new DateTimeZone($configuration['app']['timezone']),
);
$imapConfiguration = $configuration['imap'];
$mailConnectionFactory = new ConnectionFactory(
    $databaseConfiguration['host'],
    $databaseConfiguration['port'],
    $databaseConfiguration['name'],
    $databaseConfiguration['user'],
    $databaseConfiguration['password'],
);
$mailProcessingService = new MailProcessingService(
    new MailRuleRepository($mailConnectionFactory),
    new ImapMailbox(
        $imapConfiguration['host'],
        (int) $imapConfiguration['port'],
        $imapConfiguration['username'],
        $imapConfiguration['password'],
    ),
    new MimeMessageDecoder(),
    new HtmlToPdfConverter(),
    new MailProcessingHistoryRepository($mailConnectionFactory),
    new SlackClient(
        new Client(),
        $configuration['slack']['bot_token'],
    ),
    hash(
        'sha256',
        $imapConfiguration['host']
        . "\0"
        . $imapConfiguration['username']
        . "\0INBOX",
    ),
);

$app
    ->post(
        '/api/mail/process',
        function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($mailProcessingService): ResponseInterface {
            $response->getBody()->write(
                json_encode(
                    $mailProcessingService->process(),
                    JSON_THROW_ON_ERROR,
                ),
            );

            return $response;
        },
    )
    ->add(
        new BearerTokenMiddleware(
            $configuration['bearer_token']['scheduler'],
            $app->getResponseFactory(),
        ),
    );

$app
    ->post(
        '/api/dating/notify',
        function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($datingNotificationService): ResponseInterface {
            $datingNotificationService->notify();

            return $response->withStatus(204);
        },
    )
    ->add(
        new BearerTokenMiddleware(
            $configuration['bearer_token']['scheduler'],
            $app->getResponseFactory(),
        ),
    );

$app->add(
    function (
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return $handler
            ->handle($request)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    },
);

$errorMiddleware = $app->addErrorMiddleware(false, false, false);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('application/json');

return $app;

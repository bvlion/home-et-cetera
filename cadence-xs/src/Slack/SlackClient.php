<?php

declare(strict_types=1);

namespace BvlionBatch5\Slack;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class SlackClient
{
    private const ENDPOINT = 'https://slack.com/api/chat.postMessage';
    private const UPLOAD_URL_ENDPOINT
        = 'https://slack.com/api/files.getUploadURLExternal';
    private const COMPLETE_UPLOAD_ENDPOINT
        = 'https://slack.com/api/files.completeUploadExternal';
    private const TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private ClientInterface $httpClient,
        private string $botToken,
    ) {
    }

    public function postMessage(string $channelId, string $message): string
    {
        return $this->send([
            'channel' => $channelId,
            'text' => $message,
        ]);
    }

    /**
     * Same as postMessage(), but overrides the Bot's display name and
     * icon for this single post via chat:write.customize.
     */
    public function postCustomMessage(
        string $channelId,
        string $message,
        string $username,
        string $iconUrl,
    ): string {
        return $this->send([
            'channel' => $channelId,
            'text' => $message,
            'username' => $username,
            'icon_url' => $iconUrl,
        ]);
    }

    /**
     * Uploads a PDF via Slack's current three-step file upload flow
     * (files.getUploadURLExternal, an upload POST, then
     * files.completeUploadExternal) and shares it to the channel with
     * the customized display identity. Unlike postMessage()/
     * postCustomMessage(), the response to this flow never contains a
     * message timestamp, so the returned value is the Slack file ID,
     * which the caller can use in its place to prevent double
     * posting.
     */
    public function postPdfFile(
        string $channelId,
        string $filename,
        string $pdfContent,
        string $introductionComment,
        string $username,
        string $iconUrl,
    ): string {
        [$uploadUrl, $fileId] = $this->requestUploadUrl(
            $filename,
            strlen($pdfContent),
        );
        $this->uploadFileContent($uploadUrl, $pdfContent);

        return $this->completeUpload(
            $fileId,
            $filename,
            $channelId,
            $introductionComment,
            $username,
            $iconUrl,
        );
    }

    /**
     * @return array{0: string, 1: string} 0: upload_url, 1: file_id
     */
    private function requestUploadUrl(string $filename, int $length): array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                self::UPLOAD_URL_ENDPOINT,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->botToken,
                    ],
                    'multipart' => [
                        ['name' => 'filename', 'contents' => $filename],
                        [
                            'name' => 'length',
                            'contents' => (string) $length,
                        ],
                    ],
                    'http_errors' => true,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
            );
        } catch (GuzzleException) {
            throw new RuntimeException('Slack file upload request failed.');
        }

        $payload = $this->decodeSuccessfulResponse($response);
        $uploadUrl = $payload['upload_url'] ?? null;
        $fileId = $payload['file_id'] ?? null;

        if (
            !is_string($uploadUrl)
            || $uploadUrl === ''
            || !is_string($fileId)
            || $fileId === ''
        ) {
            throw new RuntimeException('Slack API response was invalid.');
        }

        return [$uploadUrl, $fileId];
    }

    private function uploadFileContent(
        string $uploadUrl,
        string $pdfContent,
    ): void {
        try {
            $response = $this->httpClient->request(
                'POST',
                $uploadUrl,
                [
                    'headers' => [
                        'Content-Type' => 'application/octet-stream',
                    ],
                    'body' => $pdfContent,
                    'http_errors' => true,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
            );
        } catch (GuzzleException) {
            throw new RuntimeException('Slack file upload failed.');
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('Slack file upload failed.');
        }
    }

    private function completeUpload(
        string $fileId,
        string $title,
        string $channelId,
        string $introductionComment,
        string $username,
        string $iconUrl,
    ): string {
        try {
            $filesParameter = json_encode(
                [['id' => $fileId, 'title' => $title]],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new RuntimeException('Slack file share failed.');
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                self::COMPLETE_UPLOAD_ENDPOINT,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->botToken,
                    ],
                    'multipart' => [
                        [
                            'name' => 'files',
                            'contents' => $filesParameter,
                        ],
                        [
                            'name' => 'channel_id',
                            'contents' => $channelId,
                        ],
                        [
                            'name' => 'initial_comment',
                            'contents' => $introductionComment,
                        ],
                        ['name' => 'username', 'contents' => $username],
                        ['name' => 'icon_url', 'contents' => $iconUrl],
                    ],
                    'http_errors' => true,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
            );
        } catch (GuzzleException) {
            throw new RuntimeException('Slack file share failed.');
        }

        $payload = $this->decodeSuccessfulResponse($response);
        $files = $payload['files'] ?? null;
        $sharedFileId = is_array($files) ? ($files[0]['id'] ?? null) : null;

        if (!is_string($sharedFileId) || $sharedFileId === '') {
            throw new RuntimeException('Slack API response was invalid.');
        }

        return $sharedFileId;
    }

    /**
     * @param array<string, string> $payload
     */
    private function send(array $payload): string
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                self::ENDPOINT,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->botToken,
                    ],
                    'json' => $payload,
                    'http_errors' => true,
                    'timeout' => self::TIMEOUT_SECONDS,
                ],
            );
        } catch (GuzzleException) {
            throw new RuntimeException('Slack request failed.');
        }

        $responsePayload = $this->decodeSuccessfulResponse($response);
        $timestamp = $responsePayload['ts'] ?? null;

        if (!is_string($timestamp) || $timestamp === '') {
            throw new RuntimeException('Slack API response was invalid.');
        }

        return $timestamp;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSuccessfulResponse(
        ResponseInterface $response,
    ): array {
        try {
            $responsePayload = json_decode(
                (string) $response->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new RuntimeException('Slack API response was invalid.');
        }

        if (
            !is_array($responsePayload)
            || ($responsePayload['ok'] ?? false) !== true
        ) {
            throw new RuntimeException('Slack API request failed.');
        }

        return $responsePayload;
    }
}

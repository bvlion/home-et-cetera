<?php

declare(strict_types=1);

namespace Bvlion\CadenceXs\Tests;

use Bvlion\CadenceXs\Mail\HtmlToPdfConverter;
use FontLib\Font;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HtmlToPdfConverterTest extends TestCase
{
    public function testConvertsHtmlToPdfBinary(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><p>Example HTML body.</p></body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testConvertsNestedTableCellContentAcrossPages(): void
    {
        $paragraph = '<p>Example body content that must remain within the '
            . 'visible PDF page area.</p>';
        $html = '<html><body><p>Example preheader.</p>'
            . '<table width="1200"><tr><td>Header sibling content.</td></tr>'
            . '<tr><td><table><tr><td>'
            . '<table><tr><td>' . str_repeat($paragraph, 180)
            . '<table><tr><td>First ranking</td>'
            . '<td>Second ranking</td></tr></table>'
            . '</td></tr></table></td></tr></table></td></tr>'
            . '<tr><td>Footer sibling content.</td></tr></table>'
            . '</body></html>';

        $pdf = (new HtmlToPdfConverter())->convert($html);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThanOrEqual(
            3,
            preg_match_all('/\/Type\s*\/Page\b/', $pdf),
        );
        preg_match_all(
            '/\/Filter \/FlateDecode.*?stream\r?\n(.*?)\r?\nendstream/s',
            $pdf,
            $compressedStreams,
        );
        $decodedStreams = '';

        foreach ($compressedStreams[1] as $compressedStream) {
            $decodedStream = gzuncompress($compressedStream);

            if (is_string($decodedStream)) {
                $decodedStreams .= $decodedStream;
            }
        }

        self::assertStringContainsString(
            mb_convert_encoding('First ranking', 'UTF-16BE', 'UTF-8'),
            $decodedStreams,
        );
        self::assertStringContainsString(
            mb_convert_encoding('Second ranking', 'UTF-16BE', 'UTF-8'),
            $decodedStreams,
        );
        self::assertStringContainsString(
            mb_convert_encoding('Header sibling content.', 'UTF-16BE', 'UTF-8'),
            $decodedStreams,
        );
        self::assertStringContainsString(
            mb_convert_encoding('Footer sibling content.', 'UTF-16BE', 'UTF-8'),
            $decodedStreams,
        );
    }

    /**
     * Verifies the Japanese text is actually rendered with a
     * genuine, glyph-bearing CJK font, not merely that a PDF was
     * produced. A regression here (e.g. the font failing to
     * register, or Dompdf falling back to a core font) would
     * otherwise still pass a "starts with %PDF-" check while
     * rendering the Japanese text as blank or garbled glyphs.
     */
    public function testConvertsHtmlWithJapaneseText(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><p>架空のメール本文です。</p></body></html>',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('IPAexGothic', $pdf);
        // TrueType CID embedding (CIDFontType2/FontFile2), not the
        // CFF/OpenType flavor (CIDFontType0/FontFile3): dompdf's font
        // embedding only reliably supports the former, confirmed by
        // manually rasterizing PDFs from both during development (see
        // PR description for that manual check).
        self::assertStringContainsString('/Subtype /CIDFontType2', $pdf);
        self::assertStringNotContainsString('/Subtype /CIDFontType0', $pdf);

        $font = $this->loadEmbeddedFontFile2($pdf);

        self::assertSame('TrueType', $font->getFontType());
        self::assertGreaterThan(0, $font->getData('head', 'unitsPerEm'));
        // More than 1 confirms actual Japanese glyphs were subsetted
        // in beyond the mandatory .notdef glyph (index 0).
        self::assertGreaterThan(1, $font->getData('maxp', 'numGlyphs'));
    }

    /**
     * Dompdf does not fall back through a font stack per glyph the
     * way a browser does, so a mail's own font-family must be
     * overridden outright rather than merely supplemented with a
     * Japanese fallback.
     */
    public function testEmailsOwnFontFamilyDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><head><style>body{font-family:Arial,sans-serif;}'
            . '</style></head><body>'
            . '<p style="font-family:Helvetica, sans-serif">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    /**
     * A mail's own `!important` (or a highly specific selector) on
     * font-family cannot be beaten by an injected CSS override rule
     * of ours, since author-origin `!important` declarations are
     * compared to each other by specificity/source order, not
     * automatically beaten by another `!important` rule. This is why
     * HtmlToPdfConverter does not try to win that cascade at all: it
     * instead makes every font name Dompdf can resolve (see
     * HtmlToPdfConverter::OVERRIDDEN_FONT_FAMILIES) resolve to the
     * Japanese font, so whichever family name wins the cascade is
     * irrelevant. These three cases mirror the ones raised in review.
     */
    public function testImportantInlineFontFamilyDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
            . '<p style="font-family:Helvetica !important">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    public function testImportantIdSelectorFontFamilyDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><head><style>#message{font-family:Arial !important;}'
            . '</style></head><body><p id="message">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    public function testImportantInlineFontShorthandDoesNotOverrideJapaneseFont(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
            . '<p style="font: bold 14px Helvetica !important">'
            . '架空のメール本文です。</p></body></html>',
        );

        self::assertJapaneseFontIsSelected($pdf);
    }

    private static function assertJapaneseFontIsSelected(string $pdf): void
    {
        self::assertStringContainsString('IPAexGothic', $pdf);
        self::assertStringNotContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/BaseFont /Arial', $pdf);
        self::assertStringNotContainsString('/Subtype /CIDFontType0', $pdf);
    }

    /**
     * Dompdf is known to use a large multiple of the input HTML's
     * size in memory while rendering; an oversized mail must fail
     * that single mail rather than being truncated and partially
     * rendered, or risking exhausting memory/time for the whole
     * batch. See HtmlToPdfConverter::MAX_HTML_BYTES for the exact
     * threshold and rationale.
     */
    public function testOversizedHtmlFailsWithoutExposingContent(): void
    {
        $secretMarker = 'EXAMPLE-SECRET-MAIL-CONTENT';
        $oversizedHtml = '<html><body><p>' . $secretMarker
            . str_repeat('a', 5_000_000) . '</p></body></html>';

        try {
            (new HtmlToPdfConverter())->convert($oversizedHtml);
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'HTML body exceeds the maximum size allowed for PDF '
                    . 'conversion.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                $secretMarker,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Inline data URIs are part of the same HTML string handed to
     * Dompdf, so they must count toward the size limit like any
     * other byte -- a mail cannot smuggle an oversized body past the
     * check just by moving the bulk of it into a data URI.
     */
    public function testDataUriCountsTowardHtmlSizeLimit(): void
    {
        $oversizedHtml = '<html><body><img src="data:image/png;base64,'
            . str_repeat('A', 5_000_000) . '"></body></html>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'HTML body exceeds the maximum size allowed for PDF '
                . 'conversion.',
        );

        (new HtmlToPdfConverter())->convert($oversizedHtml);
    }

    public function testHtmlNormalizationCannotBypassHtmlSizeLimit(): void
    {
        $prefix = '<html><body><!--';
        $suffix = '--><img src=""></body></html>';
        $html = $prefix
            . str_repeat(
                'a',
                HtmlToPdfConverter::MAX_HTML_BYTES
                    - strlen($prefix)
                    - strlen($suffix)
                    - 1,
            )
            . $suffix;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'HTML body exceeds the maximum size allowed for PDF '
                . 'conversion.',
        );

        (new HtmlToPdfConverter())->convert($html);
    }

    public function testKeepsExplicitHeightForImageWithinPageWidth(): void
    {
        $imageContent = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
            . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $html = '<html><body>'
            . str_repeat(
                '<p><img src="data:image/png;base64,' . $imageContent
                    . '" width="1" height="100"></p>',
                10,
            )
            . '</body></html>';

        $pdf = (new HtmlToPdfConverter())->convert($html);

        self::assertGreaterThanOrEqual(
            2,
            preg_match_all('/\/Type\s*\/Page\b/', $pdf),
        );
    }

    public function testEmbedsContentIdImageInPdf(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><img src="cid:logo%40example.test">'
                . '<p>Example body.</p></body></html>',
            [
                'logo@example.test' => [
                    'content_type' => 'image/png',
                    'content' => $imageContent,
                ],
            ],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function testFetchesAndEmbedsPublicHttpImageInPdf(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(
                200,
                [
                    'Content-Type' => 'image/png',
                    'Content-Length' => (string) strlen($imageContent),
                ],
                $imageContent,
            ),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/logo.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
            static function (string $host): array {
                self::assertSame('images.example.test', $host);

                return ['93.184.216.34'];
            },
        );

        self::assertCount(1, $requestHistory);
        self::assertFalse(
            $requestHistory[0]['options']['allow_redirects'],
        );
        self::assertSame(
            3,
            $requestHistory[0]['options']['connect_timeout'],
        );
        self::assertLessThanOrEqual(
            10,
            $requestHistory[0]['options']['timeout'],
        );
        self::assertArrayHasKey(
            CURLOPT_RESOLVE,
            $requestHistory[0]['options']['curl'],
        );
        self::assertSame(
            ['http', 'https'],
            $requestHistory[0]['options']['protocols'],
        );
        self::assertArrayNotHasKey(
            CURLOPT_PROTOCOLS,
            $requestHistory[0]['options']['curl'],
        );
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function testCurlResolveWithSeparateAddressEntriesUsesOnlyLastAddress(): void
    {
        $server = proc_open(
            [
                PHP_BINARY,
                '-r',
                <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
if (!is_resource($server)) {
    exit(1);
}

fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
$connection = stream_socket_accept($server, 10);

if (is_resource($connection)) {
    $request = '';

    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($connection, 8192);

        if ($chunk === '' || $chunk === false) {
            break;
        }

        $request .= $chunk;
    }

    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
    fclose($connection);
}

fclose($server);
PHP,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($server);
        fclose($pipes[0]);
        $serverAddress = fgets($pipes[1]);
        self::assertIsString($serverAddress);
        $port = (int) substr(strrchr(trim($serverAddress), ':'), 1);
        self::assertGreaterThan(0, $port);

        try {
            $client = new Client();

            try {
                $client->request(
                    'GET',
                    "http://images.example.test:{$port}/image",
                    [
                        'connect_timeout' => 1,
                        'proxy' => '',
                        'curl' => [
                            CURLOPT_RESOLVE => [
                                "images.example.test:{$port}:127.0.0.1",
                                "images.example.test:{$port}:[::1]",
                            ],
                        ],
                    ],
                );
                self::fail('ConnectException was not thrown.');
            } catch (ConnectException $exception) {
                self::assertStringContainsString(
                    'cURL error 7',
                    $exception->getMessage(),
                );
            }

            $response = $client->request(
                'GET',
                "http://images.example.test:{$port}/image",
                [
                    'connect_timeout' => 1,
                    'proxy' => '',
                    'curl' => [
                        CURLOPT_RESOLVE => [
                            "images.example.test:{$port}:127.0.0.1,[::1]",
                        ],
                    ],
                ],
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('OK', (string) $response->getBody());
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($server);
        }
    }

    public function testPassesResolvedAddressesInSingleCurlResolveEntry(): void
    {
        $requestOptions = [];
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);

        (new HtmlToPdfConverter())->convert(
            '<html><body><img src="https://images.example.test/logo.png">'
                . '</body></html>',
            [],
            new Client([
                'handler' => static function (
                    mixed $request,
                    array $options,
                ) use (
                    &$requestOptions,
                    $imageContent,
                ) {
                    $requestOptions = $options;

                    return Create::promiseFor(new Response(
                        200,
                        ['Content-Type' => 'image/png'],
                        $imageContent,
                    ));
                },
            ]),
            static fn (string $host): array => [
                '93.184.216.34',
                '2606:4700:4700::1111',
            ],
        );

        self::assertSame(
            [
                'images.example.test:443:93.184.216.34,'
                    . '[2606:4700:4700::1111]',
            ],
            $requestOptions['curl'][CURLOPT_RESOLVE],
        );
    }

    public function testGuzzleCurlHandlerRejectsProtocolCurlOption(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/Passing CURLOPT_PROTOCOLS.*"curl" request option.*'
                . 'Guzzle-managed request handling.*"protocols" request '
                . 'option/',
        );

        (new Client())->request(
            'GET',
            'https://images.example.test/logo.png',
            [
                'curl' => [
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
            ],
        );
    }

    public function testFetchesImageWithCurlResolveAndGuzzleProtocols(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/logo.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client([
                'handler' => static function (
                    mixed $request,
                    array $options,
                ) use ($imageContent) {
                    if (
                        ($options['stream'] ?? false)
                        && array_key_exists('curl', $options)
                    ) {
                        throw new InvalidArgumentException(
                            'Passing the "curl" request option to the stream '
                                . 'handler is not supported because the stream '
                                . 'handler ignores cURL options.',
                        );
                    }

                    self::assertArrayNotHasKey('stream', $options);
                    self::assertArrayHasKey(CURLOPT_RESOLVE, $options['curl']);
                    self::assertArrayNotHasKey(
                        CURLOPT_PROTOCOLS,
                        $options['curl'],
                    );
                    self::assertSame(['http', 'https'], $options['protocols']);

                    return Create::promiseFor(new Response(
                        200,
                        ['Content-Type' => 'image/png'],
                        $imageContent,
                    ));
                },
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function testCliConvertsHtmlFileToPdf(): void
    {
        $htmlPath = tempnam(sys_get_temp_dir(), 'bvlion-html-to-pdf-test-');
        $pdfPath = tempnam(sys_get_temp_dir(), 'bvlion-html-to-pdf-test-');
        self::assertIsString($htmlPath);
        self::assertIsString($pdfPath);
        @unlink($pdfPath);

        try {
            $html = '<html><body><img src="data:image/png;base64,'
                . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="'
                . '><p>Example body.</p></body></html>';
            self::assertNotFalse(file_put_contents($htmlPath, $html));

            $process = proc_open(
                [
                    PHP_BINARY,
                    dirname(__DIR__) . '/bin/convert-html-to-pdf.php',
                    $htmlPath,
                    $pdfPath,
                ],
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                dirname(__DIR__),
            );
            self::assertIsResource($process);

            $standardOutput = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $standardError = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            self::assertSame(0, $exitCode);
            self::assertSame('', $standardError);
            self::assertSame("PDF written: {$pdfPath}\n", $standardOutput);
            $pdf = file_get_contents($pdfPath);
            self::assertIsString($pdf);
            self::assertStringStartsWith('%PDF-', $pdf);
        } finally {
            @unlink($htmlPath);
            @unlink($pdfPath);
        }
    }

    public function testRejectsImageWhenContentLengthIsMissingOrUnderstated(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);
        $requestOptions = [];
        $responseIndex = 0;

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/missing-length.png">'
                . '<img src="https://images.example.test/short-length.png">'
                . '</body></html>',
            [],
            new Client([
                'handler' => static function (
                    mixed $request,
                    array $options,
                ) use (
                    &$requestOptions,
                    &$responseIndex,
                    $imageContent
                ) {
                    $requestOptions[] = $options;
                    $responseIndex++;
                    $progress = $options['progress'] ?? null;

                    if (is_callable($progress)) {
                        $progress(
                            0,
                            HtmlToPdfConverter::MAX_IMAGE_BYTES + 1,
                        );
                    }

                    return Create::promiseFor(new Response(
                        200,
                        $responseIndex === 1
                            ? ['Content-Type' => 'image/png']
                            : [
                                'Content-Type' => 'image/png',
                                'Content-Length' => '1',
                            ],
                        $imageContent,
                    ));
                },
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertCount(2, $requestOptions);

        foreach ($requestOptions as $options) {
            self::assertArrayHasKey('progress', $options);
        }

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testRejectsImageWhenTotalImageSizeLimitIsExceededDuringDownload(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);
        $inlineImageContent = $imageContent . str_repeat(
            "\0",
            1_500_000 - strlen($imageContent),
        );
        $requestOptions = [];

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><img src="cid:first%40example.test">'
                . '<img src="cid:second%40example.test">'
                . '<img src="https://images.example.test/third.png">'
                . '</body></html>',
            [
                'first@example.test' => [
                    'content_type' => 'image/png',
                    'content' => $inlineImageContent,
                ],
                'second@example.test' => [
                    'content_type' => 'image/png',
                    'content' => $inlineImageContent,
                ],
            ],
            new Client([
                'handler' => static function (
                    mixed $request,
                    array $options,
                ) use (
                    &$requestOptions,
                    $imageContent
                ) {
                    $requestOptions = $options;
                    $progress = $options['progress'] ?? null;
                    $remainingImageBytes = HtmlToPdfConverter::MAX_TOTAL_IMAGE_BYTES
                        - 3_000_000;

                    if (is_callable($progress)) {
                        self::assertFalse($progress(0, $remainingImageBytes));
                        self::assertTrue($progress(0, $remainingImageBytes + 1));
                    }

                    return Create::promiseFor(new Response(
                        200,
                        [
                            'Content-Type' => 'image/png',
                            'Content-Length' => '1',
                        ],
                        $imageContent,
                    ));
                },
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertArrayHasKey('progress', $requestOptions);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRejectsPrivateAddressWithoutHttpRequest(): void
    {
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'image/png'], 'unused'),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body><img src="http://127.0.0.1/private.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
        );

        self::assertCount(0, $requestHistory);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testRejectsHostnameResolvingToNonPublicAddress(): void
    {
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'image/png'], 'unused'),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/private.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
            static fn (string $host): array => ['100.64.0.1'],
        );

        self::assertCount(0, $requestHistory);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testRejectsPrivateRedirectTarget(): void
    {
        $requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(
                302,
                ['Location' => 'http://169.254.169.254/example.png'],
            ),
            new Response(200, ['Content-Type' => 'image/png'], 'unused'),
        ]));
        $handlerStack->push(Middleware::history($requestHistory));

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/redirect.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client(['handler' => $handlerStack]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertCount(1, $requestHistory);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testImageFetchFailureDoesNotFailConversion(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/missing.png">'
                . '<p>Example body with a missing image.</p>'
                . '</body></html>',
            [],
            new Client([
                'handler' => new MockHandler([
                    new Response(503, ['Content-Type' => 'image/png']),
                ]),
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function testFailedImageAlternativeTextIsIncludedWithoutLaterSuccess(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/missing.png" '
                . 'alt="Unavailable image">'
                . '</body></html>',
            [],
            new Client([
                'handler' => new MockHandler([
                    new Response(503, ['Content-Type' => 'image/png']),
                ]),
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        preg_match_all(
            '/\/Filter \/FlateDecode.*?stream\r?\n(.*?)\r?\nendstream/s',
            $pdf,
            $compressedStreams,
        );
        $decodedStreams = '';

        foreach ($compressedStreams[1] as $compressedStream) {
            $decodedStream = gzuncompress($compressedStream);

            if (is_string($decodedStream)) {
                $decodedStreams .= $decodedStream;
            }
        }

        self::assertStringContainsString(
            mb_convert_encoding('Unavailable image', 'UTF-16BE', 'UTF-8'),
            $decodedStreams,
        );
    }

    public function testPreservesDataUriImageWhenHttpImageFailsBeforeLaterSuccess(): void
    {
        $pngImageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        $gifImageContent = base64_decode(
            'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==',
            true,
        );
        self::assertIsString($pngImageContent);
        self::assertIsString($gifImageContent);

        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="data:image/gif;base64,'
                . base64_encode($gifImageContent) . '">'
                . '<img src="https://images.example.test/missing.png">'
                . '<img src="https://images.example.test/success.png">'
                . '</body></html>',
            [],
            new Client([
                'handler' => new MockHandler([
                    new Response(503, ['Content-Type' => 'image/png']),
                    new Response(
                        200,
                        ['Content-Type' => 'image/png'],
                        $pngImageContent,
                    ),
                ]),
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThanOrEqual(
            2,
            substr_count($pdf, '/Subtype /Image'),
        );
    }

    public function testRejectsOversizedImageFromContentLength(): void
    {
        $pdf = (new HtmlToPdfConverter())->convert(
            '<html><body>'
                . '<img src="https://images.example.test/large.png">'
                . '<p>Example body.</p></body></html>',
            [],
            new Client([
                'handler' => new MockHandler([
                    new Response(
                        200,
                        [
                            'Content-Type' => 'image/png',
                            'Content-Length' => (string) (
                                HtmlToPdfConverter::MAX_IMAGE_BYTES + 1
                            ),
                        ],
                        'unused',
                    ),
                ]),
            ]),
            static fn (string $host): array => ['93.184.216.34'],
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    /**
     * Extracts the PDF's embedded FontFile2 stream (the CID-keyed
     * TrueType font program dompdf embeds for CJK text) and parses
     * it with php-font-lib, the same library dompdf itself uses to
     * process fonts. This is only valid for the single-page, single
     * embedded font PDFs this converter produces in these tests.
     */
    private function loadEmbeddedFontFile2(string $pdf): \FontLib\TrueType\File
    {
        self::assertMatchesRegularExpression(
            '/\/FontFile2 (\d+) 0 R/',
            $pdf,
            'PDF does not reference an embedded FontFile2 stream.',
        );
        preg_match('/\/FontFile2 (\d+) 0 R/', $pdf, $referenceMatch);
        $objectNumber = $referenceMatch[1];

        $found = preg_match(
            '/' . preg_quote($objectNumber, '/')
                . ' 0 obj\s*<<(.*?)>>\s*stream\r?\n/s',
            $pdf,
            $objectMatch,
            PREG_OFFSET_CAPTURE,
        );
        self::assertSame(
            1,
            $found,
            'Font stream object was not found in the PDF.',
        );

        $dictionary = $objectMatch[1][0];
        $streamStart = $objectMatch[0][1] + strlen($objectMatch[0][0]);

        self::assertMatchesRegularExpression(
            '/\/Length (\d+)/',
            $dictionary,
        );
        preg_match('/\/Length (\d+)/', $dictionary, $lengthMatch);
        $rawStream = substr($pdf, $streamStart, (int) $lengthMatch[1]);

        if (str_contains($dictionary, '/FlateDecode')) {
            $rawStream = gzuncompress($rawStream);
            self::assertIsString(
                $rawStream,
                'Embedded font stream failed to inflate.',
            );
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'bvlion-font-test-');
        self::assertIsString($tempPath);

        try {
            file_put_contents($tempPath, $rawStream);
            $font = Font::load($tempPath);
        } finally {
            @unlink($tempPath);
        }

        self::assertInstanceOf(\FontLib\TrueType\File::class, $font);

        return $font;
    }
}

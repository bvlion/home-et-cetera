<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use DOMDocument;
use DOMElement;
use Dompdf\Dompdf;
use Dompdf\Options;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;
use Throwable;

final class HtmlToPdfConverter
{
    /**
     * Bundled under resources/fonts/IPAexGothic (IPA Font License
     * Agreement v1.0, see the license file there), instead of
     * relying on XServer's installed fonts. Registered under
     * OVERRIDDEN_FONT_FAMILIES below so it is the only font Dompdf
     * can ever select, regardless of what the mail's own font-family
     * declares: Dompdf, unlike a browser, does not fall back to a
     * different font per glyph when the selected font is missing
     * characters, so letting an unregistered or non-CJK font win
     * would silently render Japanese text as blank glyphs.
     *
     * TrueType (.ttf), not OpenType/CFF (.otf), is required here:
     * dompdf/php-font-lib's CID font embedding only reliably embeds
     * the `glyf`-outline flavor. A CFF-outline .otf (e.g. Noto Sans
     * JP as distributed by Google Fonts / Noto CJK) registers and
     * "renders" without error, but produces a corrupt embedded font
     * program that most PDF viewers fail to parse, showing garbled
     * glyphs instead of the Japanese text.
     */
    private const FONT_FAMILY = 'IPAex Gothic';
    private const FONT_DIRECTORY = __DIR__
        . '/../../resources/fonts/IPAexGothic';
    private const FONT_PATH = self::FONT_DIRECTORY . '/ipaexg.ttf';

    /**
     * Dompdf is known to use a large multiple of the input HTML's
     * size in memory while rendering, and this HTML (including any
     * inline data URI images, which count toward this the same as
     * any other byte) comes from an untrusted mail. Actual memory
     * usage depends heavily on the HTML's structure and embedded
     * images, and the true PHP memory_limit on XServer is not
     * confirmed, so this is not a value that guarantees safety --
     * it is an operational upper bound chosen to reduce the risk of
     * a single oversized mail exhausting memory or the request time
     * limit for the whole batch. A mail over this limit is treated
     * as a conversion failure for that mail only, not truncated and
     * partially rendered.
     *
     * MimeMessageDecoder enforces this same limit earlier, while
     * extracting the HTML body from the mail, so that an oversized
     * part is rejected before it is even fully fetched/decoded
     * in memory. The check here is a last-resort backstop for
     * whatever HTML this method is ever called with directly.
     */
    public const MAX_HTML_BYTES = 5_000_000;
    public const MAX_IMAGE_BYTES = 2_000_000;
    public const MAX_TOTAL_IMAGE_BYTES = 4_000_000;
    private const TABLE_CELL_LAYOUT_NORMALIZATION_TEXT_LENGTH = 2_000;
    private const IMAGE_CONNECT_TIMEOUT_SECONDS = 3;
    private const IMAGE_TIMEOUT_SECONDS = 10;
    private const MAX_IMAGE_REDIRECTS = 3;

    /**
     * Every font family name Dompdf itself ships in
     * lib/fonts/installed-fonts.dist.json. A mail's own CSS cannot
     * make Dompdf select a non-Japanese font by using `!important`
     * or a more specific selector, because these are the only family
     * names Dompdf's FontMetrics::getFont() can ever resolve to
     * something other than Options::defaultFont() (itself set to
     * FONT_FAMILY below) -- overriding all of them, in addition to
     * FONT_FAMILY, removes every non-Japanese font Dompdf could
     * possibly pick. Any other family name a mail requests simply
     * fails to resolve and falls through to defaultFont(), per
     * Css\Style::_get_font_family(). This is verified by
     * HtmlToPdfConverterTest against Dompdf's actual source
     * (vendor/dompdf/dompdf), not merely by observed behavior.
     */
    private const OVERRIDDEN_FONT_FAMILIES = [
        self::FONT_FAMILY,
        'sans-serif',
        'serif',
        'monospace',
        'fixed',
        'times',
        'times-roman',
        'courier',
        'helvetica',
        'symbol',
        'zapfdingbats',
        'dejavu sans',
        'dejavu sans mono',
        'dejavu serif',
    ];

    /**
     * @param array<string, array{content_type: string, content: string}>
     *        $inlineImages Content-IDをキーとするinline画像です。
     * @param callable(string): list<string>|null $hostResolver
     * @param array<string, int|string|null> $mailLogContext
     *        メール単位でPDF変換を追跡するためのログ情報です。
     */
    public function convert(
        string $html,
        array $inlineImages = [],
        ?ClientInterface $httpClient = null,
        ?callable $hostResolver = null,
        array $mailLogContext = [],
    ): string {
        $writeLog = static function (array $entry) use ($mailLogContext): void {
            if ($mailLogContext === []) {
                return;
            }

            $message = json_encode(
                $mailLogContext + $entry,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE,
            );

            if (is_string($message)) {
                error_log($message);
            }
        };

        $writeLog([
            'event' => 'pdf_conversion_started',
            'html_bytes' => strlen($html),
        ]);

        if (strlen($html) > self::MAX_HTML_BYTES) {
            $writeLog([
                'event' => 'pdf_conversion_failed',
                'failure_reason' => 'html_size_limit',
            ]);

            throw new RuntimeException(
                'HTML body exceeds the maximum size allowed for PDF '
                    . 'conversion.',
            );
        }

        $allowedContentTypes = [
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
        ];
        $document = new DOMDocument();
        $previousInternalErrors = libxml_use_internal_errors(true);

        try {
            $isLoaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);
        }

        if ($isLoaded) {
            $writeLog([
                'event' => 'pdf_image_processing_started',
                'image_count' => $document->getElementsByTagName('img')->length,
            ]);

            foreach (iterator_to_array($document->childNodes) as $childNode) {
                if ($childNode->nodeType === XML_PI_NODE) {
                    $document->removeChild($childNode);
                }
            }

            // Dompdf cannot split a table cell across pages. Normalize the
            // table structure around a large body while retaining nested
            // tables that are not part of that structure, such as ranking
            // rows and columns.
            $layoutElements = [];

            foreach (iterator_to_array($document->getElementsByTagName('td')) as $tableCell) {
                if (
                    !$tableCell instanceof DOMElement
                    || mb_strlen(trim($tableCell->textContent))
                        < self::TABLE_CELL_LAYOUT_NORMALIZATION_TEXT_LENGTH
                ) {
                    continue;
                }

                $ancestor = $tableCell;
                $outerTable = null;
                $tableAncestors = [];

                while ($ancestor instanceof DOMElement) {
                    if (strtolower($ancestor->tagName) === 'table') {
                        $outerTable = $ancestor;
                        $tableAncestors[] = $ancestor;
                    }

                    $ancestor = $ancestor->parentNode;
                }

                if (!$outerTable instanceof DOMElement) {
                    continue;
                }

                foreach (
                    array_merge(
                        [$outerTable],
                        iterator_to_array($outerTable->getElementsByTagName('*')),
                    ) as $element
                ) {
                    if (
                        !$element instanceof DOMElement
                        || !in_array(
                            strtolower($element->tagName),
                            ['table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th'],
                            true,
                        )
                    ) {
                        continue;
                    }

                    $elementAncestor = $element;

                    while ($elementAncestor instanceof DOMElement) {
                        if (strtolower($elementAncestor->tagName) === 'table') {
                            $isTableAncestor = false;

                            foreach ($tableAncestors as $tableAncestor) {
                                if ($elementAncestor->isSameNode($tableAncestor)) {
                                    $isTableAncestor = true;
                                    break;
                                }
                            }

                            if (!$isTableAncestor) {
                                continue 2;
                            }
                        }

                        if ($elementAncestor->isSameNode($outerTable)) {
                            break;
                        }

                        $elementAncestor = $elementAncestor->parentNode;
                    }

                    $isLayoutElement = false;

                    foreach ($layoutElements as $layoutElement) {
                        if ($element->isSameNode($layoutElement)) {
                            $isLayoutElement = true;
                            break;
                        }
                    }

                    if (!$isLayoutElement) {
                        $layoutElements[] = $element;
                    }
                }
            }

            foreach (array_reverse($layoutElements) as $element) {
                $replacement = $document->createElement('div');

                foreach (iterator_to_array($element->attributes) as $attribute) {
                    if (!$attribute instanceof \DOMAttr) {
                        continue;
                    }

                    $replacement->setAttribute(
                        $attribute->name,
                        $attribute->value,
                    );
                }

                $layoutStyle = trim($replacement->getAttribute('style'));
                $replacement->setAttribute(
                    'style',
                    $layoutStyle
                        . ($layoutStyle === '' ? '' : '; ')
                        . 'display: block !important; '
                        . 'width: auto !important; '
                        . 'max-width: 100% !important; '
                        . 'word-wrap: break-word !important;',
                );

                while ($element->firstChild !== null) {
                    $replacement->appendChild($element->firstChild);
                }

                $element->parentNode?->replaceChild(
                    $replacement,
                    $element,
                );
            }

            foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
                if (!$image instanceof DOMElement) {
                    continue;
                }

                $layoutStyle = trim($image->getAttribute('style'));
                $image->setAttribute(
                    'style',
                    $layoutStyle
                        . ($layoutStyle === '' ? '' : '; ')
                        . 'max-width: 100% !important;',
                );
            }

            $convertedHtml = $document->saveHTML();

            if (
                !is_string($convertedHtml)
                || strlen($convertedHtml) > self::MAX_HTML_BYTES
            ) {
                $writeLog([
                    'event' => 'pdf_conversion_failed',
                    'failure_reason' => 'html_size_limit',
                ]);

                throw new RuntimeException(
                    'HTML body exceeds the maximum size allowed for PDF '
                        . 'conversion.',
                );
            }

            $html = $convertedHtml;

            /** @var array<string, string|null> $resolvedImages */
            $resolvedImages = [];
            $totalImageBytes = 0;
            $imageIndex = 0;

            foreach (iterator_to_array($document->getElementsByTagName('img')) as $image) {
                if (!$image instanceof DOMElement) {
                    continue;
                }

                $imageIndex++;

                $source = trim($image->getAttribute('src'));

                if ($source === '') {
                    $writeLog([
                        'event' => 'pdf_image_processed',
                        'image_index' => $imageIndex,
                        'source_type' => 'empty',
                        'result' => 'failed',
                        'failure_reason' => 'empty_source',
                    ]);

                    continue;
                }

                $dataUri = null;
                $imageLogEntry = null;
                $resourceKey = $source;
                $lowerSource = strtolower($source);

                if (str_starts_with($lowerSource, 'cid:')) {
                    $contentId = strtolower(trim(
                        rawurldecode(substr($source, 4)),
                        "<> \t\r\n",
                    ));
                    $resourceKey = 'cid:' . $contentId;

                    if (array_key_exists($resourceKey, $resolvedImages)) {
                        $dataUri = $resolvedImages[$resourceKey];
                        $imageLogEntry = [
                            'event' => 'pdf_image_processed',
                            'image_index' => $imageIndex,
                            'src' => $source,
                            'source_type' => 'cid',
                            'result' => is_string($dataUri)
                                ? 'embedded_from_cache'
                                : 'failed_from_cache',
                        ];
                    } else {
                        $inlineImage = $inlineImages[$contentId] ?? null;
                        $contentType = is_array($inlineImage)
                            ? ($inlineImage['content_type'] ?? null)
                            : null;
                        $content = is_array($inlineImage)
                            ? ($inlineImage['content'] ?? null)
                            : null;
                        $imageInformation = is_string($content)
                            ? @getimagesizefromstring($content)
                            : false;
                        $actualContentType = is_array($imageInformation)
                            ? ($imageInformation['mime'] ?? null)
                            : null;

                        if (
                            is_string($contentType)
                            && in_array(
                                $contentType,
                                $allowedContentTypes,
                                true,
                            )
                            && is_string($content)
                            && strlen($content) <= self::MAX_IMAGE_BYTES
                            && $totalImageBytes + strlen($content)
                                <= self::MAX_TOTAL_IMAGE_BYTES
                            && $actualContentType === $contentType
                        ) {
                            $dataUri = 'data:' . $contentType . ';base64,'
                                . base64_encode($content);
                            $totalImageBytes += strlen($content);
                        }

                        $resolvedImages[$resourceKey] = $dataUri;
                        $failureReason = null;

                        if (!is_string($dataUri)) {
                            if (!is_string($content)) {
                                $failureReason = 'inline_image_not_found';
                            } elseif (
                                !is_string($contentType)
                                || !in_array(
                                    $contentType,
                                    $allowedContentTypes,
                                    true,
                                )
                            ) {
                                $failureReason = 'unsupported_declared_content_type';
                            } elseif (strlen($content) > self::MAX_IMAGE_BYTES) {
                                $failureReason = 'image_size_limit';
                            } elseif (
                                $totalImageBytes + strlen($content)
                                > self::MAX_TOTAL_IMAGE_BYTES
                            ) {
                                $failureReason = 'total_image_size_limit';
                            } elseif ($actualContentType !== $contentType) {
                                $failureReason = 'declared_actual_mime_mismatch';
                            }
                        }

                        $imageLogEntry = [
                            'event' => 'pdf_image_processed',
                            'image_index' => $imageIndex,
                            'src' => $source,
                            'source_type' => 'cid',
                            'content_id' => $contentId,
                            'declared_content_type' => $contentType,
                            'actual_content_type' => $actualContentType,
                            'bytes' => is_string($content) ? strlen($content) : null,
                            'result' => is_string($dataUri)
                                ? 'embedded'
                                : 'failed',
                            'failure_reason' => $failureReason,
                        ];
                    }
                } elseif (
                    str_starts_with($lowerSource, 'http://')
                    || str_starts_with($lowerSource, 'https://')
                ) {
                    if (array_key_exists($resourceKey, $resolvedImages)) {
                        $dataUri = $resolvedImages[$resourceKey];
                        $imageLogEntry = [
                            'event' => 'pdf_image_processed',
                            'image_index' => $imageIndex,
                            'src' => $source,
                            'source_type' => 'http',
                            'result' => is_string($dataUri)
                                ? 'embedded_from_cache'
                                : 'failed_from_cache',
                        ];
                    } else {
                        $currentUrl = $source;
                        $deadline = microtime(true)
                            + self::IMAGE_TIMEOUT_SECONDS;
                        $redirectCount = 0;
                        $failureReason = null;
                        $exception = null;
                        $addresses = [];
                        $actualContentType = null;
                        $fetchedBytes = null;
                        $maximumReadableBytes = 0;
                        $receivedBytes = 0;
                        $isSizeLimitExceeded = false;
                        $httpClient ??= new Client();

                        try {
                            while (true) {
                                $currentUri = new Uri($currentUrl);
                                $scheme = strtolower(
                                    $currentUri->getScheme(),
                                );
                                $host = $currentUri->getHost();
                                $resolvedHost = strtolower(trim($host, '[]'));

                                if (
                                    !in_array(
                                        $scheme,
                                        ['http', 'https'],
                                        true,
                                    )
                                    || $resolvedHost === ''
                                    || $currentUri->getUserInfo() !== ''
                                ) {
                                    $failureReason = 'invalid_url';
                                    break;
                                }

                                $port = $currentUri->getPort()
                                    !== null
                                    ? $currentUri->getPort()
                                    : ($scheme === 'https' ? 443 : 80);

                                if ($port < 1 || $port > 65535) {
                                    $failureReason = 'invalid_port';
                                    break;
                                }

                                $isLiteralIp = filter_var(
                                    $resolvedHost,
                                    FILTER_VALIDATE_IP,
                                ) !== false;

                                if ($isLiteralIp) {
                                    $addresses = [$resolvedHost];
                                } elseif (
                                    filter_var(
                                        $resolvedHost,
                                        FILTER_VALIDATE_DOMAIN,
                                        FILTER_FLAG_HOSTNAME,
                                    ) !== false
                                ) {
                                    if ($hostResolver !== null) {
                                        $addresses = $hostResolver(
                                            $resolvedHost,
                                        );
                                    } else {
                                        $records = @dns_get_record(
                                            $resolvedHost,
                                            DNS_A | DNS_AAAA,
                                        );
                                        $addresses = [];

                                        if (is_array($records)) {
                                            foreach ($records as $record) {
                                                $address = $record['ip']
                                                    ?? $record['ipv6']
                                                    ?? null;

                                                if (is_string($address)) {
                                                    $addresses[] = $address;
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    $failureReason = 'invalid_host';
                                    break;
                                }

                                if (!is_array($addresses)) {
                                    $failureReason = 'dns_resolution_failed';
                                    break;
                                }

                                $addresses = array_values(array_unique(
                                    $addresses,
                                ));
                                $isPublicAddressSet = $addresses !== [];

                                foreach ($addresses as $address) {
                                    $packedAddress = is_string($address)
                                        ? @inet_pton($address)
                                        : false;
                                    $isMulticastAddress = is_string(
                                        $packedAddress,
                                    ) && (
                                        (
                                            strlen($packedAddress) === 4
                                            && ord($packedAddress[0]) >= 224
                                        )
                                        || (
                                            strlen($packedAddress) === 16
                                            && ord($packedAddress[0]) === 255
                                        )
                                    );
                                    $isSpecialTranslationAddress = is_string(
                                        $packedAddress,
                                    ) && strlen($packedAddress) === 16 && (
                                        str_starts_with(
                                            $packedAddress,
                                            substr(
                                                (string) inet_pton(
                                                    '64:ff9b::',
                                                ),
                                                0,
                                                12,
                                            ),
                                        )
                                        || str_starts_with(
                                            $packedAddress,
                                            substr(
                                                (string) inet_pton(
                                                    '64:ff9b:1::',
                                                ),
                                                0,
                                                6,
                                            ),
                                        )
                                    );
                                    $isDeprecatedRelayAddress = is_string(
                                        $packedAddress,
                                    ) && strlen($packedAddress) === 4
                                        && substr($packedAddress, 0, 3)
                                            === substr(
                                                (string) inet_pton(
                                                    '192.88.99.0',
                                                ),
                                                0,
                                                3,
                                            );

                                    if (
                                        !is_string($address)
                                        || filter_var(
                                            $address,
                                            FILTER_VALIDATE_IP,
                                            FILTER_FLAG_GLOBAL_RANGE,
                                        ) === false
                                        || $isMulticastAddress
                                        || $isSpecialTranslationAddress
                                        || $isDeprecatedRelayAddress
                                    ) {
                                        $isPublicAddressSet = false;
                                        break;
                                    }
                                }

                                if (!$isPublicAddressSet) {
                                    $failureReason = 'non_public_address';
                                    break;
                                }

                                $remainingSeconds = $deadline
                                    - microtime(true);

                                if ($remainingSeconds <= 0) {
                                    $failureReason = 'timeout';
                                    break;
                                }

                                $maximumReadableBytes = min(
                                    self::MAX_IMAGE_BYTES,
                                    self::MAX_TOTAL_IMAGE_BYTES
                                        - $totalImageBytes,
                                );

                                if ($maximumReadableBytes <= 0) {
                                    $failureReason = 'total_image_size_limit';
                                    break;
                                }

                                $curlOptions = [];

                                if (!$isLiteralIp) {
                                    $resolveAddresses = [];

                                    foreach ($addresses as $address) {
                                        $resolveAddresses[] =
                                            str_contains($address, ':')
                                                ? '[' . $address . ']'
                                                : $address;
                                    }

                                    $curlOptions[CURLOPT_RESOLVE]
                                        = [sprintf(
                                            '%s:%d:%s',
                                            $resolvedHost,
                                            $port,
                                            implode(',', $resolveAddresses),
                                        )];
                                }

                                $response = $httpClient->request(
                                    'GET',
                                    $currentUri,
                                    [
                                        'allow_redirects' => false,
                                        'connect_timeout' => min(
                                            self::IMAGE_CONNECT_TIMEOUT_SECONDS,
                                            $remainingSeconds,
                                        ),
                                        'timeout' => $remainingSeconds,
                                        'http_errors' => false,
                                        'decode_content' => false,
                                        'proxy' => '',
                                        'protocols' => ['http', 'https'],
                                        'progress' => static function (
                                            $downloadTotal,
                                            $downloaded,
                                        ) use (
                                            &$receivedBytes,
                                            &$isSizeLimitExceeded,
                                            $maximumReadableBytes,
                                        ): bool {
                                            $receivedBytes = $downloaded;

                                            if ($downloaded > $maximumReadableBytes) {
                                                $isSizeLimitExceeded = true;

                                                return true;
                                            }

                                            return false;
                                        },
                                        'headers' => [
                                            'Accept' => implode(
                                                ', ',
                                                $allowedContentTypes,
                                            ),
                                            'Accept-Encoding' => 'identity',
                                        ],
                                        'curl' => $curlOptions,
                                    ],
                                );
                                $statusCode = $response->getStatusCode();
                                $responseContentType = $response->getHeaderLine(
                                    'Content-Type',
                                );
                                $responseContentLength = $response->getHeaderLine(
                                    'Content-Length',
                                );

                                $writeLog([
                                    'event' => 'pdf_image_response_received',
                                    'image_index' => $imageIndex,
                                    'src' => $source,
                                    'source_type' => 'http',
                                    'resolved_ips' => $addresses,
                                    'request_url' => $currentUrl,
                                    'status' => $statusCode,
                                    'response_content_type' => $responseContentType,
                                    'response_content_length' => $responseContentLength,
                                ]);

                                if ($isSizeLimitExceeded) {
                                    $failureReason = $maximumReadableBytes
                                        < self::MAX_IMAGE_BYTES
                                        ? 'total_image_size_limit'
                                        : 'image_size_limit';
                                    $fetchedBytes = $receivedBytes;
                                    break;
                                }

                                if (
                                    in_array(
                                        $statusCode,
                                        [301, 302, 303, 307, 308],
                                        true,
                                    )
                                ) {
                                    $location = trim(
                                        $response->getHeaderLine('Location'),
                                    );

                                    if (
                                        $location === ''
                                        || $redirectCount
                                            >= self::MAX_IMAGE_REDIRECTS
                                    ) {
                                        $failureReason = $location === ''
                                            ? 'missing_redirect_location'
                                            : 'redirect_limit';
                                        break;
                                    }

                                    $currentUrl = (string) UriResolver::resolve(
                                        new Uri($currentUrl),
                                        new Uri($location),
                                    );
                                    $writeLog([
                                        'event' => 'pdf_image_redirect',
                                        'image_index' => $imageIndex,
                                        'src' => $source,
                                        'source_type' => 'http',
                                        'redirect_url' => $currentUrl,
                                        'status' => $statusCode,
                                    ]);
                                    $redirectCount++;
                                    continue;
                                }

                                if ($statusCode !== 200) {
                                    $failureReason = 'http_status';
                                    break;
                                }

                                $contentType = strtolower(trim(explode(
                                    ';',
                                    $response->getHeaderLine('Content-Type'),
                                    2,
                                )[0]));

                                if (
                                    !in_array(
                                        $contentType,
                                        $allowedContentTypes,
                                        true,
                                    )
                                ) {
                                    $failureReason = 'unsupported_content_type';
                                    break;
                                }

                                $contentLength = trim(
                                    $response->getHeaderLine(
                                        'Content-Length',
                                    ),
                                );

                                if (
                                    $contentLength !== ''
                                    && (
                                        !ctype_digit($contentLength)
                                        || (int) $contentLength
                                            > $maximumReadableBytes
                                    )
                                ) {
                                    $failureReason = 'image_size_limit';
                                    break;
                                }

                                $content = '';
                                $responseBody = $response->getBody();
                                $isComplete = true;

                                while (!$responseBody->eof()) {
                                    $chunk = $responseBody->read(min(
                                        8192,
                                        $maximumReadableBytes
                                            - strlen($content) + 1,
                                    ));

                                    if ($chunk === '') {
                                        $isComplete = false;
                                        break;
                                    }

                                    $content .= $chunk;

                                    if (
                                        strlen($content)
                                        > $maximumReadableBytes
                                    ) {
                                        $isComplete = false;
                                        break;
                                    }
                                }

                                $imageInformation = $isComplete
                                    ? @getimagesizefromstring($content)
                                    : false;
                                $actualContentType = is_array(
                                    $imageInformation,
                                ) ? ($imageInformation['mime'] ?? null) : null;
                                $fetchedBytes = strlen($content);

                                if ($actualContentType !== $contentType) {
                                    $failureReason = $isComplete
                                        ? 'declared_actual_mime_mismatch'
                                        : 'image_size_limit';
                                    break;
                                }

                                $dataUri = 'data:' . $contentType . ';base64,'
                                    . base64_encode($content);
                                $totalImageBytes += strlen($content);
                                break;
                            }
                        } catch (Throwable $throwable) {
                            $dataUri = null;
                            $failureReason = $isSizeLimitExceeded
                                ? ($maximumReadableBytes < self::MAX_IMAGE_BYTES
                                    ? 'total_image_size_limit'
                                    : 'image_size_limit')
                                : 'exception';
                            $fetchedBytes = $isSizeLimitExceeded
                                ? $receivedBytes
                                : null;
                            $exception = $throwable;
                        }

                        $resolvedImages[$resourceKey] = $dataUri;
                        $imageLogEntry = [
                            'event' => 'pdf_image_processed',
                            'image_index' => $imageIndex,
                            'src' => $source,
                            'source_type' => 'http',
                            'request_url' => $currentUrl,
                            'resolved_ips' => $addresses,
                            'actual_content_type' => $actualContentType,
                            'bytes' => $fetchedBytes,
                            'result' => is_string($dataUri)
                                ? 'embedded'
                                : 'failed',
                            'failure_reason' => $failureReason,
                            'exception_class' => $exception !== null
                                ? $exception::class
                                : null,
                            'exception_message' => $exception?->getMessage(),
                        ];
                    }
                } else {
                    $isDataUri = str_starts_with($lowerSource, 'data:');

                    $writeLog([
                        'event' => 'pdf_image_processed',
                        'image_index' => $imageIndex,
                        'src' => $isDataUri ? null : $source,
                        'source_type' => $isDataUri ? 'data' : 'other',
                        'result' => 'not_processed',
                    ]);
                }

                if (!is_string($dataUri)) {
                    if (is_array($imageLogEntry)) {
                        $writeLog($imageLogEntry);

                        $alternativeText = $image->getAttribute('alt');

                        if ($alternativeText !== '') {
                            $image->parentNode?->replaceChild(
                                $document->createTextNode($alternativeText),
                                $image,
                            );
                        } else {
                            $image->parentNode?->removeChild($image);
                        }

                        $convertedHtml = $document->saveHTML();

                        if (is_string($convertedHtml)) {
                            $html = $convertedHtml;
                        }
                    }

                    continue;
                }

                $originalSource = $image->getAttribute('src');
                $image->setAttribute('src', $dataUri);
                $convertedHtml = $document->saveHTML();

                if (
                    !is_string($convertedHtml)
                    || strlen($convertedHtml) > self::MAX_HTML_BYTES
                ) {
                    $image->setAttribute('src', $originalSource);
                    if (is_array($imageLogEntry)) {
                        $imageLogEntry['result'] = 'failed';
                        $imageLogEntry['failure_reason'] = 'data_uri_html_size_limit';
                        $writeLog($imageLogEntry);
                    }

                    $alternativeText = $image->getAttribute('alt');

                    if ($alternativeText !== '') {
                        $image->parentNode?->replaceChild(
                            $document->createTextNode($alternativeText),
                            $image,
                        );
                    } else {
                        $image->parentNode?->removeChild($image);
                    }

                    $convertedHtml = $document->saveHTML();

                    if (is_string($convertedHtml)) {
                        $html = $convertedHtml;
                    }

                    continue;
                }

                $html = $convertedHtml;

                if (is_array($imageLogEntry)) {
                    $writeLog($imageLogEntry);
                }
            }
        }

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont(self::FONT_FAMILY);
        // Dompdf's local-file access is restricted to its own chroot
        // (its package directory by default), so the bundled font
        // directory must be added explicitly or registerFont() below
        // fails silently and PDFs fall back to a non-CJK core font.
        $options->setChroot([self::FONT_DIRECTORY]);

        $dompdf = new Dompdf($options);

        try {
            $this->registerJapaneseFont($dompdf);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdf = $dompdf->output();
        } catch (Throwable $throwable) {
            $writeLog([
                'event' => 'pdf_conversion_failed',
                'failure_reason' => 'dompdf_exception',
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
            ]);

            throw new RuntimeException('HTML to PDF conversion failed.');
        }

        if (!is_string($pdf) || $pdf === '') {
            $writeLog([
                'event' => 'pdf_conversion_failed',
                'failure_reason' => 'empty_pdf',
            ]);

            throw new RuntimeException('HTML to PDF conversion failed.');
        }

        $writeLog([
            'event' => 'pdf_conversion_completed',
            'pdf_bytes' => strlen($pdf),
        ]);

        return $pdf;
    }

    /**
     * IPAex Gothic ships a single (regular) weight, so the same
     * physical font also stands in for bold/italic variants: Dompdf
     * will render them without a true bold/italic outline, but the
     * Japanese text stays readable, which is this feature's only
     * goal (see the FONT_FAMILY docblock).
     *
     * FontMetrics::registerFont() writes a fresh copy of the font
     * file (plus metrics) to disk for every distinct (family, style)
     * pair it is called with, even when the source file is
     * identical. Registering FONT_PATH once under FONT_FAMILY's
     * normal style is therefore the only real registerFont() call;
     * every other name/style combination in OVERRIDDEN_FONT_FAMILIES
     * is set up as an alias pointing at that same registered path
     * via setFontFamily(), which only updates FontMetrics' in-memory
     * and cached lookup table, not the font file itself. Calling
     * registerFont() once per name (14 families x 4 styles = 56
     * calls) would instead duplicate the ~6MB font up to 56 times on
     * first use.
     */
    private function registerJapaneseFont(Dompdf $dompdf): void
    {
        $fontMetrics = $dompdf->getFontMetrics();

        $fontMetrics->registerFont(
            [
                'family' => self::FONT_FAMILY,
                'weight' => 'normal',
                'style' => 'normal',
            ],
            self::FONT_PATH,
        );

        $registeredFontPath = $fontMetrics->getFontFamilies()
            [mb_strtolower(self::FONT_FAMILY, 'UTF-8')]['normal']
            ?? null;

        if (!is_string($registeredFontPath)) {
            throw new RuntimeException(
                'Japanese font registration failed.',
            );
        }

        $aliasedStyles = [
            'normal' => $registeredFontPath,
            'bold' => $registeredFontPath,
            'italic' => $registeredFontPath,
            'bold_italic' => $registeredFontPath,
        ];

        foreach (self::OVERRIDDEN_FONT_FAMILIES as $family) {
            $fontMetrics->setFontFamily($family, $aliasedStyles);
        }
    }
}

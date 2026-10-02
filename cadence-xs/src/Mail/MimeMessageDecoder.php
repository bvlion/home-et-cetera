<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use RuntimeException;
use stdClass;
use ValueError;

final class MimeMessageDecoder
{
    private const MAX_BODY_LENGTH = 4000;

    /**
     * Upper bound for an HTML part while it is still in its
     * transfer-encoded form (base64 or quoted-printable), checked
     * against both the IMAP-declared part size and the raw fetched
     * body, before any decoding is attempted -- so an oversized part
     * is rejected without ever calling the (potentially expensive)
     * body fetcher, and without base64_decode()/
     * quoted_printable_decode() ever running on it. Sized well above
     * HtmlToPdfConverter::MAX_HTML_BYTES to comfortably cover
     * base64's exact 4/3 inflation and quoted-printable's much less
     * predictable (worst case roughly 3x) inflation; it does not
     * need to precisely predict the final decoded size, because the
     * decoded/UTF-8-converted result is independently re-checked
     * against HtmlToPdfConverter::MAX_HTML_BYTES afterwards.
     */
    private const MAX_ENCODED_HTML_BYTES = HtmlToPdfConverter::MAX_HTML_BYTES
        * 4;
    private const MAX_ENCODED_IMAGE_BYTES = HtmlToPdfConverter::MAX_IMAGE_BYTES
        * 4;

    public function decodeSubject(string $encodedSubject): string
    {
        $elements = imap_mime_header_decode($encodedSubject);

        if ($elements === false) {
            return '';
        }

        $subject = '';

        foreach ($elements as $element) {
            $text = (string) ($element->text ?? '');
            $charset = strtoupper((string) ($element->charset ?? ''));

            if (
                $charset === ''
                || $charset === 'DEFAULT'
                || $charset === 'US-ASCII'
                || $charset === 'UTF-8'
            ) {
                $subject .= mb_scrub($text, 'UTF-8');
                continue;
            }

            try {
                $subject .= mb_convert_encoding(
                    $text,
                    'UTF-8',
                    $charset,
                );
            } catch (ValueError) {
                continue;
            }
        }

        return $subject;
    }

    /**
     * @param callable(string): (string|false) $bodyFetcher
     *        IMAPのsection番号（例: 1、1.2）を受け取ります。
     */
    public function decodeBody(
        stdClass $structure,
        callable $bodyFetcher,
    ): string {
        $body = $this->findBodyBySubtype(
            $structure,
            (int) ($structure->type ?? -1) === TYPEMULTIPART ? '' : '1',
            $bodyFetcher,
            'PLAIN',
        );

        if ($body === null) {
            return '';
        }

        return mb_substr($body, 0, self::MAX_BODY_LENGTH, 'UTF-8');
    }

    /**
     * @param callable(string): (string|false) $bodyFetcher
     *        IMAPのsection番号（例: 1、1.2）を受け取ります。
     * @param array<string, array{content_type: string, content: string}>|null
     *        $inlineImages Content-IDをキーとするinline画像です。
     */
    public function decodeHtmlBody(
        stdClass $structure,
        callable $bodyFetcher,
        ?array &$inlineImages = null,
    ): string {
        $totalInlineImageBytes = 0;
        $body = $this->findBodyBySubtype(
            $structure,
            (int) ($structure->type ?? -1) === TYPEMULTIPART ? '' : '1',
            $bodyFetcher,
            'HTML',
            self::MAX_ENCODED_HTML_BYTES,
            HtmlToPdfConverter::MAX_HTML_BYTES,
            $inlineImages,
            $totalInlineImageBytes,
        );

        return $body ?? '';
    }

    /**
     * @param callable(string): (string|false) $bodyFetcher
     * @param int|null $maxEncodedBytes Checked against the
     *        IMAP-declared part size and the raw (still
     *        transfer-encoded) fetched body. Null (used for
     *        decodeBody()'s plain-text search) disables this check.
     * @param int|null $maxDecodedBytes Checked against the body
     *        after transfer decoding, and again after UTF-8
     *        conversion. Null disables this check.
     * @param array<string, array{content_type: string, content: string}>|null
     *        $inlineImages Content-IDをキーとするinline画像です。
     * @param bool $shouldFindBody falseの場合は画像だけを探索します。
     */
    private function findBodyBySubtype(
        stdClass $part,
        string $partNumber,
        callable $bodyFetcher,
        string $subtype,
        ?int $maxEncodedBytes = null,
        ?int $maxDecodedBytes = null,
        ?array &$inlineImages = null,
        ?int &$totalInlineImageBytes = null,
        bool $shouldFindBody = true,
    ): ?string {
        $partType = (int) ($part->type ?? -1);
        $disposition = strtoupper((string) ($part->disposition ?? ''));

        if (
            $inlineImages !== null
            && $partType === TYPEIMAGE
            && $disposition !== 'ATTACHMENT'
        ) {
            $contentId = strtolower(trim(
                (string) ($part->id ?? ''),
                "<> \t\r\n",
            ));
            $contentTypes = [
                'GIF' => 'image/gif',
                'JPEG' => 'image/jpeg',
                'JPG' => 'image/jpeg',
                'PNG' => 'image/png',
                'WEBP' => 'image/webp',
            ];
            $contentType = $contentTypes[
                strtoupper((string) ($part->subtype ?? ''))
            ] ?? null;
            $declaredBytes = $part->bytes ?? null;

            if (
                $contentId === ''
                || $contentType === null
                || isset($inlineImages[$contentId])
                || !is_numeric($declaredBytes)
                || (int) $declaredBytes > self::MAX_ENCODED_IMAGE_BYTES
            ) {
                return null;
            }

            $content = $bodyFetcher($partNumber);

            if (
                $content === false
                || strlen($content) > self::MAX_ENCODED_IMAGE_BYTES
            ) {
                return null;
            }

            $encoding = (int) ($part->encoding ?? ENC7BIT);

            if ($encoding === ENCBASE64) {
                $decodedContent = base64_decode($content, true);

                if ($decodedContent === false) {
                    return null;
                }

                $content = $decodedContent;
            } elseif ($encoding === ENCQUOTEDPRINTABLE) {
                $content = quoted_printable_decode($content);
            }

            $imageInformation = @getimagesizefromstring($content);
            $actualContentType = is_array($imageInformation)
                ? ($imageInformation['mime'] ?? null)
                : null;

            if (
                strlen($content) > HtmlToPdfConverter::MAX_IMAGE_BYTES
                || ($totalInlineImageBytes ?? 0) + strlen($content)
                    > HtmlToPdfConverter::MAX_TOTAL_IMAGE_BYTES
                || $actualContentType !== $contentType
            ) {
                return null;
            }

            $inlineImages[$contentId] = [
                'content_type' => $contentType,
                'content' => $content,
            ];
            $totalInlineImageBytes = ($totalInlineImageBytes ?? 0)
                + strlen($content);

            return null;
        }

        $isAttachment = strtoupper(
            (string) ($part->disposition ?? ''),
        ) === 'ATTACHMENT';

        foreach (
            [
                $part->parameters ?? [],
                $part->dparameters ?? [],
            ] as $parameters
        ) {
            if (!is_array($parameters)) {
                continue;
            }

            foreach ($parameters as $parameter) {
                $attribute = strtolower(
                    (string) ($parameter->attribute ?? ''),
                );

                if ($attribute === 'filename' || $attribute === 'name') {
                    $isAttachment = true;
                    break 2;
                }
            }
        }

        if ($isAttachment) {
            return null;
        }

        if ($partType === TYPEMULTIPART) {
            $parts = $part->parts ?? [];

            if (!is_array($parts)) {
                return null;
            }

            $foundBody = null;

            foreach ($parts as $index => $childPart) {
                if (!$childPart instanceof stdClass) {
                    continue;
                }

                $childPartNumber = $partNumber === ''
                    ? (string) ($index + 1)
                    : $partNumber . '.' . ($index + 1);
                $body = $this->findBodyBySubtype(
                    $childPart,
                    $childPartNumber,
                    $bodyFetcher,
                    $subtype,
                    $maxEncodedBytes,
                    $maxDecodedBytes,
                    $inlineImages,
                    $totalInlineImageBytes,
                    $foundBody === null,
                );

                if ($body !== null && $foundBody === null) {
                    $foundBody = $body;

                    if ($inlineImages === null) {
                        return $foundBody;
                    }
                }
            }

            return $foundBody;
        }

        if (!$shouldFindBody) {
            return null;
        }

        if (
            $partType !== TYPETEXT
            || strtoupper((string) ($part->subtype ?? '')) !== $subtype
        ) {
            return null;
        }

        if ($maxEncodedBytes !== null) {
            $this->assertDeclaredSizeWithinLimit(
                $part->bytes ?? null,
                $maxEncodedBytes,
            );
        }

        $body = $bodyFetcher($partNumber);

        if ($body === false) {
            throw new RuntimeException('IMAP message body fetch failed.');
        }

        if ($maxEncodedBytes !== null && strlen($body) > $maxEncodedBytes) {
            throw new RuntimeException(
                'Mail part exceeds the maximum allowed size before '
                    . 'decoding.',
            );
        }

        $encoding = (int) ($part->encoding ?? ENC7BIT);

        if ($encoding === ENCBASE64) {
            $decodedBody = base64_decode($body, true);

            if ($decodedBody === false) {
                return null;
            }

            $body = $decodedBody;
        } elseif ($encoding === ENCQUOTEDPRINTABLE) {
            $body = quoted_printable_decode($body);
        }

        if ($maxDecodedBytes !== null && strlen($body) > $maxDecodedBytes) {
            throw new RuntimeException(
                'Mail part exceeds the maximum allowed size after '
                    . 'decoding.',
            );
        }

        $charset = 'UTF-8';
        $parameters = $part->parameters ?? [];

        if (is_array($parameters)) {
            foreach ($parameters as $parameter) {
                if (
                    strtolower(
                        (string) ($parameter->attribute ?? ''),
                    ) === 'charset'
                ) {
                    $charset = (string) ($parameter->value ?? 'UTF-8');
                    break;
                }
            }
        }

        $normalizedCharset = strtoupper($charset);

        if (
            $normalizedCharset === ''
            || $normalizedCharset === 'DEFAULT'
            || $normalizedCharset === 'US-ASCII'
            || $normalizedCharset === 'UTF-8'
        ) {
            $convertedBody = mb_scrub($body, 'UTF-8');
        } else {
            try {
                $convertedBody = mb_convert_encoding(
                    $body,
                    'UTF-8',
                    $charset,
                );
            } catch (ValueError) {
                return null;
            }
        }

        if (
            $maxDecodedBytes !== null
            && strlen($convertedBody) > $maxDecodedBytes
        ) {
            throw new RuntimeException(
                'Mail part exceeds the maximum allowed size after '
                    . 'decoding.',
            );
        }

        return $convertedBody;
    }

    /**
     * Per RFC 3501's body-fields grammar, body-fld-octets (the IMAP
     * server's declared byte size for a text/* part, exposed by PHP
     * as $part->bytes) is a mandatory, always-numeric field for
     * every such part -- it is never NIL. A missing or non-numeric
     * value therefore indicates a non-compliant server response
     * rather than a legitimately size-less part, so it is rejected
     * here rather than treated as "no declared size, fetch anyway".
     */
    private function assertDeclaredSizeWithinLimit(
        mixed $declaredBytes,
        int $maxEncodedBytes,
    ): void {
        if (
            !is_numeric($declaredBytes)
            || (int) $declaredBytes > $maxEncodedBytes
        ) {
            throw new RuntimeException(
                'Mail part exceeds the maximum allowed size before '
                    . 'decoding.',
            );
        }
    }
}

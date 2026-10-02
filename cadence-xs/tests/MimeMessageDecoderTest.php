<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\HtmlToPdfConverter;
use BvlionBatch5\Mail\MimeMessageDecoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MimeMessageDecoderTest extends TestCase
{
    public function testDecodesMimeEncodedSubject(): void
    {
        $encodedSubject = '=?UTF-8?B?'
            . base64_encode('架空の件名')
            . '?=';

        self::assertSame(
            '架空の件名',
            (new MimeMessageDecoder())->decodeSubject($encodedSubject),
        );
    }

    public function testDecodesQuotedPrintablePlainText(): void
    {
        $sourceBody = mb_convert_encoding(
            'Example café body.',
            'ISO-8859-1',
            'UTF-8',
        );
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'PLAIN',
            'encoding' => ENCQUOTEDPRINTABLE,
            'parameters' => [
                (object) [
                    'attribute' => 'charset',
                    'value' => 'ISO-8859-1',
                ],
            ],
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static function (string $partNumber) use ($sourceBody): string {
                self::assertSame('1', $partNumber);

                return quoted_printable_encode($sourceBody);
            },
        );

        self::assertSame('Example café body.', $body);
    }

    public function testRecursivelyPrefersBase64PlainText(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPEMULTIPART,
                    'subtype' => 'ALTERNATIVE',
                    'parts' => [
                        (object) [
                            'type' => TYPETEXT,
                            'subtype' => 'HTML',
                            'encoding' => ENCQUOTEDPRINTABLE,
                        ],
                        (object) [
                            'type' => TYPETEXT,
                            'subtype' => 'PLAIN',
                            'encoding' => ENCBASE64,
                            'parameters' => [
                                (object) [
                                    'attribute' => 'charset',
                                    'value' => 'UTF-8',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $partBodies = [
            '1.1' => '<p>Example HTML body.</p>',
            '1.2' => base64_encode('Example plain body.'),
        ];
        $fetchedPartNumbers = [];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static function (string $partNumber) use (
                &$fetchedPartNumbers,
                $partBodies,
            ): string {
                self::assertArrayHasKey($partNumber, $partBodies);
                $fetchedPartNumbers[] = $partNumber;

                return $partBodies[$partNumber];
            },
        );

        self::assertSame('Example plain body.', $body);
        self::assertSame(['1.2'], $fetchedPartNumbers);
    }

    public function testHtmlOnlyReturnsEmptyBody(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENCQUOTEDPRINTABLE,
        ];
        $bodyFetcher = static function (string $partNumber): string {
            self::fail('HTML body must not be fetched.');
        };

        self::assertSame(
            '',
            (new MimeMessageDecoder())->decodeBody(
                $structure,
                $bodyFetcher,
            ),
        );
    }

    public function testBodyFetchFailureIsNotTreatedAsEmptyBody(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'PLAIN',
            'encoding' => ENC7BIT,
        ];
        $decoder = new MimeMessageDecoder();

        try {
            $decoder->decodeBody(
                $structure,
                static function (string $partNumber): false {
                    self::assertSame('1', $partNumber);

                    return false;
                },
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'IMAP message body fetch failed.',
                $exception->getMessage(),
            );
        }
    }

    public function testDeeplyNestedMultipartUsesImapSectionNumber(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'HTML',
                    'encoding' => ENC7BIT,
                ],
                (object) [
                    'type' => TYPEMULTIPART,
                    'subtype' => 'MIXED',
                    'parts' => [
                        (object) [
                            'type' => TYPEMULTIPART,
                            'subtype' => 'ALTERNATIVE',
                            'parts' => [
                                (object) [
                                    'type' => TYPETEXT,
                                    'subtype' => 'PLAIN',
                                    'encoding' => ENC7BIT,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static function (string $partNumber): string {
                self::assertSame('2.1.1', $partNumber);

                return 'Example deeply nested body.';
            },
        );

        self::assertSame('Example deeply nested body.', $body);
    }

    public function testTextAttachmentIsNotUsedAsBody(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'PLAIN',
                    'encoding' => ENCBASE64,
                    'disposition' => 'ATTACHMENT',
                    'dparameters' => [
                        (object) [
                            'attribute' => 'filename',
                            'value' => 'example.txt',
                        ],
                    ],
                ],
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'PLAIN',
                    'encoding' => ENC7BIT,
                ],
            ],
        ];
        $partBodies = [
            '1' => base64_encode('Example attachment content.'),
            '2' => 'Example message body.',
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static fn (string $partNumber): string|false =>
                $partBodies[$partNumber] ?? false,
        );

        self::assertSame('Example message body.', $body);
    }

    public function testBodyIsLimitedToFourThousandCharacters(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'PLAIN',
            'encoding' => ENC7BIT,
        ];

        $body = (new MimeMessageDecoder())->decodeBody(
            $structure,
            static fn (string $partNumber): string => str_repeat(
                'あ',
                5000,
            ),
        );

        self::assertSame(4000, mb_strlen($body, 'UTF-8'));
        self::assertSame(str_repeat('あ', 4000), $body);
    }

    public function testDecodesQuotedPrintableHtmlBody(): void
    {
        $sourceBody = mb_convert_encoding(
            '<p>Example café body.</p>',
            'ISO-8859-1',
            'UTF-8',
        );
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENCQUOTEDPRINTABLE,
            'bytes' => 100,
            'parameters' => [
                (object) [
                    'attribute' => 'charset',
                    'value' => 'ISO-8859-1',
                ],
            ],
        ];

        $body = (new MimeMessageDecoder())->decodeHtmlBody(
            $structure,
            static function (string $partNumber) use ($sourceBody): string {
                self::assertSame('1', $partNumber);

                return quoted_printable_encode($sourceBody);
            },
        );

        self::assertSame('<p>Example café body.</p>', $body);
    }

    public function testRecursivelyFindsHtmlBodyInMultipartAlternative(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPEMULTIPART,
                    'subtype' => 'ALTERNATIVE',
                    'parts' => [
                        (object) [
                            'type' => TYPETEXT,
                            'subtype' => 'PLAIN',
                            'encoding' => ENCBASE64,
                            'parameters' => [
                                (object) [
                                    'attribute' => 'charset',
                                    'value' => 'UTF-8',
                                ],
                            ],
                        ],
                        (object) [
                            'type' => TYPETEXT,
                            'subtype' => 'HTML',
                            'encoding' => ENCQUOTEDPRINTABLE,
                            'bytes' => 100,
                        ],
                    ],
                ],
            ],
        ];
        $partBodies = [
            '1.1' => base64_encode('Example plain body.'),
            '1.2' => '<p>Example HTML body.</p>',
        ];
        $fetchedPartNumbers = [];

        $body = (new MimeMessageDecoder())->decodeHtmlBody(
            $structure,
            static function (string $partNumber) use (
                &$fetchedPartNumbers,
                $partBodies,
            ): string {
                self::assertArrayHasKey($partNumber, $partBodies);
                $fetchedPartNumbers[] = $partNumber;

                return $partBodies[$partNumber];
            },
        );

        self::assertSame('<p>Example HTML body.</p>', $body);
        self::assertSame(['1.2'], $fetchedPartNumbers);
    }

    public function testDecodesContentIdInlineImageWithHtmlBody(): void
    {
        $imageContent = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
                . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($imageContent);
        $encodedImage = base64_encode($imageContent);
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'RELATED',
            'parts' => [
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'HTML',
                    'encoding' => ENC7BIT,
                    'bytes' => 100,
                ],
                (object) [
                    'type' => TYPEIMAGE,
                    'subtype' => 'PNG',
                    'encoding' => ENCBASE64,
                    'bytes' => strlen($encodedImage),
                    'disposition' => 'INLINE',
                    'id' => '<logo@example.test>',
                ],
            ],
        ];
        $partBodies = [
            '1' => '<p><img src="cid:logo@example.test"></p>',
            '2' => $encodedImage,
        ];
        $inlineImages = [];

        $body = (new MimeMessageDecoder())->decodeHtmlBody(
            $structure,
            static fn (string $partNumber): string|false =>
                $partBodies[$partNumber] ?? false,
            $inlineImages,
        );

        self::assertSame($partBodies['1'], $body);
        self::assertSame(
            [
                'logo@example.test' => [
                    'content_type' => 'image/png',
                    'content' => $imageContent,
                ],
            ],
            $inlineImages,
        );
    }

    public function testInvalidInlineImageDoesNotFailHtmlBodyDecoding(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'RELATED',
            'parts' => [
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'HTML',
                    'encoding' => ENC7BIT,
                    'bytes' => 100,
                ],
                (object) [
                    'type' => TYPEIMAGE,
                    'subtype' => 'PNG',
                    'encoding' => ENCBASE64,
                    'bytes' => 20,
                    'disposition' => 'INLINE',
                    'id' => '<invalid@example.test>',
                ],
            ],
        ];
        $inlineImages = [];

        $body = (new MimeMessageDecoder())->decodeHtmlBody(
            $structure,
            static fn (string $partNumber): string => match ($partNumber) {
                '1' => '<p>Example HTML body.</p>',
                '2' => 'invalid-base64',
            },
            $inlineImages,
        );

        self::assertSame('<p>Example HTML body.</p>', $body);
        self::assertSame([], $inlineImages);
    }

    public function testPlainOnlyReturnsEmptyHtmlBody(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'PLAIN',
            'encoding' => ENC7BIT,
        ];
        $bodyFetcher = static function (string $partNumber): string {
            self::fail('Plain text body must not be fetched.');
        };

        self::assertSame(
            '',
            (new MimeMessageDecoder())->decodeHtmlBody(
                $structure,
                $bodyFetcher,
            ),
        );
    }

    public function testHtmlAttachmentIsNotUsedAsBody(): void
    {
        $structure = (object) [
            'type' => TYPEMULTIPART,
            'subtype' => 'MIXED',
            'parts' => [
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'HTML',
                    'encoding' => ENCBASE64,
                    'disposition' => 'ATTACHMENT',
                    'dparameters' => [
                        (object) [
                            'attribute' => 'filename',
                            'value' => 'example.html',
                        ],
                    ],
                ],
                (object) [
                    'type' => TYPETEXT,
                    'subtype' => 'HTML',
                    'encoding' => ENC7BIT,
                    'bytes' => 100,
                ],
            ],
        ];
        $partBodies = [
            '1' => base64_encode('<p>Example attachment content.</p>'),
            '2' => '<p>Example message body.</p>',
        ];

        $body = (new MimeMessageDecoder())->decodeHtmlBody(
            $structure,
            static fn (string $partNumber): string|false =>
                $partBodies[$partNumber] ?? false,
        );

        self::assertSame('<p>Example message body.</p>', $body);
    }

    public function testHtmlBodyIsNotLimitedToFourThousandCharacters(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENC7BIT,
            'bytes' => 15000,
        ];

        $body = (new MimeMessageDecoder())->decodeHtmlBody(
            $structure,
            static fn (string $partNumber): string => str_repeat(
                'あ',
                5000,
            ),
        );

        self::assertSame(5000, mb_strlen($body, 'UTF-8'));
        self::assertSame(str_repeat('あ', 5000), $body);
    }

    public function testOversizedDeclaredBase64HtmlPartDoesNotCallBodyFetcher(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENCBASE64,
            'bytes' => (HtmlToPdfConverter::MAX_HTML_BYTES * 4) + 1,
        ];
        $bodyFetcher = static function (string $partNumber): string {
            self::fail(
                'bodyFetcher must not be called for an oversized '
                    . 'declared part size.',
            );
        };

        try {
            (new MimeMessageDecoder())->decodeHtmlBody(
                $structure,
                $bodyFetcher,
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Mail part exceeds the maximum allowed size before '
                    . 'decoding.',
                $exception->getMessage(),
            );
        }
    }

    public function testOversizedDeclaredQuotedPrintableHtmlPartDoesNotCallBodyFetcher(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENCQUOTEDPRINTABLE,
            'bytes' => (HtmlToPdfConverter::MAX_HTML_BYTES * 4) + 1,
        ];
        $bodyFetcher = static function (string $partNumber): string {
            self::fail(
                'bodyFetcher must not be called for an oversized '
                    . 'declared part size.',
            );
        };

        try {
            (new MimeMessageDecoder())->decodeHtmlBody(
                $structure,
                $bodyFetcher,
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Mail part exceeds the maximum allowed size before '
                    . 'decoding.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * Per RFC 3501, body-fld-octets (surfaced by PHP as $part->bytes)
     * is a mandatory field for every text/* part, so a missing value
     * indicates a non-compliant response rather than a legitimately
     * size-less part. This must not be treated as "no known size,
     * fetch anyway".
     */
    public function testMissingDeclaredSizeDoesNotCallBodyFetcher(): void
    {
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENC7BIT,
        ];
        $bodyFetcher = static function (string $partNumber): string {
            self::fail(
                'bodyFetcher must not be called when the declared '
                    . 'size is missing.',
            );
        };

        try {
            (new MimeMessageDecoder())->decodeHtmlBody(
                $structure,
                $bodyFetcher,
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Mail part exceeds the maximum allowed size before '
                    . 'decoding.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * The IMAP-declared size understates the real fetched size here,
     * so this specifically exercises the second checkpoint (the raw
     * fetched body itself), independent of the first (the declared
     * part->bytes checked before the fetch).
     */
    public function testFetchedBodyExceedingEncodedLimitFailsBeforeDecoding(): void
    {
        $oversizedRawBody = str_repeat(
            'a',
            (HtmlToPdfConverter::MAX_HTML_BYTES * 4) + 1,
        );
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENC7BIT,
            'bytes' => 100,
        ];

        try {
            (new MimeMessageDecoder())->decodeHtmlBody(
                $structure,
                static fn (string $partNumber): string => $oversizedRawBody,
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Mail part exceeds the maximum allowed size before '
                    . 'decoding.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * A base64 body whose encoded size fits comfortably within the
     * transfer-encoded limit can still decode to something over the
     * final UTF-8 limit; this must be rejected right after decoding,
     * before charset conversion is even attempted, without exposing
     * the mail's content in the exception.
     */
    public function testDecodedBodyExceedingFinalLimitFailsAfterDecoding(): void
    {
        $secretMarker = 'EXAMPLE-SECRET-HTML-CONTENT';
        $oversizedDecodedBody = '<p>' . $secretMarker
            . str_repeat('a', HtmlToPdfConverter::MAX_HTML_BYTES)
            . '</p>';
        $encodedBody = base64_encode($oversizedDecodedBody);
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENCBASE64,
            'bytes' => strlen($encodedBody),
        ];

        try {
            (new MimeMessageDecoder())->decodeHtmlBody(
                $structure,
                static fn (string $partNumber): string => $encodedBody,
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Mail part exceeds the maximum allowed size after '
                    . 'decoding.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString(
                $secretMarker,
                $exception->getMessage(),
            );
        }
    }

    /**
     * Converting from a legacy charset to UTF-8 can grow the byte
     * count (e.g. ISO-8859-1's non-ASCII bytes each become 2 UTF-8
     * bytes), so a body that fits the final limit right after
     * transfer-decoding can still exceed it once converted. This
     * must be caught after conversion, not only after decoding.
     */
    public function testCharsetConversionExceedingFinalLimitFailsAfterConversion(): void
    {
        $rawIso88591Body = str_repeat("\xe9", 3_000_000);
        $structure = (object) [
            'type' => TYPETEXT,
            'subtype' => 'HTML',
            'encoding' => ENC7BIT,
            'bytes' => strlen($rawIso88591Body),
            'parameters' => [
                (object) [
                    'attribute' => 'charset',
                    'value' => 'ISO-8859-1',
                ],
            ],
        ];

        try {
            (new MimeMessageDecoder())->decodeHtmlBody(
                $structure,
                static fn (string $partNumber): string => $rawIso88591Body,
            );
            self::fail('RuntimeException was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Mail part exceeds the maximum allowed size after '
                    . 'decoding.',
                $exception->getMessage(),
            );
        }
    }
}

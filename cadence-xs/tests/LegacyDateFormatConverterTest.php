<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Mail\LegacyDateFormatConverter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyDateFormatConverterTest extends TestCase
{
    private function exampleDate(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-03-05 07:09:02',
            new DateTimeZone('Asia/Tokyo'),
        );
    }

    public function testConvertsYearMonthDayHourMinute(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '2026/03/05 07:09',
            $converter->format($this->exampleDate(), 'yyyy/MM/dd HH:mm'),
        );
    }

    public function testPreservesFixedLiteralText(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '受信 2026-03-05',
            $converter->format($this->exampleDate(), "'受信 'yyyy-MM-dd"),
        );
    }

    public function testUnquotedNonLetterCharactersArePreservedLiterally(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '[2026-03-05 07:09] ',
            $converter->format(
                $this->exampleDate(),
                '[yyyy-MM-dd HH:mm] ',
            ),
        );
    }

    public function testDoubledSingleQuoteProducesALiteralQuote(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            "07'09",
            $converter->format($this->exampleDate(), "HH''mm"),
        );
    }

    public function testFormatsInAsiaTokyoRegardlessOfSourceTimezone(): void
    {
        $converter = new LegacyDateFormatConverter();
        $utcDate = new DateTimeImmutable(
            '2026-03-04 22:09:02',
            new DateTimeZone('UTC'),
        );

        self::assertSame(
            '07:09',
            $converter->format(
                $utcDate->setTimezone(new DateTimeZone('Asia/Tokyo')),
                'HH:mm',
            ),
        );
    }

    public function testSingleDigitMonthDayHourTokensAreNotZeroPadded(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '2026/3/5 7',
            $converter->format($this->exampleDate(), 'y/M/d H'),
        );
    }

    public function testDoubleDigitMonthDayHourTokensAreZeroPadded(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '03/05 07',
            $converter->format($this->exampleDate(), 'MM/dd HH'),
        );
    }

    public function testSingleDigitMinuteAndSecondTokensAreNotZeroPadded(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '9:2',
            $converter->format($this->exampleDate(), 'm:s'),
        );
    }

    public function testDoubleDigitMinuteAndSecondTokensAreZeroPadded(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '09:02',
            $converter->format($this->exampleDate(), 'mm:ss'),
        );
    }

    public function testYearTokenLengthControlsTruncationAndPadding(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '2026 26 2026 2026 02026',
            $converter->format($this->exampleDate(), 'y yy yyy yyyy yyyyy'),
        );
    }

    public function testTripleDigitNumericTokensAreZeroPaddedToPatternLength(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '005 007 007 009 002',
            $converter->format(
                $this->exampleDate(),
                'ddd HHH hhh mmm sss',
            ),
        );
    }

    public function testDoubleDigitMonthTokenIsZeroPaddedToTwoDigits(): void
    {
        $converter = new LegacyDateFormatConverter();

        self::assertSame(
            '03',
            $converter->format($this->exampleDate(), 'MM'),
        );
    }

    public function testUnsupportedMonthNameTokenThrows(): void
    {
        $converter = new LegacyDateFormatConverter();

        $this->expectException(RuntimeException::class);

        $converter->format($this->exampleDate(), 'MMM dd');
    }

    public function testUnsupportedLetterTokenThrows(): void
    {
        $converter = new LegacyDateFormatConverter();

        $this->expectException(RuntimeException::class);

        $converter->format($this->exampleDate(), 'EEE yyyy-MM-dd');
    }

    public function testUnterminatedQuotedLiteralThrows(): void
    {
        $converter = new LegacyDateFormatConverter();

        $this->expectException(RuntimeException::class);

        $converter->format($this->exampleDate(), "yyyy'MM-dd");
    }
}

<?php

declare(strict_types=1);

namespace BvlionBatch5\Mail;

use DateTimeImmutable;
use RuntimeException;

/**
 * Formats a DateTimeImmutable using a legacy BvlionBatch4 prefix_format
 * pattern (an Apache Commons FastDateFormat / java.text.SimpleDateFormat
 * pattern). PHP's own DateTimeInterface::format() uses different letters
 * for the same meaning, and has no directive for a non-zero-padded
 * minute or second, so the legacy pattern cannot simply be translated
 * into a PHP format string and passed to format() as-is; each token is
 * instead resolved and rendered directly against the given date.
 *
 * Only the token set needed to reproduce the old mail Slack display
 * name (year, month, day, hour, minute, second and literal text) is
 * supported. An unsupported token throws rather than silently
 * producing a wrong date, since a mistranslated format would post an
 * incorrect timestamp to Slack.
 */
final class LegacyDateFormatConverter
{
    public function format(DateTimeImmutable $date, string $legacyFormat): string
    {
        $characters = mb_str_split($legacyFormat, 1, 'UTF-8');
        $length = count($characters);
        $result = '';
        $index = 0;

        while ($index < $length) {
            $character = $characters[$index];

            if ($character === "'") {
                [$literal, $index] = $this->readQuotedLiteral(
                    $characters,
                    $index,
                );
                $result .= $literal;

                continue;
            }

            if ($this->isAsciiLetter($character)) {
                $runStart = $index;

                while (
                    $index < $length
                    && $characters[$index] === $character
                ) {
                    $index++;
                }

                $result .= $this->formatToken(
                    $date,
                    $character,
                    $index - $runStart,
                );

                continue;
            }

            $result .= $character;
            $index++;
        }

        return $result;
    }

    /**
     * Renders a single FastDateFormat token run. FastDateFormat's
     * Number rule treats the pattern letter count as the minimum digit
     * count: a single letter (e.g. "m") is not zero-padded, while a
     * run of N letters (e.g. "mmm") is zero-padded to N digits.
     */
    private function formatToken(
        DateTimeImmutable $date,
        string $character,
        int $count,
    ): string {
        return match ($character) {
            'y' => $count === 2
                ? $date->format('y')
                : $this->formatNumber((int) $date->format('Y'), $count),
            'M' => $count >= 3
                ? throw new RuntimeException(
                    'prefix_format uses an unsupported month token.',
                )
                : $this->formatNumber((int) $date->format('n'), $count),
            'd' => $this->formatNumber((int) $date->format('j'), $count),
            'H' => $this->formatNumber((int) $date->format('G'), $count),
            'h' => $this->formatNumber((int) $date->format('g'), $count),
            'm' => $this->formatNumber((int) $date->format('i'), $count),
            's' => $this->formatNumber((int) $date->format('s'), $count),
            default => throw new RuntimeException(sprintf(
                'prefix_format uses an unsupported token: %s.',
                $character,
            )),
        };
    }

    /**
     * Zero-pads a numeric field to at least $count digits, matching
     * FastDateFormat's Number rule where the pattern letter count is
     * the minimum digit count (e.g. "ddd" with day 5 renders "005").
     * A single-letter pattern is left unpadded.
     */
    private function formatNumber(int $value, int $count): string
    {
        return $count === 1 ? (string) $value : sprintf('%0' . $count . 'd', $value);
    }

    /**
     * @param list<string> $characters
     * @return array{0: string, 1: int}
     */
    private function readQuotedLiteral(array $characters, int $index): array
    {
        $length = count($characters);
        $index++;

        if ($index < $length && $characters[$index] === "'") {
            return ["'", $index + 1];
        }

        $literal = '';

        while ($index < $length && $characters[$index] !== "'") {
            $literal .= $characters[$index];
            $index++;
        }

        if ($index >= $length) {
            throw new RuntimeException(
                'prefix_format contains an unterminated quoted literal.',
            );
        }

        return [$literal, $index + 1];
    }

    private function isAsciiLetter(string $character): bool
    {
        return preg_match('/^[A-Za-z]$/', $character) === 1;
    }
}

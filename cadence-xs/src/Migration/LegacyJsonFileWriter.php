<?php

declare(strict_types=1);

namespace BvlionBatch5\Migration;

use JsonException;
use RuntimeException;

final class LegacyJsonFileWriter
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    public function write(array $rows, string $outputPath): int
    {
        if (file_exists($outputPath)) {
            throw new RuntimeException(
                'Export output file already exists.',
            );
        }

        $temporaryPath = $outputPath . '.tmp';

        if (file_exists($temporaryPath)) {
            throw new RuntimeException(
                'Export temporary file already exists.',
            );
        }

        $previousUmask = umask(0077);

        try {
            $this->writeTemporaryFile($rows, $temporaryPath);

            if (!rename($temporaryPath, $outputPath)) {
                throw new RuntimeException(
                    'Export output file could not be finalized.',
                );
            }
        } catch (RuntimeException $exception) {
            $this->removeIfExists($temporaryPath);

            throw $exception;
        } finally {
            umask($previousUmask);
        }

        return count($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeTemporaryFile(array $rows, string $temporaryPath): void
    {
        $handle = @fopen($temporaryPath, 'x');

        if ($handle === false) {
            throw new RuntimeException(
                'Export temporary file could not be created.',
            );
        }

        try {
            chmod($temporaryPath, 0600);

            try {
                $encoded = json_encode(
                    $rows,
                    JSON_THROW_ON_ERROR
                        | JSON_PRETTY_PRINT
                        | JSON_UNESCAPED_UNICODE,
                );
            } catch (JsonException) {
                throw new RuntimeException(
                    'Export data could not be encoded.',
                );
            }

            if (fwrite($handle, $encoded) === false) {
                throw new RuntimeException(
                    'Export temporary file could not be written.',
                );
            }
        } finally {
            fclose($handle);
        }

        chmod($temporaryPath, 0600);
    }

    private function removeIfExists(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

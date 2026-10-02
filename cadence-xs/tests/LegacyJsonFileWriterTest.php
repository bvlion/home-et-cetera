<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Migration\LegacyJsonFileWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyJsonFileWriterTest extends TestCase
{
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir() . '/'
            . uniqid('legacy-json-file-writer-test-', true);
        mkdir($this->workingDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workingDirectory . '/*') as $file) {
            unlink($file);
        }

        rmdir($this->workingDirectory);
    }

    public function testWritesRowsAsPermissionRestrictedJsonFile(): void
    {
        $outputPath = $this->workingDirectory . '/example.json';
        $rows = [
            ['id' => 1, 'target_date' => '0411', 'message' => 'Example.'],
            ['id' => 2, 'target_date' => '20260101', 'message' => 'Other.'],
        ];

        $rowCount = (new LegacyJsonFileWriter())->write($rows, $outputPath);

        self::assertSame(2, $rowCount);
        self::assertFileExists($outputPath);
        self::assertFileDoesNotExist($outputPath . '.tmp');
        self::assertSame(
            '0600',
            substr(sprintf('%o', fileperms($outputPath)), -4),
        );
        self::assertSame(
            $rows,
            json_decode(
                (string) file_get_contents($outputPath),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function testRefusesToOverwriteExistingOutputFile(): void
    {
        $outputPath = $this->workingDirectory . '/example.json';
        file_put_contents($outputPath, '[]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Export output file already exists.',
        );

        (new LegacyJsonFileWriter())->write([], $outputPath);
    }

    public function testRefusesToOverwriteExistingTemporaryFile(): void
    {
        $outputPath = $this->workingDirectory . '/example.json';
        file_put_contents($outputPath . '.tmp', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Export temporary file already exists.',
        );

        (new LegacyJsonFileWriter())->write([], $outputPath);
    }

    public function testRemovesTemporaryFileWhenEncodingFails(): void
    {
        $outputPath = $this->workingDirectory . '/example.json';

        try {
            (new LegacyJsonFileWriter())->write(
                [['invalid' => NAN]],
                $outputPath,
            );
            self::fail('An exception was expected.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Export data could not be encoded.',
                $exception->getMessage(),
            );
        }

        self::assertFileDoesNotExist($outputPath);
        self::assertFileDoesNotExist($outputPath . '.tmp');
    }
}

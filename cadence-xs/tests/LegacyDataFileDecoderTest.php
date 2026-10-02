<?php

declare(strict_types=1);

namespace BvlionBatch5\Tests;

use BvlionBatch5\Migration\LegacyDataFileDecoder;
use PHPUnit\Framework\TestCase;

final class LegacyDataFileDecoderTest extends TestCase
{
    public function testDecodesAWellFormedRowListFile(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeRowListFile(
            'dating.json',
            '[{"id":1,"target_date":"0411","message":"Example."}]',
        );

        self::assertSame([], $result['errors']);
        self::assertSame(
            [['id' => 1, 'target_date' => '0411', 'message' => 'Example.']],
            $result['rows'],
        );
    }

    public function testRejectsATopLevelStringWithoutThrowing(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeRowListFile(
            'dating.json',
            '"not a list"',
        );

        self::assertNotEmpty($result['errors']);
        self::assertSame([], $result['rows']);
    }

    public function testRejectsATopLevelObjectForARowListFile(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeRowListFile(
            'dating.json',
            '{"id":1,"target_date":"0411","message":"Example."}',
        );

        self::assertNotEmpty($result['errors']);
        self::assertSame([], $result['rows']);
    }

    public function testRejectsScalarElementsWithinTheArray(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeRowListFile(
            'dating.json',
            '[{"id":1,"target_date":"0411","message":"Example."}, "oops", 42]',
        );

        self::assertCount(2, $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function testMissingRequiredKeyIsStillARowNotAnError(): void
    {
        // Structural decoding does not check for individual required
        // keys; a row missing target_date is still a valid JSON object
        // and is passed through. Field-level validation happens later
        // in LegacyDataImporter::resolve().
        $result = (new LegacyDataFileDecoder())->decodeRowListFile(
            'dating.json',
            '[{"id":1,"message":"Example."}]',
        );

        self::assertSame([], $result['errors']);
        self::assertSame([['id' => 1, 'message' => 'Example.']], $result['rows']);
        self::assertArrayNotHasKey('target_date', $result['rows'][0]);
    }

    public function testDecodesAWellFormedObjectFile(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeObjectFile(
            'channel_map.json',
            '{"example-channel":"C0000000000"}',
        );

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['example-channel' => 'C0000000000'],
            $result['data'],
        );
    }

    public function testAcceptsAnEmptyObjectFile(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeObjectFile(
            'channel_map.json',
            '{}',
        );

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['data']);
    }

    public function testRejectsATopLevelArrayForAnObjectFile(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeObjectFile(
            'migration-settings.json',
            '["a", "b"]',
        );

        self::assertNotEmpty($result['errors']);
        self::assertSame([], $result['data']);
    }

    public function testRejectsAnEmptyArrayAsAnObjectFile(): void
    {
        // `[]` and `{}` are indistinguishable once decoded in assoc
        // mode, which is exactly why decodeObjectFile() decodes
        // without assoc mode first. An empty JSON array must still be
        // rejected even though it decodes to the same empty PHP array
        // as `{}` would under assoc mode.
        $result = (new LegacyDataFileDecoder())->decodeObjectFile(
            'channel_map.json',
            '[]',
        );

        self::assertNotEmpty($result['errors']);
        self::assertSame([], $result['data']);
    }

    public function testRejectsInvalidJsonWithoutThrowing(): void
    {
        $result = (new LegacyDataFileDecoder())->decodeObjectFile(
            'migration-settings.json',
            '{not valid json',
        );

        self::assertNotEmpty($result['errors']);
        self::assertSame([], $result['data']);
    }
}

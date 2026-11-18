<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Tests\Unit;

use IndianOpinion\SseChatWidget\Vector\IndexFormat;
use IndianOpinion\SseChatWidget\Vector\IndexUnusable;
use PHPUnit\Framework\TestCase;

/**
 * T53 (format part), case 64. The vector retriever is on the roadmap; the
 * format and its header checks ship now.
 */
final class IndexFormatTest extends TestCase
{
    private function file(int $dim, int $count, int $records): string
    {
        $bytes = IndexFormat::header($dim, $count, 1_700_000_000);
        for ($i = 0; $i < $records; $i++) {
            $bytes .= IndexFormat::record(100 + $i, array_fill(0, $dim, 0.5));
        }
        return $bytes;
    }

    public function testRoundTrip(): void
    {
        $bytes = $this->file(8, 3, 3);
        $h = IndexFormat::readHeader(substr($bytes, 0, IndexFormat::HEADER_BYTES), strlen($bytes), 8);
        self::assertSame(['version' => 1, 'dimension' => 8, 'count' => 3, 'built' => 1_700_000_000], $h);
        $records = IndexFormat::decode(substr($bytes, IndexFormat::HEADER_BYTES), 8);
        self::assertCount(3, $records);
        self::assertSame(101, $records[1][0]);
        self::assertEqualsWithDelta(0.5, $records[1][1][7], 1e-6);
    }

    public function testUnusableIndexes(): void
    {
        $good = $this->file(8, 2, 2);
        $cases = [
            'bad_tag' => ['XXXX' . substr($good, 4), null],
            'bad_version' => [substr($good, 0, 4) . pack('v', 9) . substr($good, 6), null],
            'dimension_mismatch' => [$good, 16],
            'short_header' => [substr($good, 0, 20), null],
            'partial_record' => [substr($good, 0, -3), null],
            'dimension_too_small' => [substr($good, 0, 8) . pack('V', 4) . substr($good, 12), null],
        ];
        foreach ($cases as $reason => [$bytes, $dim]) {
            try {
                IndexFormat::readHeader(substr($bytes, 0, IndexFormat::HEADER_BYTES), strlen($bytes), $dim);
                self::fail('expected ' . $reason);
            } catch (IndexUnusable $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }
}

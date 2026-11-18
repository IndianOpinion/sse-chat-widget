<?php

declare(strict_types=1);

namespace IndianOpinion\SseChatWidget\Vector;

/**
 * The packed vector index format (case 62). The vector retriever itself is
 * on the roadmap; this class fixes the format so a vector source plugin can
 * target it now.
 *
 * Layout, all integers little-endian:
 *
 *   offset 0   4 bytes  tag "SCVX"
 *   offset 4   uint16   format version (1)
 *   offset 6   uint16   reserved (0)
 *   offset 8   uint32   dimension (8 or more)
 *   offset 12  uint32   record count
 *   offset 16  uint64   build time (unix seconds)
 *   offset 24  8 bytes  reserved (zero)
 *   offset 32  records
 *
 * Each record: uint64 post id, then dimension float32 values.
 */
final class IndexFormat
{
    public const TAG = 'SCVX';
    public const VERSION = 1;
    public const HEADER_BYTES = 32;
    public const MIN_DIMENSION = 8;
    public const CHUNK_RECORDS = 256;

    public static function header(int $dimension, int $count, int $buildTime): string
    {
        if ($dimension < self::MIN_DIMENSION) {
            throw new \InvalidArgumentException('Dimension must be at least ' . self::MIN_DIMENSION . '.');
        }
        return self::TAG . pack('vvVVP', self::VERSION, 0, $dimension, $count, $buildTime) . str_repeat("\0", 8);
    }

    public static function recordBytes(int $dimension): int
    {
        return 8 + 4 * $dimension;
    }

    /**
     * @param list<float> $vector
     */
    public static function record(int $postId, array $vector): string
    {
        return pack('P', $postId) . pack('g*', ...$vector);
    }

    /**
     * Check a header before trusting any record.
     *
     * @return array{version: int, dimension: int, count: int, built: int}
     * @throws IndexUnusable
     */
    public static function readHeader(string $bytes, int $fileSize, ?int $expectedDimension): array
    {
        if (strlen($bytes) < self::HEADER_BYTES || $fileSize < self::HEADER_BYTES) {
            throw new IndexUnusable('short_header');
        }
        if (substr($bytes, 0, 4) !== self::TAG) {
            throw new IndexUnusable('bad_tag');
        }
        /** @var array{version: int, reserved: int, dimension: int, count: int, built: int} $h */
        $h = unpack('vversion/vreserved/Vdimension/Vcount/Pbuilt', substr($bytes, 4, 20));
        if ($h['version'] !== self::VERSION) {
            throw new IndexUnusable('bad_version');
        }
        if ($h['dimension'] < self::MIN_DIMENSION) {
            throw new IndexUnusable('dimension_too_small');
        }
        if ($expectedDimension !== null && $h['dimension'] !== $expectedDimension) {
            throw new IndexUnusable('dimension_mismatch');
        }
        $body = $fileSize - self::HEADER_BYTES;
        $size = self::recordBytes($h['dimension']);
        if ($body % $size !== 0 || intdiv($body, $size) !== $h['count']) {
            throw new IndexUnusable('partial_record');
        }
        return ['version' => $h['version'], 'dimension' => $h['dimension'], 'count' => $h['count'], 'built' => $h['built']];
    }

    /**
     * Decode a chunk of whole records.
     *
     * @return list<array{0: int, 1: list<float>}>
     */
    public static function decode(string $chunk, int $dimension): array
    {
        $size = self::recordBytes($dimension);
        $out = [];
        $n = intdiv(strlen($chunk), $size);
        for ($i = 0; $i < $n; $i++) {
            $rec = substr($chunk, $i * $size, $size);
            /** @var array{1: int} $id */
            $id = unpack('P', substr($rec, 0, 8));
            /** @var array<int, float> $values */
            $values = unpack('g*', substr($rec, 8));
            $out[] = [$id[1], array_values($values)];
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace FancyQr\Tests\Support;

use RuntimeException;

/**
 * Reads a PNG back, for the suite only: 8-bit grey/RGB/RGBA and 1/8-bit
 * indexed, non-interlaced, all five filters, every CRC checked.
 *
 * Independent of the writer under test: it shares no code with `Png`, so a
 * writer bug cannot be read back as correct by the same mistake.
 */
final class PngReader
{
    /**
     * Unfiltered rows as byte strings. Cheap enough for a rendered page.
     *
     * @return array{width: int, height: int, depth: int, type: int, channels: int, palette: list<array{int, int, int}>, rows: list<string>}
     */
    public static function raw(string $bytes): array
    {
        if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('not a PNG');
        }
        $p = 8;
        $ihdr = null;
        $palette = [];
        $idat = '';
        while ($p < strlen($bytes)) {
            $len = unpack('N', substr($bytes, $p, 4))[1];
            $type = substr($bytes, $p + 4, 4);
            $data = substr($bytes, $p + 8, $len);
            $crc = unpack('N', substr($bytes, $p + 8 + $len, 4))[1];
            if (crc32($type.$data) !== $crc) {
                throw new RuntimeException("bad CRC on {$type}");
            }
            $p += 12 + $len;
            if ($type === 'IHDR') {
                $ihdr = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $data);
            } elseif ($type === 'PLTE') {
                foreach (str_split($data, 3) as $rgb) {
                    $palette[] = [ord($rgb[0]), ord($rgb[1]), ord($rgb[2])];
                }
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if ($ihdr === null || $ihdr['interlace'] !== 0) {
            throw new RuntimeException('unsupported PNG');
        }
        $width = $ihdr['width'];
        $height = $ihdr['height'];
        $depth = $ihdr['depth'];
        $type = $ihdr['type'];
        $channels = [0 => 1, 2 => 3, 3 => 1, 6 => 4][$type] ?? throw new RuntimeException("type {$type}");
        $bitsPerPixel = $channels * $depth;
        $bpp = max(1, intdiv($bitsPerPixel, 8));
        $stride = intdiv($width * $bitsPerPixel + 7, 8);

        $raw = gzuncompress($idat);
        if ($raw === false || strlen($raw) !== ($stride + 1) * $height) {
            throw new RuntimeException('IDAT does not inflate to the size the header promises');
        }

        $rows = [];
        $prev = str_repeat("\0", $stride);
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$y * ($stride + 1)]);
            $line = substr($raw, $y * ($stride + 1) + 1, $stride);
            if ($filter === 0) {
                $out = $line;
            } else {
                $out = '';
                for ($i = 0; $i < $stride; $i++) {
                    $x = ord($line[$i]);
                    $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
                    $b = ord($prev[$i]);
                    $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                    $v = match ($filter) {
                        1 => $x + $a,
                        2 => $x + $b,
                        3 => $x + (($a + $b) >> 1),
                        4 => $x + self::paeth($a, $b, $c),
                        default => throw new RuntimeException("filter {$filter}"),
                    };
                    $out .= chr($v & 0xFF);
                }
            }
            $rows[] = $out;
            $prev = $out;
        }

        return ['width' => $width, 'height' => $height, 'depth' => $depth, 'type' => $type, 'channels' => $channels, 'palette' => $palette, 'rows' => $rows];
    }

    /**
     * Every pixel as RGB. Only for small images: it is a PHP array per pixel.
     *
     * @return array{width: int, height: int, depth: int, type: int, palette: list<array{int, int, int}>, pixels: list<list<array{int, int, int}>>}
     */
    public static function read(string $bytes): array
    {
        $png = self::raw($bytes);
        $pixels = [];
        foreach ($png['rows'] as $out) {
            $row = [];
            for ($px = 0; $px < $png['width']; $px++) {
                $row[] = self::pixel($png, $out, $px);
            }
            $pixels[] = $row;
        }

        return ['width' => $png['width'], 'height' => $png['height'], 'depth' => $png['depth'], 'type' => $png['type'], 'palette' => $png['palette'], 'pixels' => $pixels];
    }

    /**
     * One pixel of a row returned by {@see raw()}.
     *
     * @param  array{depth: int, type: int, channels: int, palette: list<array{int, int, int}>}  $png
     * @return array{int, int, int}
     */
    public static function pixel(array $png, string $row, int $x): array
    {
        if ($png['type'] === 3) {
            $depth = $png['depth'];
            $bit = $x * $depth;
            $index = (ord($row[$bit >> 3]) >> (8 - $depth - ($bit & 7))) & ((1 << $depth) - 1);

            return $png['palette'][$index] ?? throw new RuntimeException("index {$index} outside the palette");
        }
        if ($png['type'] === 0) {
            $g = ord($row[$x]);

            return [$g, $g, $g];
        }
        $o = $x * $png['channels'];

        return [ord($row[$o]), ord($row[$o + 1]), ord($row[$o + 2])];
    }

    /**
     * The IHDR fields alone, for an image too large to expand into PHP arrays.
     *
     * @return array{width: int, height: int, depth: int, type: int}
     */
    public static function header(string $bytes): array
    {
        if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n" || substr($bytes, 12, 4) !== 'IHDR') {
            throw new RuntimeException('not a PNG with IHDR first');
        }
        $h = unpack('Nwidth/Nheight/Cdepth/Ctype', substr($bytes, 16, 10));

        return ['width' => $h['width'], 'height' => $h['height'], 'depth' => $h['depth'], 'type' => $h['type']];
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }
}

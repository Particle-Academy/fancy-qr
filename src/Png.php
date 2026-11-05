<?php

declare(strict_types=1);

namespace FancyQr;

/**
 * A PNG writer for QR symbols, on PHP's bundled zlib.
 *
 * The same approach as fine-print's `PngEncoder` (signature, IHDR, one IDAT
 * of `gzcompress`ed rows each with a filter byte, IEND, a CRC-32 per chunk),
 * written here rather than depended on, because a QR code needs one narrow
 * case of it and a package that draws codes should not install a PDF engine.
 *
 * The narrow case is a 1-bit INDEXED image with a two-colour palette:
 * background is index 0, foreground index 1. A QR code has exactly two
 * colours, so this is the smallest faithful encoding, and it is one fine-print
 * embeds into a PDF byte for byte, without decoding it.
 *
 * Every module is a whole number of pixels square and the quiet zone is
 * painted with the background, so the image is opaque and its module edges
 * are exact: there is nothing for a scanner to guess at.
 *
 * @internal Use {@see QrCode::png()}.
 */
final class Png
{
    public const MAX_SIDE = 8192;

    /**
     * @param  array{int, int, int}  $foreground  RGB
     * @param  array{int, int, int}  $background  RGB
     */
    public static function encode(Symbol $symbol, int $quietZone, int $scale, array $foreground, array $background): string
    {
        $extent = $symbol->size + $quietZone * 2;
        $width = $extent * $scale;

        $margin = str_repeat('0', $quietZone * $scale);
        $blankRow = self::packRow(str_repeat('0', $width));

        $raw = '';
        for ($i = 0; $i < $quietZone * $scale; $i++) {
            $raw .= "\0".$blankRow;
        }
        foreach ($symbol->modules as $line) {
            $bits = $margin;
            foreach ($line as $dark) {
                $bits .= str_repeat($dark ? '1' : '0', $scale);
            }
            $packed = "\0".self::packRow($bits.$margin);
            $raw .= str_repeat($packed, $scale);
        }
        for ($i = 0; $i < $quietZone * $scale; $i++) {
            $raw .= "\0".$blankRow;
        }

        // Width, height, bit depth 1, colour type 3 (indexed), deflate,
        // adaptive filtering (method 0), no interlace.
        $ihdr = pack('NNCCCCC', $width, $width, 1, 3, 0, 0, 0);
        $palette = pack('C*', ...$background, ...$foreground);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', $ihdr)
            .self::chunk('PLTE', $palette)
            .self::chunk('IDAT', (string) gzcompress($raw, 9))
            .self::chunk('IEND', '');
    }

    /** A string of `0`/`1` pixels, packed eight to a byte, most significant first. */
    private static function packRow(string $bits): string
    {
        $pad = (8 - strlen($bits) % 8) % 8;
        $bits .= str_repeat('0', $pad);
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            $out .= chr((int) bindec($byte));
        }

        return $out;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}

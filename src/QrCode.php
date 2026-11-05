<?php

declare(strict_types=1);

namespace FancyQr;

/**
 * QR codes on the server: the module matrix, an SVG, a PNG, and a printable
 * document for fine-print.
 *
 * The encoder is a port of react-fancy's, so a code generated here and a
 * `<QrCode>` rendered in the browser are the same symbol for the same input,
 * and `svg()` writes the same bytes as react-fancy's `qrCodeSvg()`.
 *
 * ## Every input is treated as hostile
 *
 * - A value longer than any QR code can carry (7089 bytes) is refused from its
 *   length before anything reads it, and any value that does not fit throws
 *   {@see ValueTooLongException}. Nothing is truncated.
 * - So the work is bounded: at most a version 40 symbol and eight masks.
 * - A PNG is at most 8192 pixels a side, whatever `scale` asks for.
 * - The label and colours are escaped into the SVG, and characters XML cannot
 *   carry (C0 controls, invalid UTF-8) are removed or replaced.
 */
final class QrCode
{
    private const WHITE = '#ffffff';

    private const BLACK = '#000000';

    /**
     * The module matrix.
     *
     * @param  'L'|'M'|'Q'|'H'  $errorCorrection
     * @param  'numeric'|'alphanumeric'|'byte'|null  $mode
     */
    public static function encode(
        string $value,
        string $errorCorrection = 'M',
        ?string $mode = null,
        ?int $version = null,
    ): Symbol {
        return Encoder::encode($value, $errorCorrection, $mode, $version);
    }

    /**
     * A standalone SVG document with an XML declaration, a `<title>`, an
     * accessible name and an opaque background.
     *
     * @param  string  $label  what the code is FOR, e.g. "Your sign-up link"; written as the `<title>`
     * @param  int|float|null  $size  width and height in pixels; default ten per module
     * @param  string  $foreground  any SVG colour; `currentColor` becomes black, since a file has no text colour
     * @param  string  $background  any SVG colour; `transparent` becomes white, since a file has no page behind it
     */
    public static function svg(
        string $value,
        string $label,
        string $errorCorrection = 'M',
        ?string $mode = null,
        ?int $version = null,
        int $quietZone = 4,
        string $foreground = self::BLACK,
        string $background = self::WHITE,
        int|float|null $size = null,
    ): string {
        $symbol = Encoder::encode($value, $errorCorrection, $mode, $version);
        $quietZone = max(0, $quietZone);
        $extent = $symbol->size + $quietZone * 2;
        $pixels = $size !== null && is_finite((float) $size) && $size > 0 ? self::number($size) : (string) ($extent * 10);
        $name = self::xml($label);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<svg xmlns="http://www.w3.org/2000/svg" width="'.$pixels.'" height="'.$pixels.'" viewBox="0 0 '.$extent.' '.$extent.'" shape-rendering="crispEdges" role="img" aria-label="'.$name.'">'
            .'<title>'.$name.'</title>'
            .'<rect width="'.$extent.'" height="'.$extent.'" fill="'.self::xml(self::svgBackground($background)).'"/>'
            .'<path d="'.self::path($symbol, $quietZone).'" fill="'.self::xml(self::svgForeground($foreground)).'"/>'
            ."</svg>\n";
    }

    /**
     * A PNG: 1-bit indexed, opaque, a whole number of pixels per module.
     *
     * @param  int  $scale  pixels per module, clamped to at least 1 and to at most 8192 pixels a side
     * @param  string  $foreground  `#rgb` or `#rrggbb`; `currentColor` means black
     * @param  string  $background  `#rgb` or `#rrggbb`; `transparent` means white
     *
     * @throws QrException for a colour that is not hex
     */
    public static function png(
        string $value,
        string $errorCorrection = 'M',
        ?string $mode = null,
        ?int $version = null,
        int $quietZone = 4,
        int $scale = 10,
        string $foreground = self::BLACK,
        string $background = self::WHITE,
    ): string {
        $fg = self::rgb(self::svgForeground($foreground));
        $bg = self::rgb(self::svgBackground($background));
        $symbol = Encoder::encode($value, $errorCorrection, $mode, $version);
        $quietZone = max(0, $quietZone);

        return Png::encode($symbol, $quietZone, self::scale($scale, $symbol->size + $quietZone * 2), $fg, $bg);
    }

    /**
     * A printable page as a fine-print document: the code as an image, sized in
     * millimetres, and the value as text beneath it (a link when it is a web
     * address). Returns plain data in fine-print's document model; this
     * package does not depend on fine-print. To write the PDF:
     *
     *     $pdf = \FinePrint\Agent::toBytes(QrCode::finePrintDocument($url, 'Your sign-up link'));
     *
     * The image is drawn at 300 dpi or better for its printed size. The value is
     * set in fine-print's standard fonts, which cover Windows-1252; for other
     * scripts, register a TrueType font with fine-print and set it on the run.
     *
     * @param  bool  $showValue  print the value under the code; turn off for a value not meant to be read out
     * @return array{title: string, blocks: list<array<string, mixed>>}
     */
    public static function finePrintDocument(
        string $value,
        string $label,
        bool $showValue = true,
        float $sizeMm = 50.0,
        string $errorCorrection = 'M',
        ?string $mode = null,
        ?int $version = null,
        int $quietZone = 4,
        string $foreground = self::BLACK,
        string $background = self::WHITE,
    ): array {
        if (! is_finite($sizeMm) || $sizeMm <= 0 || $sizeMm > 1000) {
            throw new QrException('sizeMm must be greater than 0 and at most 1000');
        }
        $symbol = Encoder::encode($value, $errorCorrection, $mode, $version);
        $quietZone = max(0, $quietZone);
        $extent = $symbol->size + $quietZone * 2;
        $scale = self::scale((int) ceil($sizeMm / 25.4 * 300 / $extent), $extent);
        $png = Png::encode($symbol, $quietZone, $scale, self::rgb(self::svgForeground($foreground)), self::rgb(self::svgBackground($background)));

        $blocks = [[
            'type' => 'image',
            'src' => 'data:image/png;base64,'.base64_encode($png),
            'widthPx' => round($sizeMm / 25.4 * 96, 2),
            'alt' => $label,
            'keepNext' => $showValue,
        ]];
        if ($showValue) {
            $run = ['text' => $value];
            if (self::isWebUrl($value)) {
                $run['link'] = $value;
            }
            $blocks[] = ['type' => 'paragraph', 'runs' => [$run]];
        }

        return ['title' => $label, 'blocks' => $blocks];
    }

    /**
     * The SVG path for the dark modules, horizontal runs as one rectangle: the
     * same string react-fancy's `modulePath` builds.
     */
    public static function path(Symbol $symbol, int $quietZone = 4): string
    {
        $parts = [];
        $size = $symbol->size;
        foreach ($symbol->modules as $row => $line) {
            $col = 0;
            while ($col < $size) {
                if (! $line[$col]) {
                    $col++;

                    continue;
                }
                $run = 1;
                while ($col + $run < $size && $line[$col + $run]) {
                    $run++;
                }
                $parts[] = 'M'.($col + $quietZone).' '.($row + $quietZone).'h'.$run.'v1h-'.$run.'z';
                $col += $run;
            }
        }

        return implode('', $parts);
    }

    private static function scale(int $scale, int $extent): int
    {
        $ceiling = max(1, intdiv(Png::MAX_SIDE, max(1, $extent)));

        return min(max(1, $scale), $ceiling);
    }

    private static function svgBackground(string $colour): string
    {
        return in_array($colour, ['', 'transparent', 'none'], true) ? self::WHITE : $colour;
    }

    private static function svgForeground(string $colour): string
    {
        return in_array($colour, ['', 'currentColor'], true) ? self::BLACK : $colour;
    }

    /**
     * `#rgb` or `#rrggbb` to RGB. Checked with strspn, not a pattern.
     *
     * @return array{int, int, int}
     */
    private static function rgb(string $colour): array
    {
        $hex = substr($colour, 1);
        $length = strlen($hex);
        if ($colour === '' || $colour[0] !== '#' || ($length !== 3 && $length !== 6) || strspn($hex, '0123456789abcdefABCDEF') !== $length) {
            throw new QrException('A PNG colour must be #rgb or #rrggbb (or currentColor / transparent)');
        }
        if ($length === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /**
     * XML text and attribute escaping, matching react-fancy's: `&<>"'` become
     * entities. Before that, C0 control characters XML 1.0 cannot carry are
     * removed, and invalid UTF-8 becomes U+FFFD.
     */
    private static function xml(string $text): string
    {
        $text = str_replace(
            ["\x00", "\x01", "\x02", "\x03", "\x04", "\x05", "\x06", "\x07", "\x08", "\x0B", "\x0C", "\x0E", "\x0F",
                "\x10", "\x11", "\x12", "\x13", "\x14", "\x15", "\x16", "\x17", "\x18", "\x19", "\x1A", "\x1B", "\x1C", "\x1D", "\x1E", "\x1F"],
            '',
            $text,
        );

        // ENT_XML1 writes the apostrophe as `&apos;`; react-fancy writes
        // `&#39;`. Both are valid XML, and only one is the same bytes.
        return str_replace('&apos;', '&#39;', htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8'));
    }

    /** A number as JavaScript's String() writes it, for the values this takes. */
    private static function number(int|float $value): string
    {
        if (is_int($value) || floor($value) === $value) {
            return (string) (int) $value;
        }

        return (string) $value;
    }

    private static function isWebUrl(string $value): bool
    {
        $parts = parse_url($value);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}

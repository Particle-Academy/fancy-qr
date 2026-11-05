<?php

declare(strict_types=1);

use FancyQr\QrCode;
use FancyQr\QrException;
use FancyQr\Tests\Support\PngReader;

const LINK = 'https://example.com/r/AbC123';

function svgDocument(string $svg): DOMDocument
{
    $doc = new DOMDocument;
    expect($doc->loadXML($svg, LIBXML_NONET))->toBeTrue();

    return $doc;
}

describe('svg', function () {
    it('is a standalone SVG with a title and an accessible name', function () {
        $doc = svgDocument(QrCode::svg(LINK, label: 'Your sign-up link'));
        $svg = $doc->documentElement;
        expect($svg->namespaceURI)->toBe('http://www.w3.org/2000/svg')
            ->and($svg->getAttribute('role'))->toBe('img')
            ->and($svg->getAttribute('aria-label'))->toBe('Your sign-up link')
            ->and($doc->getElementsByTagName('title')->item(0)?->textContent)->toBe('Your sign-up link');
    });

    it('draws the encoder\'s modules inside a four-module quiet zone', function () {
        $symbol = QrCode::encode(LINK);
        $doc = svgDocument(QrCode::svg(LINK, label: 'x'));
        $extent = $symbol->size + 8;
        expect($doc->documentElement->getAttribute('viewBox'))->toBe("0 0 {$extent} {$extent}")
            ->and($doc->getElementsByTagName('path')->item(0)?->getAttribute('d'))->toBe(QrCode::path($symbol, 4));
    });

    it('is opaque: a file has no page behind it to inherit', function () {
        $doc = svgDocument(QrCode::svg(LINK, label: 'x', foreground: 'currentColor', background: 'transparent'));
        expect($doc->getElementsByTagName('rect')->item(0)?->getAttribute('fill'))->toBe('#ffffff')
            ->and($doc->getElementsByTagName('path')->item(0)?->getAttribute('fill'))->toBe('#000000');
    });

    it('escapes the label and colours, and drops what XML cannot carry', function () {
        $hostile = "</title><script>alert(1)</script>\x01\x1F & 'q' \"d\" \xFF";
        $doc = svgDocument(QrCode::svg(LINK, label: $hostile, foreground: '"/><script>x</script>'));
        expect($doc->getElementsByTagName('script')->length)->toBe(0)
            ->and($doc->getElementsByTagName('title')->item(0)?->textContent)
            ->toBe("</title><script>alert(1)</script> & 'q' \"d\" \u{FFFD}");
    });
});

describe('png', function () {
    it('is a 1-bit indexed PNG whose every pixel is its module, quiet zone included', function () {
        $symbol = QrCode::encode(LINK);
        $png = PngReader::read(QrCode::png(LINK, scale: 6));
        $extent = $symbol->size + 8;

        expect($png['width'])->toBe($extent * 6)
            ->and($png['height'])->toBe($extent * 6)
            ->and($png['depth'])->toBe(1)
            ->and($png['type'])->toBe(3)
            ->and($png['palette'])->toBe([[255, 255, 255], [0, 0, 0]]);

        $wrong = 0;
        for ($y = 0; $y < $png['height']; $y++) {
            for ($x = 0; $x < $png['width']; $x++) {
                $row = intdiv($y, 6) - 4;
                $col = intdiv($x, 6) - 4;
                $dark = $row >= 0 && $col >= 0 && $row < $symbol->size && $col < $symbol->size && $symbol->modules[$row][$col];
                if ($png['pixels'][$y][$x] !== ($dark ? [0, 0, 0] : [255, 255, 255])) {
                    $wrong++;
                }
            }
        }
        expect($wrong)->toBe(0);
    });

    it('takes hex colours, and refuses anything it cannot draw', function () {
        $png = PngReader::read(QrCode::png('1', foreground: '#1a1a2e', background: '#FFE'));
        expect($png['palette'])->toBe([[255, 255, 238], [26, 26, 46]]);

        expect(fn () => QrCode::png('1', foreground: 'red'))->toThrow(QrException::class)
            ->and(fn () => QrCode::png('1', background: '#12345'))->toThrow(QrException::class)
            ->and(fn () => QrCode::png('1', background: '#gggggg'))->toThrow(QrException::class);
    });

    it('is a whole number of pixels per module, at least one, at most 8192 a side', function () {
        $symbol = QrCode::encode(LINK);
        $extent = $symbol->size + 8;
        expect(PngReader::read(QrCode::png(LINK, scale: 0))['width'])->toBe($extent)
            ->and(PngReader::read(QrCode::png(LINK, scale: -9))['width'])->toBe($extent)
            ->and(PngReader::header(QrCode::png(LINK, scale: 1_000_000))['width'])->toBe(intdiv(8192, $extent) * $extent);
    });

    it('honours a custom quiet zone and clamps a negative one to none', function () {
        $symbol = QrCode::encode(LINK);
        expect(PngReader::read(QrCode::png(LINK, quietZone: 6, scale: 1))['width'])->toBe($symbol->size + 12)
            ->and(PngReader::read(QrCode::png(LINK, quietZone: -3, scale: 1))['width'])->toBe($symbol->size);
    });
});

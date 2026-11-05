<?php

declare(strict_types=1);

use FancyQr\QrCode;
use FancyQr\Tests\Support\PngReader;
use FinePrint\Agent;
use FinePrint\Render\Renderer;

/*
 * The printable-page recipe: `finePrintDocument()` returns plain data in
 * fine-print's document model, and fine-print writes it. fine-print is a dev
 * dependency here, never a runtime one, so these tests are what keep the
 * recipe honest: the document validates, the PDF carries the value as text,
 * and the code on the rendered page reads back as the same modules.
 */

const SIGNUP = 'https://example.com/r/AbC123';

it('returns a fine-print document: the code as an image and the value as a link', function () {
    $doc = QrCode::finePrintDocument(SIGNUP, 'Your sign-up link');

    expect($doc['title'])->toBe('Your sign-up link')
        ->and($doc['blocks'])->toHaveCount(2)
        ->and($doc['blocks'][0]['type'])->toBe('image')
        ->and($doc['blocks'][0]['src'])->toStartWith('data:image/png;base64,')
        ->and($doc['blocks'][0]['widthPx'])->toBe(188.98)
        ->and($doc['blocks'][0]['alt'])->toBe('Your sign-up link')
        ->and($doc['blocks'][1])->toBe(['type' => 'paragraph', 'runs' => [['text' => SIGNUP, 'link' => SIGNUP]]]);
});

it('draws the image at 300 dpi or better for its printed size', function () {
    $doc = QrCode::finePrintDocument(SIGNUP, 'x', sizeMm: 50);
    $png = PngReader::read(base64_decode(substr($doc['blocks'][0]['src'], 22)));
    expect($png['width'])->toBeGreaterThanOrEqual((int) ceil(50 / 25.4 * 300));
});

it('leaves the value out, and makes no link of a value that is not a web address', function () {
    expect(QrCode::finePrintDocument('WIFI:T:WPA;S:Guest;P:hunter2;;', 'x', showValue: false)['blocks'])->toHaveCount(1)
        ->and(QrCode::finePrintDocument('SN-4829-13', 'x')['blocks'][1]['runs'][0])->toBe(['text' => 'SN-4829-13'])
        ->and(QrCode::finePrintDocument('javascript:alert(1)', 'x')['blocks'][1]['runs'][0])->not->toHaveKey('link');
});

it('validates, and writes a PDF that carries the value as text', function () {
    $doc = QrCode::finePrintDocument(SIGNUP, 'Your sign-up link');
    expect(Agent::validate($doc))->toBe([]);

    $pdf = Agent::toBytes($doc);
    expect($pdf)->toStartWith('%PDF-');

    $read = Agent::read($pdf);
    expect($read['ok'])->toBeTrue()
        ->and(json_encode($read, JSON_UNESCAPED_SLASHES))->toContain(SIGNUP);
});

it('prints a code that reads back as the same modules', function () {
    $symbol = QrCode::encode(SIGNUP);
    $pdf = Agent::toBytes(QrCode::finePrintDocument(SIGNUP, 'x', showValue: false));
    $page = PngReader::raw(Renderer::png($pdf, page: 1, scale: 4.0));
    $dark = function (int $x, int $y) use ($page): bool {
        [$r, $g, $b] = PngReader::pixel($page, $page['rows'][$y], $x);

        return $r + $g + $b < 384;
    };

    // The symbol's own extent: from the first dark pixel to the last, which
    // the three finder patterns pin to its corners. The page is otherwise
    // blank (the value is left out), so every dark pixel is the code's.
    $minX = $minY = PHP_INT_MAX;
    $maxX = $maxY = -1;
    for ($y = 0; $y < $page['height']; $y++) {
        for ($x = 0; $x < $page['width']; $x++) {
            if ($dark($x, $y)) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }
    $module = ($maxX - $minX + 1) / $symbol->size;
    expect(abs(($maxY - $minY + 1) / $symbol->size - $module))->toBeLessThan(0.5)
        // 50 mm at scale 4 (288 dpi) is ~567 px, so a module is many pixels.
        ->and($module)->toBeGreaterThan(8.0);

    $wrong = 0;
    for ($row = 0; $row < $symbol->size; $row++) {
        for ($col = 0; $col < $symbol->size; $col++) {
            if ($dark((int) ($minX + ($col + 0.5) * $module), (int) ($minY + ($row + 0.5) * $module)) !== $symbol->modules[$row][$col]) {
                $wrong++;
            }
        }
    }
    expect($wrong)->toBe(0);
});

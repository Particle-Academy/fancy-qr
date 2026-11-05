<?php

declare(strict_types=1);

use FancyQr\Encoder;
use FancyQr\QrCode;

/*
 * The contract: the same input gives the same module matrix as react-fancy's
 * `encodeQr`, and the same SVG bytes as its `qrCodeSvg`.
 *
 * `fixtures/parity.json` is generated from the TypeScript source by
 * `fixtures/export-parity.mjs`, never from this package, so this suite cannot
 * agree with itself by construction. It covers every version at every level,
 * all three modes, all eight masks forced, and enough real-shaped links that
 * automatic mask selection lands on each of the eight. A divergence in mode
 * selection, version selection, Reed-Solomon, interleaving, placement,
 * masking, format bits or penalty scoring changes the matrix, and the matrix
 * is compared whole.
 */

function parityFixture(): array
{
    static $fixture = null;

    return $fixture ??= json_decode((string) file_get_contents(__DIR__.'/fixtures/parity.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** @return iterable<string, array{array<string, mixed>}> */
function parityCases(): iterable
{
    foreach (parityFixture()['cases'] as $i => $case) {
        $options = $case['options'];
        $label = sprintf(
            '#%d %s %s',
            $i,
            json_encode(strlen($case['value']) > 40 ? substr($case['value'], 0, 37).'...' : $case['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($options),
        );
        yield $label => [$case];
    }
}

it('covers what it claims to cover', function () {
    $fixture = parityFixture();
    expect($fixture['masksCovered'])->toBe([0, 1, 2, 3, 4, 5, 6, 7])
        ->and(count($fixture['cases']))->toBeGreaterThan(400)
        ->and(array_values(array_unique(array_column($fixture['cases'], 'mode'))))->toEqualCanonicalizing(['numeric', 'alphanumeric', 'byte'])
        ->and(array_unique(array_column($fixture['cases'], 'version')))->toHaveCount(40);
});

it('builds the same matrix as react-fancy', function (array $case) {
    $options = $case['options'];
    $symbol = Encoder::encode(
        $case['value'],
        $options['errorCorrection'] ?? 'M',
        $options['mode'] ?? null,
        $options['version'] ?? null,
        $options['mask'] ?? null,
    );

    expect([
        'version' => $symbol->version,
        'mode' => $symbol->mode,
        'mask' => $symbol->mask,
        'size' => $symbol->size,
    ])->toBe([
        'version' => $case['version'],
        'mode' => $case['mode'],
        'mask' => $case['mask'],
        'size' => $case['size'],
    ]);

    $rows = $symbol->rows();
    if (isset($case['rows'])) {
        // Row by row, so a failure names the first row that differs.
        expect($rows)->toBe($case['rows']);
    }
    expect(hash('sha256', implode("\n", $rows)))->toBe($case['sha256']);
})->with(parityCases());

it('writes the same SVG as react-fancy, byte for byte', function (array $case) {
    $o = $case['options'];
    $svg = QrCode::svg(
        $case['value'],
        label: $o['label'],
        errorCorrection: $o['errorCorrection'] ?? 'M',
        quietZone: $o['quietZone'] ?? 4,
        foreground: $o['foreground'] ?? '#000000',
        background: $o['background'] ?? '#ffffff',
        size: $o['size'] ?? null,
    );
    expect($svg)->toBe($case['svg']);
})->with(fn () => array_map(fn ($c) => [$c], parityFixture()['svg']));

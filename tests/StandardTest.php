<?php

declare(strict_types=1);

use FancyQr\Encoder;
use FancyQr\Tables;

/*
 * Independent anchors, ported from react-fancy's own suite, so this package is
 * not checked only against the TypeScript (which could share a bug with it):
 *
 *  1. The standard's published 5-Q worked example: real input, real data
 *     codewords, real interleaved output with error correction.
 *  2. A three-way check of the block table against the matrix THIS package
 *     builds, for all 160 version/level rows.
 *  3. The published version-information strings.
 */

// The input really is 53 bytes with a backslash before the apostrophe,
// recovered from the published codewords. "Tidying" it breaks every test here.
const GOLDEN_INPUT = "There\\'s a frood who really knows where his towel is.";

const GOLDEN_DATA = [
    67, 85, 70, 134, 87, 38, 85, 194, 119, 50, 6, 18, 6, 103, 38,
    246, 246, 66, 7, 118, 134, 242, 7, 38, 86, 22, 198, 199, 146, 6,
    182, 230, 247, 119, 50, 7, 118, 134, 87, 38, 82, 6, 134, 151, 50, 7,
    70, 247, 118, 86, 194, 6, 151, 50, 224, 236, 17, 236, 17, 236, 17, 236,
];

const GOLDEN_INTERLEAVED = [
    67, 246, 182, 70, 85, 246, 230, 247, 70, 66, 247, 118, 134, 7, 119, 86, 87, 118, 50, 194, 38,
    134, 7, 6, 85, 242, 118, 151, 194, 7, 134, 50, 119, 38, 87, 224, 50, 86, 38, 236, 6, 22, 82, 17,
    18, 198, 6, 236, 6, 199, 134, 17, 103, 146, 151, 236, 38, 6, 50, 17, 7, 236,
    213, 87, 148, 140, 199, 204, 116, 100, 11, 96, 177, 250, 45, 60, 212, 247, 115, 202, 76, 108,
    247, 182, 133, 131, 241, 124, 75, 37, 223, 157, 242, 104, 229, 200, 238, 253, 248, 134, 76, 113,
    154, 27, 195, 111, 117, 129, 230, 235, 154, 209, 189, 197, 111, 17, 10, 83, 86, 163, 108, 6,
    161, 163, 240, 205, 111, 120, 192, 89, 39, 133, 141, 74,
];

describe("the standard's published 5-Q worked example", function () {
    it('has the input the published codewords decode to', function () {
        expect(strlen(GOLDEN_INPUT))->toBe(53)
            ->and(GOLDEN_INPUT)->toContain(chr(92));
    });

    it('produces the published data codewords, padding included', function () {
        expect(Encoder::dataCodewords(GOLDEN_INPUT, 'byte', 5, 'Q'))->toBe(GOLDEN_DATA);
    });

    it('produces the published interleaved message, error correction included', function () {
        expect(Encoder::interleave(Encoder::dataCodewords(GOLDEN_INPUT, 'byte', 5, 'Q'), 5, 'Q'))->toBe(GOLDEN_INTERLEAVED);
    });
});

describe('the block table agrees with the matrix this package builds', function () {
    it('holds all three equations for every version and level', function () {
        $failures = [];
        for ($version = 1; $version <= Tables::MAX_VERSION; $version++) {
            $free = Encoder::rawDataModules($version);
            foreach (Tables::LEVELS as $level) {
                $l = Tables::blockLayout($version, $level);
                $blocks = $l['g1Blocks'] + $l['g2Blocks'];
                $fromGroups = $l['g1Blocks'] * $l['g1Size'] + $l['g2Blocks'] * $l['g2Size'];
                $total = $l['dataCodewords'] + $l['ecPerBlock'] * $blocks;
                if ($fromGroups !== $l['dataCodewords']) {
                    $failures[] = "v{$version}-{$level}: groups sum to {$fromGroups}";
                }
                if ($total !== intdiv($free, 8)) {
                    $failures[] = "v{$version}-{$level}: {$total} codewords, matrix has room for ".intdiv($free, 8);
                }
                if ($l['g2Blocks'] > 0 && $l['g2Size'] !== $l['g1Size'] + 1) {
                    $failures[] = "v{$version}-{$level}: group 2 is {$l['g2Size']}";
                }
            }
        }
        expect($failures)->toBe([]);
    });

    it('places alignment patterns 6 modules in and 7 from the far edge', function () {
        for ($version = 2; $version <= 40; $version++) {
            $positions = Tables::alignmentPositions($version);
            expect($positions[0])->toBe(6)
                ->and(end($positions))->toBe($version * 4 + 17 - 7)
                ->and($positions)->toHaveCount(intdiv($version, 7) + 2);
        }
        expect(Tables::alignmentPositions(1))->toBe([]);
    });
});

it('computes the published version-information strings', function () {
    $bits = fn (int $v) => str_pad(decbin(Tables::versionBits($v)), 18, '0', STR_PAD_LEFT);
    expect($bits(7))->toBe('000111110010010100')
        ->and($bits(8))->toBe('001000010110111100')
        ->and($bits(9))->toBe('001001101010011001')
        ->and($bits(10))->toBe('001010010011010011')
        ->and($bits(40))->toBe('101000110001101001');
});

it('derives the published maximum capacities', function () {
    expect(Encoder::maxCharacters('numeric'))->toBe(7089)
        ->and(Encoder::maxCharacters('alphanumeric'))->toBe(4296)
        ->and(Encoder::maxCharacters('byte'))->toBe(2953);
});

it('selects the narrowest mode', function () {
    expect(Encoder::selectMode('482913'))->toBe('numeric')
        ->and(Encoder::selectMode(''))->toBe('numeric')
        ->and(Encoder::selectMode('SN-4829-13'))->toBe('alphanumeric')
        ->and(Encoder::selectMode('sn-4829-13'))->toBe('byte')
        ->and(Encoder::selectMode('https://example.com/r/AbC123'))->toBe('byte')
        ->and(Encoder::selectMode("caf\u{e9}"))->toBe('byte');
});

it('scores the mask penalty rules as react-fancy does', function () {
    // Values pinned in react-fancy's suite: a 7x7 single-colour field is 278,
    // a perfect checkerboard is 0.
    $flat = array_fill(0, 7, array_fill(0, 7, true));
    expect(Encoder::penalty($flat))->toBe(278);

    $checker = [];
    for ($r = 0; $r < 7; $r++) {
        for ($c = 0; $c < 7; $c++) {
            $checker[$r][$c] = ($r + $c) % 2 === 0;
        }
    }
    expect(Encoder::penalty($checker))->toBe(0);
});

<?php

declare(strict_types=1);

namespace FancyQr;

/**
 * A QR encoder, dependency-free: a faithful port of react-fancy's
 * `qr-encode.ts`.
 *
 * ISO/IEC 18004, versions 1-40, error correction L/M/Q/H, numeric /
 * alphanumeric / byte modes with automatic selection, and mask choice by the
 * four penalty rules. Same tables, same mode selection, same version
 * selection, same mask scoring and tie-break, so the same input gives the
 * SAME MODULE MATRIX as `encodeQr` in react-fancy. `tests/fixtures/parity.json`
 * is generated from the TypeScript and holds this to it.
 *
 * The one input difference: TypeScript encodes a JavaScript string as UTF-8,
 * and PHP strings are already bytes. A valid UTF-8 string therefore encodes
 * identically, and any other byte string is carried as the bytes it is.
 *
 * No Kanji mode and no structured append, as in react-fancy: an untested
 * branch in an encoder is worse than an absent one.
 */
final class Encoder
{
    public const MODES = ['numeric', 'alphanumeric', 'byte'];

    private const MODE_INDICATOR = ['numeric' => 0b0001, 'alphanumeric' => 0b0010, 'byte' => 0b0100];

    /** The character set alphanumeric mode can carry, in its own index order. */
    private const ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    private const DIGITS = '0123456789';

    /** @var list<int>|null */
    private static ?array $exp = null;

    /** @var list<int>|null */
    private static ?array $log = null;

    /** @var array<int, list<int>> */
    private static array $generators = [];

    /**
     * Encode a value into a QR symbol.
     *
     * @param  'L'|'M'|'Q'|'H'  $errorCorrection
     * @param  'numeric'|'alphanumeric'|'byte'|null  $mode  force a mode instead of the narrowest that fits
     * @param  int|null  $version  force a version, 1-40
     * @param  int|null  $mask  force a mask, 0-7, instead of scoring all eight
     *
     * @throws ValueTooLongException when the value cannot fit, or a forced version is too small.
     *                               Throwing is deliberate: a truncated symbol scans cleanly and
     *                               yields the wrong string.
     * @throws QrException on an unknown level, mode, version or mask, or a value the forced mode
     *                     cannot carry.
     */
    public static function encode(
        string $value,
        string $errorCorrection = 'M',
        ?string $mode = null,
        ?int $version = null,
        ?int $mask = null,
    ): Symbol {
        if (! in_array($errorCorrection, Tables::LEVELS, true)) {
            throw new QrException('Error correction must be one of L, M, Q, H');
        }
        if ($mode !== null && ! in_array($mode, self::MODES, true)) {
            throw new QrException('Mode must be one of numeric, alphanumeric, byte');
        }
        if ($mask !== null && ($mask < 0 || $mask > 7)) {
            throw new QrException('Mask must be 0-7');
        }

        // Before anything walks the value. A string longer than the largest
        // capacity of any mode cannot fit, and is refused from its length
        // alone: refusing 5 MB costs the same as refusing 8 kB.
        $length = strlen($value);
        $absolute = self::maxCharacters('numeric');
        if ($length > $absolute) {
            throw new ValueTooLongException(
                "Value is too long for a QR code ({$length} bytes; the maximum any mode can carry is {$absolute})",
            );
        }
        if ($mode !== null && $length > self::maxCharacters($mode)) {
            throw new ValueTooLongException(
                "Value is too long for a QR code in {$mode} mode ({$length} characters; the maximum is ".self::maxCharacters($mode).')',
            );
        }

        $mode ??= self::selectMode($value);
        if ($length > self::maxCharacters($mode)) {
            throw new ValueTooLongException(
                "Value is too long for a QR code in {$mode} mode ({$length} characters; the maximum is ".self::maxCharacters($mode).')',
            );
        }
        if ($mode === 'numeric' && strspn($value, self::DIGITS) !== $length) {
            throw new QrException('Numeric mode accepts digits only');
        }
        if ($mode === 'alphanumeric' && strspn($value, self::ALPHANUMERIC) !== $length) {
            throw new QrException('Alphanumeric mode accepts 0-9 A-Z space and $%*+-./: only');
        }

        if ($version !== null) {
            if ($version < Tables::MIN_VERSION || $version > Tables::MAX_VERSION) {
                throw new QrException('Version must be '.Tables::MIN_VERSION.'-'.Tables::MAX_VERSION);
            }
            if (self::payloadBits($value, $mode, $version) > Tables::blockLayout($version, $errorCorrection)['dataCodewords'] * 8) {
                throw new ValueTooLongException("Value does not fit in version {$version} at level {$errorCorrection}");
            }
        } else {
            $version = self::selectVersion($value, $mode, $errorCorrection);
            if ($version === null) {
                throw new ValueTooLongException(
                    "Value is too long for a QR code at level {$errorCorrection} ({$length} bytes)",
                );
            }
        }

        $codewords = self::interleave(self::dataCodewords($value, $mode, $version, $errorCorrection), $version, $errorCorrection);
        $template = self::reservedModules($version);
        $placed = $template;
        self::placeData($placed, $codewords);

        $best = null;
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        foreach ($mask === null ? [0, 1, 2, 3, 4, 5, 6, 7] : [$mask] as $candidate) {
            $modules = [];
            foreach ($placed as $row => $line) {
                $out = [];
                foreach ($line as $col => $cell) {
                    // Only data modules are masked, which is why the mask is
                    // applied against the TEMPLATE's null positions.
                    $isData = $template[$row][$col] === null;
                    $out[] = $isData && self::maskCondition($candidate, $row, $col) ? ! $cell : (bool) $cell;
                }
                $modules[] = $out;
            }
            self::applyFormat($modules, $errorCorrection, $candidate);
            $score = self::penalty($modules);
            // Strictly lower replaces, so a tie keeps the LOWER mask: the same
            // rule as react-fancy's `reduce` with `<=`.
            if ($score < $bestScore) {
                $best = $modules;
                $bestMask = $candidate;
                $bestScore = $score;
            }
        }

        return new Symbol($best ?? [], $version * 4 + 17, $version, $errorCorrection, $mode, $bestMask);
    }

    /** The narrowest mode that can carry the value. */
    public static function selectMode(string $value): string
    {
        $length = strlen($value);
        if (strspn($value, self::DIGITS) === $length) {
            return 'numeric';
        }
        if (strspn($value, self::ALPHANUMERIC) === $length) {
            return 'alphanumeric';
        }

        return 'byte';
    }

    /**
     * The most characters a mode can carry, at version 40 level L. Derived from
     * the block table: 7089 numeric, 4296 alphanumeric, 2953 bytes.
     */
    public static function maxCharacters(string $mode): int
    {
        $bits = Tables::blockLayout(Tables::MAX_VERSION, 'L')['dataCodewords'] * 8 - 4 - self::countBits($mode, Tables::MAX_VERSION);

        return match ($mode) {
            'numeric' => intdiv($bits * 3, 10),
            'alphanumeric' => intdiv($bits * 2, 11),
            default => intdiv($bits, 8),
        };
    }

    /** Bits in the character count indicator; it widens with version. */
    private static function countBits(string $mode, int $version): int
    {
        $tier = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);

        return match ($mode) {
            'numeric' => [10, 12, 14][$tier],
            'alphanumeric' => [9, 11, 13][$tier],
            default => [8, 16, 16][$tier],
        };
    }

    /**
     * The payload's own bits before terminator and padding, as a list of 0/1.
     *
     * @return list<int>
     */
    private static function encodePayload(string $value, string $mode, int $version): array
    {
        $bits = [];
        self::push($bits, self::MODE_INDICATOR[$mode], 4);
        $length = strlen($value);

        if ($mode === 'numeric') {
            self::push($bits, $length, self::countBits($mode, $version));
            for ($i = 0; $i < $length; $i += 3) {
                $group = substr($value, $i, 3);
                self::push($bits, (int) $group, strlen($group) * 3 + 1);
            }

            return $bits;
        }

        if ($mode === 'alphanumeric') {
            self::push($bits, $length, self::countBits($mode, $version));
            for ($i = 0; $i < $length; $i += 2) {
                $first = strpos(self::ALPHANUMERIC, $value[$i]);
                if ($i + 1 < $length) {
                    self::push($bits, (int) $first * 45 + (int) strpos(self::ALPHANUMERIC, $value[$i + 1]), 11);
                } else {
                    self::push($bits, (int) $first, 6);
                }
            }

            return $bits;
        }

        self::push($bits, $length, self::countBits($mode, $version));
        for ($i = 0; $i < $length; $i++) {
            self::push($bits, ord($value[$i]), 8);
        }

        return $bits;
    }

    /** @param list<int> $bits */
    private static function push(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * How many bits the payload needs at a version. Arithmetic, not an encode:
     * the same count `encodePayload` produces, without building it.
     */
    private static function payloadBits(string $value, string $mode, int $version): int
    {
        $n = strlen($value);
        $data = match ($mode) {
            'numeric' => intdiv($n, 3) * 10 + [0, 4, 7][$n % 3],
            'alphanumeric' => intdiv($n, 2) * 11 + ($n % 2) * 6,
            default => $n * 8,
        };

        return 4 + self::countBits($mode, $version) + $data;
    }

    /** The smallest version that fits, or null. */
    private static function selectVersion(string $value, string $mode, string $level): ?int
    {
        for ($version = Tables::MIN_VERSION; $version <= Tables::MAX_VERSION; $version++) {
            if (self::payloadBits($value, $mode, $version) <= Tables::blockLayout($version, $level)['dataCodewords'] * 8) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Data codewords: payload, terminator, pad to a byte, then alternating pad
     * bytes 0xEC / 0x11.
     *
     * @return list<int>
     */
    public static function dataCodewords(string $value, string $mode, int $version, string $level): array
    {
        $capacity = Tables::blockLayout($version, $level)['dataCodewords'] * 8;
        $bits = self::encodePayload($value, $mode, $version);

        self::push($bits, 0, min(4, $capacity - count($bits)));
        self::push($bits, 0, (8 - (count($bits) % 8)) % 8);

        $codewords = [];
        for ($i = 0, $n = count($bits); $i < $n; $i += 8) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $byte = ($byte << 1) | $bits[$i + $bit];
            }
            $codewords[] = $byte;
        }

        $target = intdiv($capacity, 8);
        for ($i = 0; count($codewords) < $target; $i++) {
            $codewords[] = $i % 2 === 0 ? 0xEC : 0x11;
        }

        return $codewords;
    }

    /**
     * Split into blocks, compute error correction, and interleave.
     *
     * @param  list<int>  $codewords
     * @return list<int>
     */
    public static function interleave(array $codewords, int $version, string $level): array
    {
        $layout = Tables::blockLayout($version, $level);
        $dataBlocks = [];
        $offset = 0;
        for ($i = 0; $i < $layout['g1Blocks']; $i++) {
            $dataBlocks[] = array_slice($codewords, $offset, $layout['g1Size']);
            $offset += $layout['g1Size'];
        }
        for ($i = 0; $i < $layout['g2Blocks']; $i++) {
            $dataBlocks[] = array_slice($codewords, $offset, $layout['g2Size']);
            $offset += $layout['g2Size'];
        }

        $ecBlocks = array_map(fn (array $block) => self::reedSolomon($block, $layout['ecPerBlock']), $dataBlocks);

        $result = [];
        $widest = max($layout['g1Size'], $layout['g2Blocks'] > 0 ? $layout['g2Size'] : 0);
        for ($i = 0; $i < $widest; $i++) {
            foreach ($dataBlocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $layout['ecPerBlock']; $i++) {
            foreach ($ecBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    // --- Reed-Solomon over GF(256), primitive polynomial 0x11d, generator 2 ---

    private static function field(): void
    {
        if (self::$exp !== null) {
            return;
        }
        $exp = array_fill(0, 256, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        $exp[255] = $exp[0];
        self::$exp = $exp;
        self::$log = $log;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /** @return list<int> */
    private static function generatorPoly(int $degree): array
    {
        if (isset(self::$generators[$degree])) {
            return self::$generators[$degree];
        }
        self::field();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::gfMul($coefficient, self::$exp[$i]);
            }
            $poly = $next;
        }

        return self::$generators[$degree] = $poly;
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $degree): array
    {
        $generator = self::generatorPoly($degree);
        $remainder = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $remainder[$i] ^= self::gfMul($generator[$i + 1], $factor);
            }
        }

        return $remainder;
    }

    // --- Matrix construction ---------------------------------------------------

    /**
     * A grid with every function pattern placed and data positions left null.
     *
     * @return list<list<bool|null>>
     */
    public static function reservedModules(int $version): array
    {
        $size = $version * 4 + 17;
        $grid = array_fill(0, $size, array_fill(0, $size, null));

        // Three finder patterns with their separators.
        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$baseRow, $baseCol]) {
            for ($row = -1; $row <= 7; $row++) {
                for ($col = -1; $col <= 7; $col++) {
                    $r = $baseRow + $row;
                    $c = $baseCol + $col;
                    if ($r < 0 || $r >= $size || $c < 0 || $c >= $size) {
                        continue;
                    }
                    $ring = max(abs($row - 3), abs($col - 3));
                    $grid[$r][$c] = $ring !== 2 && $ring <= 3;
                }
            }
        }

        // Timing patterns, row and column 6.
        for ($i = 0; $i < $size; $i++) {
            if ($grid[6][$i] === null) {
                $grid[6][$i] = $i % 2 === 0;
            }
            if ($grid[$i][6] === null) {
                $grid[$i][6] = $i % 2 === 0;
            }
        }

        // Alignment patterns, except where a finder already sits.
        $positions = Tables::alignmentPositions($version);
        foreach ($positions as $row) {
            foreach ($positions as $col) {
                $nearFinder = ($row <= 8 && $col <= 8) || ($row <= 8 && $col >= $size - 9) || ($row >= $size - 9 && $col <= 8);
                if ($nearFinder) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $grid[$row + $dr][$col + $dc] = max(abs($dr), abs($dc)) !== 1;
                    }
                }
            }
        }

        // Format information areas, reserved now and written after masking.
        for ($i = 0; $i < 9; $i++) {
            if ($grid[8][$i] === null) {
                $grid[8][$i] = false;
            }
            if ($grid[$i][8] === null) {
                $grid[$i][8] = false;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $grid[8][$size - 1 - $i] = false;
            $grid[$size - 1 - $i][8] = false;
        }
        $grid[$size - 8][8] = true;

        // Version information, versions 7 and up.
        if ($version >= 7) {
            $bits = Tables::versionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $grid[intdiv($i, 3)][$size - 11 + ($i % 3)] = $bit;
                $grid[$size - 11 + ($i % 3)][intdiv($i, 3)] = $bit;
            }
        }

        return $grid;
    }

    /** How many modules a version leaves free for data, counted from the grid. */
    public static function rawDataModules(int $version): int
    {
        $free = 0;
        foreach (self::reservedModules($version) as $row) {
            foreach ($row as $cell) {
                if ($cell === null) {
                    $free++;
                }
            }
        }

        return $free;
    }

    /**
     * Place the interleaved codewords in the standard's zigzag order.
     *
     * @param  list<list<bool|null>>  $grid
     * @param  list<int>  $codewords
     */
    private static function placeData(array &$grid, array $codewords): void
    {
        $size = count($grid);
        $bitIndex = 0;
        $count = count($codewords);
        $upward = true;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            // Column 6 is the vertical timing pattern; the pair skips it.
            $pair = $right === 6 ? [5, 4] : [$right, $right - 1];
            for ($step = 0; $step < $size; $step++) {
                $row = $upward ? $size - 1 - $step : $step;
                foreach ($pair as $col) {
                    if ($grid[$row][$col] === null) {
                        $byteIndex = $bitIndex >> 3;
                        // Past the end is a remainder bit: light.
                        $bit = $byteIndex < $count ? ($codewords[$byteIndex] >> (7 - ($bitIndex & 7))) & 1 : 0;
                        $grid[$row][$col] = $bit === 1;
                        $bitIndex++;
                    }
                }
            }
            if ($right === 6) {
                $right -= 1;
            }
            $upward = ! $upward;
        }
    }

    /** The eight mask conditions. True means invert this module. */
    private static function maskCondition(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => (($row * $col) % 2) + (($row * $col) % 3) === 0,
            6 => ((($row * $col) % 2) + (($row * $col) % 3)) % 2 === 0,
            default => ((($row + $col) % 2) + (($row * $col) % 3)) % 2 === 0,
        };
    }

    /**
     * The four penalty rules, scored on a finished candidate. Ported rule for
     * rule, including react-fancy's convention for rule 3: each eleven-module
     * window counts separately, so a finder-like run with four light modules
     * on BOTH sides scores 80.
     *
     * @param  list<list<bool>>  $modules
     */
    public static function penalty(array $modules): int
    {
        $size = count($modules);
        $score = 0;

        // Rows and columns as strings of 0/1, built once.
        $rows = [];
        $cols = array_fill(0, $size, '');
        foreach ($modules as $line) {
            $text = '';
            foreach ($line as $c => $dark) {
                $bit = $dark ? '1' : '0';
                $text .= $bit;
                $cols[$c] .= $bit;
            }
            $rows[] = $text;
        }

        // Rule 1: runs of five or more same-coloured modules in a line.
        foreach ([$rows, $cols] as $lines) {
            foreach ($lines as $line) {
                $run = 1;
                for ($j = 1; $j < $size; $j++) {
                    if ($line[$j] === $line[$j - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $score += $run - 2;
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $score += $run - 2;
                }
            }
        }

        // Rule 2: every 2x2 block of one colour.
        for ($row = 0; $row < $size - 1; $row++) {
            $here = $rows[$row];
            $below = $rows[$row + 1];
            for ($col = 0; $col < $size - 1; $col++) {
                $a = $here[$col];
                if ($a === $here[$col + 1] && $a === $below[$col] && $a === $below[$col + 1]) {
                    $score += 3;
                }
            }
        }

        // Rule 3: the finder-like 1:1:3:1:1 pattern, with four light modules
        // on a side. strpos from the next offset finds overlapping matches too.
        foreach ([$rows, $cols] as $lines) {
            foreach ($lines as $line) {
                $at = 0;
                while (($j = strpos($line, '1011101', $at)) !== false) {
                    if ($j >= 4 && substr($line, $j - 4, 4) === '0000') {
                        $score += 40;
                    }
                    if ($j + 11 <= $size && substr($line, $j + 7, 4) === '0000') {
                        $score += 40;
                    }
                    $at = $j + 1;
                }
            }
        }

        // Rule 4: deviation of the dark proportion from 50%.
        $dark = 0;
        foreach ($rows as $line) {
            $dark += substr_count($line, '1');
        }
        $percent = ($dark * 100) / ($size * $size);
        $score += (int) floor(abs($percent - 50) / 5) * 10;

        return $score;
    }

    /**
     * Write the 15 format bits into both locations: the standard's split
     * placement, seven up the column by the bottom-left finder then eight
     * along the row by the top-right one.
     *
     * @param  list<list<bool>>  $modules
     */
    private static function applyFormat(array &$modules, string $level, int $mask): void
    {
        $size = count($modules);
        $bits = Tables::FORMAT_BITS[$level][$mask];
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $modules[8][$i] = $bit(14 - $i);
        }
        $modules[8][7] = $bit(8);
        $modules[8][8] = $bit(7);
        $modules[7][8] = $bit(6);
        for ($i = 9; $i <= 14; $i++) {
            $modules[14 - $i][8] = $bit(14 - $i);
        }

        for ($i = 0; $i <= 6; $i++) {
            $modules[$size - 1 - $i][8] = $bit($i);
        }
        for ($i = 7; $i <= 14; $i++) {
            $modules[8][$size - 15 + $i] = $bit($i);
        }
        $modules[$size - 8][8] = true;
    }
}

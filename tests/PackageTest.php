<?php

declare(strict_types=1);

/*
 * Zero runtime dependencies is a property of this package, not an accident of
 * today's composer.json: it needs PHP and bundled extensions, nothing else.
 * fine-print is a dev dependency (for the printable-page recipe's tests) and a
 * `suggest`, never a `require`.
 */

it('requires nothing at runtime but PHP and bundled extensions', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $extra = array_filter(
        array_keys($composer['require']),
        fn (string $name) => $name !== 'php' && ! str_starts_with($name, 'ext-'),
    );
    expect(array_values($extra))->toBe([])
        ->and($composer['require'])->toBe(['php' => '^8.4', 'ext-zlib' => '*'])
        ->and($composer['suggest'])->toHaveKey('particle-academy/fine-print');
});

it('uses no extension it does not declare', function () {
    // The only extension functions the source calls: zlib's gzcompress, plus
    // core (crc32, pack, hexdec, htmlspecialchars, parse_url, strspn).
    $source = implode("\n", array_map('file_get_contents', glob(__DIR__.'/../src/*.php')));
    foreach (['mb_', 'iconv', 'ctype_', 'preg_', 'gmp_', 'bc', 'imagecreate', 'Imagick'] as $needle) {
        expect(str_contains($source, $needle.'('))->toBeFalse("src calls {$needle}");
    }
    expect($source)->toContain('gzcompress(');
});

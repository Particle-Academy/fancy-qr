<?php

declare(strict_types=1);

use FancyQr\Encoder;
use FancyQr\QrCode;
use FancyQr\QrException;
use FancyQr\ValueTooLongException;

/*
 * Every value is text this package did not author. What does a hostile caller
 * get out of it? Not unbounded work: a value longer than any QR code can carry
 * is refused from strlen() before anything reads it, and anything that does fit
 * is at most a version 40 symbol and eight masks.
 */

it('refuses an impossible value from its length, without reading it', function () {
    $huge = str_repeat('a', 5_000_000);
    $started = hrtime(true);
    try {
        Encoder::encode($huge);
        $this->fail('expected a refusal');
    } catch (ValueTooLongException $e) {
        expect($e->getMessage())->toContain('7089');
    }
    expect((hrtime(true) - $started) / 1e6)->toBeLessThan(20.0);
});

it('encodes right at each capacity and refuses one past it', function () {
    expect(Encoder::encode(str_repeat('7', 7089), 'L')->version)->toBe(40)
        ->and(Encoder::encode(str_repeat('A', 4296), 'L')->version)->toBe(40)
        ->and(Encoder::encode(str_repeat('a', 2953), 'L')->version)->toBe(40);

    expect(fn () => Encoder::encode(str_repeat('7', 7090), 'L'))->toThrow(ValueTooLongException::class)
        ->and(fn () => Encoder::encode(str_repeat('A', 4297), 'L'))->toThrow(ValueTooLongException::class)
        ->and(fn () => Encoder::encode(str_repeat('a', 2954), 'L'))->toThrow(ValueTooLongException::class)
        // A higher level holds less: 2953 bytes fit at L, not at H.
        ->and(fn () => Encoder::encode(str_repeat('a', 2953), 'H'))->toThrow(ValueTooLongException::class);
});

it('says why, for a value too long for a forced version', function () {
    expect(fn () => Encoder::encode(str_repeat('a', 100), version: 2))
        ->toThrow(ValueTooLongException::class, 'Value does not fit in version 2 at level M');
});

it('refuses options it does not know rather than guessing', function () {
    expect(fn () => Encoder::encode('x', 'X'))->toThrow(QrException::class)
        ->and(fn () => Encoder::encode('x', mode: 'kanji'))->toThrow(QrException::class)
        ->and(fn () => Encoder::encode('x', version: 0))->toThrow(QrException::class)
        ->and(fn () => Encoder::encode('x', version: 41))->toThrow(QrException::class)
        ->and(fn () => Encoder::encode('x', mask: 8))->toThrow(QrException::class)
        ->and(fn () => Encoder::encode('12a', mode: 'numeric'))->toThrow(QrException::class, 'digits only')
        ->and(fn () => Encoder::encode('abc', mode: 'alphanumeric'))->toThrow(QrException::class);
});

it('bounds the worst case that does fit', function () {
    $started = hrtime(true);
    $symbol = Encoder::encode(str_repeat('a', 2953), 'L');
    $ms = (hrtime(true) - $started) / 1e6;
    expect($symbol->size)->toBe(177)
        // Generous: this is a ceiling against a regression to unbounded work,
        // not a benchmark.
        ->and($ms)->toBeLessThan(5000.0);
});

it('carries bytes that are not UTF-8 as the bytes they are', function () {
    $symbol = Encoder::encode("\xFF\xFE\x00binary");
    expect($symbol->mode)->toBe('byte');
});

it('refuses a print size that is not a size', function () {
    expect(fn () => QrCode::finePrintDocument('x', 'x', sizeMm: 0))->toThrow(QrException::class)
        ->and(fn () => QrCode::finePrintDocument('x', 'x', sizeMm: INF))->toThrow(QrException::class)
        ->and(fn () => QrCode::finePrintDocument('x', 'x', sizeMm: 5000))->toThrow(QrException::class);
});

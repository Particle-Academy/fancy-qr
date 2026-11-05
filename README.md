# fancy-qr

QR codes on the server, as SVG and PNG, in pure PHP with **zero runtime
dependencies** (PHP 8.4 and the bundled `zlib` extension).

The encoder is a port of the one inside
[react-fancy](https://github.com/Particle-Academy/react-fancy)'s `<QrCode>`, so a
code generated on the server and a code rendered in the browser are **the same
symbol** for the same input: same version, same mode, same mask, same module
matrix. `QrCode::svg()` writes the same bytes as react-fancy's `qrCodeSvg()`.

A printable page goes through
[fine-print](https://github.com/Particle-Academy/fine-print), without this
package depending on it.

```bash
composer require particle-academy/fancy-qr
```

## Usage

```php
use FancyQr\QrCode;

$link = 'https://example.com/r/AbC123';

// An SVG file: XML declaration, <title>, accessible name, opaque background.
$svg = QrCode::svg($link, label: 'Your sign-up link');

// A PNG: 1-bit indexed, a whole number of pixels per module.
$png = QrCode::png($link, scale: 12);

// The module matrix, for anything else: a label printer, a canvas, a terminal.
$symbol = QrCode::encode($link, errorCorrection: 'Q');
$symbol->modules;   // list<list<bool>>, true is dark
$symbol->size;      // version * 4 + 17
$symbol->version;   // 1-40
$symbol->mode;      // 'numeric' | 'alphanumeric' | 'byte'
$symbol->mask;      // 0-7, chosen by penalty scoring
$symbol->rows();    // list<string> of '0'/'1'
```

Serving one from Laravel:

```php
Route::get('/r/{token}/qr.svg', fn (string $token) => response(
    QrCode::svg(url("/r/{$token}"), label: 'Your sign-up link'),
    200,
    ['Content-Type' => 'image/svg+xml'],
));
```

`value` is any string. A QR code is a byte container, not a link component: a
serial number, a ticket id, a WiFi block, a contact card or plain text all work
the same way. A digits-only value is encoded in numeric mode and is a third the
size; uppercase letters, digits and ` $%*+-./:` use alphanumeric mode.

## A printable page, through fine-print

`finePrintDocument()` returns plain data in fine-print's document model: the
code as an image, sized in millimetres and drawn at 300 dpi or better, and the
value beneath it as text (a link when it is a web address).

```bash
composer require particle-academy/fine-print
```

```php
use FancyQr\QrCode;
use FinePrint\Agent;

$doc = QrCode::finePrintDocument($link, label: 'Your sign-up link', sizeMm: 50);
$pdf = Agent::toBytes($doc);
```

The document is data, so it composes: put `$doc['blocks']` inside a larger
fine-print document (a flyer with a heading and copy) rather than printing a
page with nothing but the code. Pass `showValue: false` for a value that is not
meant to be read out, such as a WiFi password.

fine-print sets text in its standard fonts, which cover Windows-1252. For a
value in another script, register a TrueType font with fine-print and set it on
the run.

## Colours, and the light quiet zone

Defaults are black modules on white, in every output: 21:1 contrast, past WCAG
AAA's 7:1. A QR code is read by the contrast between dark modules and a **light**
surround, and the quiet zone (four modules by default, the standard's minimum)
must be that light colour for a scanner to find the code. Most scanners do not
read an inverted code. So if you set colours, keep the background the lighter
one.

Files are always opaque: `transparent` becomes white and `currentColor` becomes
black, because a file has no page behind it to inherit. `svg()` takes any SVG
colour string; `png()` takes `#rgb` or `#rrggbb` and refuses anything else with
a `QrException`.

## Accessibility

A QR code is an image of a value, unusable to anyone who cannot point a second
device at it. So:

- `svg()` requires a `label` saying what the code is FOR ("Your sign-up link"),
  written as the file's `<title>` and accessible name.
- `finePrintDocument()` prints the value as text under the code by default.
- On a web page, show the value as text beside the code too. react-fancy's
  `<QrCodePanel showValue>` does this.

## Hostile input

Every value is treated as text this package did not author.

- A value longer than any QR code can carry (7089 bytes) is refused from its
  length, before anything reads it. Refusing 5 MB costs what refusing 8 kB does.
- Any value that does not fit at the requested level, mode or version throws
  `ValueTooLongException`, with the reason. Nothing is ever truncated: a
  truncated code scans cleanly and yields the wrong string.
- So the work is bounded: at most a version 40 symbol and eight masks scored.
- A PNG is at most 8192 pixels a side, whatever `scale` asks for.
- The label and colours are escaped into the SVG. C0 control characters XML
  cannot carry are dropped, and invalid UTF-8 becomes U+FFFD.
- No regular expressions anywhere in the source.

Largest capacities, at version 40 level `L`: **7089** digits, **4296**
alphanumeric characters, **2953** bytes. A higher level holds less.

## Options

| Option | Default | |
|---|---|---|
| `errorCorrection` | `'M'` | `'L'`, `'M'`, `'Q'` or `'H'`. `M` survives about 15% damage. Raise it for print that may be scuffed; higher holds less, so the same value needs a bigger symbol. |
| `mode` | automatic | Force `'numeric'`, `'alphanumeric'` or `'byte'`. |
| `version` | smallest that fits | Force 1-40, to give several codes one module count. |
| `quietZone` | `4` | Modules of light margin. Do not set 0 unless you add your own. |
| `foreground` / `background` | `'#000000'` / `'#ffffff'` | See above. |
| `size` (`svg`) | 10 px per module | Width and height in pixels. |
| `scale` (`png`) | `10` | Pixels per module, at least 1. |
| `sizeMm`, `showValue` (`finePrintDocument`) | `50`, `true` | Printed width; whether to print the value. |

## Parity with react-fancy

`tests/fixtures/parity.json` is generated from react-fancy's TypeScript encoder
by `tests/fixtures/export-parity.mjs`, never from this package. It holds 465
matrices (every version at every level, all three modes, all eight masks
forced, and 200 link-shaped values on which automatic mask choice lands on each
of the eight) and SVG files compared byte for byte. The suite also checks the
encoder against the standard's published worked example and version strings,
independently of the TypeScript.

The one input difference: TypeScript encodes a JavaScript string as UTF-8, and
a PHP string is already bytes. A valid UTF-8 string encodes identically; any
other byte string is carried as the bytes it is.

## Not supported

Kanji mode, ECI and structured append, as in react-fancy.

## License

MIT.

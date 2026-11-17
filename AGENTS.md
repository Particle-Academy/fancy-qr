# Using fancy-qr — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-qr
```

- **PHP:** `^8.4` with `ext-zlib`. No other runtime dependency.
- **Optional:** `composer require particle-academy/fine-print` to write
  `finePrintDocument()` as a PDF.
- In the browser, react-fancy's `<QrCode>` draws the same symbol for the same
  input.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package's registry entry is `fancy-qr`. As a
server-side package it is found with `search-backend-packages` (stack `php`);
`install-instructions` gives the exact install commands.

## API essentials

All on `FancyQr\QrCode`:

- `QrCode::svg($value, label: '...')`: an SVG file with `<title>` and an
  accessible name. Byte-identical to react-fancy's `qrCodeSvg()`.
- `QrCode::png($value, scale: 12)`: a 1-bit indexed PNG.
- `QrCode::encode($value, errorCorrection: 'Q')`: a `Symbol` with `modules`,
  `size`, `version`, `mode`, `mask`, `rows()`.
- `QrCode::finePrintDocument($value, label: '...', sizeMm: 50, showValue: true)`:
  plain arrays in fine-print's document model, for `FinePrint\Agent::toBytes()`
  or for composing into a larger document via `$doc['blocks']`.
- Options: `errorCorrection` (`L`, `M` default, `Q`, `H`), `mode`, `version`,
  `quietZone` (4), `foreground` / `background`, `size` (svg), `scale` (png).

## Traps

- **`svg()` requires a `label`** saying what the code is for. On a web page,
  show the value as text beside the code too.
- **Keep the background the lighter colour.** Most scanners do not read an
  inverted code, and the quiet zone must be light. Do not set `quietZone` to 0
  unless you add your own margin.
- **`png()` takes only `#rgb` or `#rrggbb`** and throws `QrException`
  otherwise. Files are always opaque: `transparent` becomes white.
- **A value that does not fit throws `ValueTooLongException`.** Nothing is
  truncated. Capacity at version 40, level `L`: 7089 digits, 4296
  alphanumeric, 2953 bytes; a higher level holds less.
- A PNG is at most 8192 pixels a side, whatever `scale` asks for.
- **`showValue: false`** for a value not meant to be read out, such as a WiFi
  password.
- fine-print's standard fonts cover Windows-1252; register a TrueType font for
  other scripts.
- Kanji mode, ECI and structured append are not supported.

# Contributing to fancy-qr

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Code map

`FancyQr\` is a PHP 8.4 library with zero runtime dependencies: PHP and
`ext-zlib`, nothing else. `tests/PackageTest.php` fails if `require` gains
anything, or if `src/` calls an extension it does not declare.

- `Encoder` is a PORT of react-fancy's `src/components/QrCode/qr-encode.ts`,
  function for function. `Tables` is its `qr-tables.ts`. The contract is the
  same module matrix for the same input. Do not "improve" the algorithm here:
  mode selection, version selection, Reed-Solomon, interleaving, placement,
  masking, format bits, the penalty rules and the mask tie-break (strictly
  lower score wins, so a tie keeps the lower mask) must all stay as the
  TypeScript has them. A change belongs in react-fancy first, then here.
- `Symbol` is the result. `QrCode` is the public facade: `encode`, `svg`,
  `png`, `finePrintDocument`, `path`. `Png` is the writer (1-bit indexed, the
  chunk approach of fine-print's `PngEncoder`, written here, not depended on).
- `QrCode::svg()` must stay byte-identical to react-fancy's `qrCodeSvg()`,
  escaping included: react-fancy writes `&#39;`, PHP's `ENT_XML1` writes
  `&apos;`, and `xml()` converts. Both drop C0 controls XML cannot carry.
- `finePrintDocument()` returns ARRAYS in fine-print's document model. It must
  never reference a `FinePrint\` class: fine-print is a dev dependency and a
  `suggest` only.
- No regular expressions in `src/`. Character classes are checked with
  `strspn`.

Hostile input: the length check in `Encoder::encode` runs before anything
reads the value (strlen only), and every later step is bounded by version 40.
Keep any new code path behind that check.

## Tests

```bash
composer install
composer test                 # pest
composer run parity:export    # regenerate the parity fixture from react-fancy
```

- `tests/fixtures/parity.json` is GENERATED from react-fancy by
  `node tests/fixtures/export-parity.mjs [path/to/react-fancy]` (default: the
  sibling checkout in the Fancy envelope, with its `node_modules`). Never edit
  it by hand and never regenerate it from this package. Regenerate when
  react-fancy's encoder or `qrCodeSvg` changes, and expect the matrix cases to
  be unchanged unless the encoder genuinely changed. `generatedFrom` records
  the react-fancy version and commit.
- `tests/StandardTest.php` holds anchors independent of the TypeScript: the
  standard's 5-Q worked example (its input really contains a backslash), the
  three-way block table check, published version strings.
- `tests/FinePrintTest.php` writes a real PDF with fine-print, renders the page
  and samples the code back into modules.
- `tests/Support/PngReader.php` reads PNGs back with no code shared with `Png`.
  `read()` is a PHP array per pixel: use `raw()` + `pixel()` or `header()` for
  anything large, or the suite runs out of memory.

# Changelog

All notable changes are documented here, following
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes land in MINOR releases. Read each entry before upgrading.

## [Unreleased]

### Changed

- **Docs:** `AGENTS.md` is now the guide to using this package (install, its entries on the Fancy MCP catalog server, API essentials, traps). The contributor notes it held moved to `CONTRIBUTING.md`, and `CLAUDE.md` is now a symlink to it. The README points to the MCP server and to `AGENTS.md`. No code changed; nothing to do.
- CI: re-pin org third-party allowlist to 2614224

## [0.1.0] - 2026-11-04

### Added

- **QR codes on the server, with zero runtime dependencies** (PHP 8.4 and `ext-zlib`). A port of react-fancy's `<QrCode>` encoder: versions 1-40, levels L/M/Q/H, numeric / alphanumeric / byte modes chosen automatically, and the mask chosen by the four penalty rules with the same tie-break. The same input gives the same module matrix as react-fancy, held to by a fixture of 465 matrices generated from the TypeScript. **Consumer action:** none; this is the first release.
- **`QrCode::svg()`**: a standalone SVG with a `<title>`, an accessible name and an opaque background, byte for byte what react-fancy's `qrCodeSvg()` writes.
- **`QrCode::png()`**: a 1-bit indexed PNG on PHP's zlib, a whole number of pixels per module, at most 8192 pixels a side.
- **`QrCode::finePrintDocument()`**: a printable page as fine-print document data (the code as an image at 300 dpi or better, the value as text or a link). fine-print is a `suggest`, not a dependency.
- **`QrCode::encode()`** for the module matrix, and `Symbol::rows()`.
- **Hostile input is bounded.** A value over 7089 bytes is refused from its length before it is read; any value that does not fit throws `ValueTooLongException` rather than being truncated.

// Regenerates tests/fixtures/parity.json from react-fancy's TypeScript encoder.
//
//   node tests/fixtures/export-parity.mjs [path/to/react-fancy]
//
// The fixture is the CONTRACT this package is held to: the same input must
// produce the same module matrix as react-fancy's `encodeQr`, and the same SVG
// as its `qrCodeSvg`. It is generated from the TypeScript source, never from
// this package, so the PHP suite cannot agree with itself by construction.
//
// Needs a react-fancy checkout with node_modules (for esbuild). Default path is
// the sibling checkout in the Fancy envelope. The PHP suite does not need Node:
// it reads the committed JSON.
import { createRequire } from "node:module";
import { dirname, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { execFileSync } from "node:child_process";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";

const here = dirname(fileURLToPath(import.meta.url));
const reactFancy = resolve(process.argv[2] ?? resolve(here, "../../../react-fancy"));
const esbuild = createRequire(resolve(reactFancy, "package.json"))("esbuild");

const scratch = mkdtempSync(resolve(tmpdir(), "fancy-qr-parity-"));
const bundle = resolve(scratch, "encoder.mjs");
await esbuild.build({
  stdin: {
    contents: [
      'export { encodeQr } from "./src/components/QrCode/qr-encode";',
      'export { qrCodeSvg } from "./src/components/QrCode/qr-export";',
      'export { blockLayout } from "./src/components/QrCode/qr-tables";',
    ].join("\n"),
    resolveDir: reactFancy,
    loader: "ts",
  },
  bundle: true,
  format: "esm",
  platform: "node",
  outfile: bundle,
  logLevel: "error",
});
const { encodeQr, qrCodeSvg, blockLayout } = await import(pathToFileURL(bundle).href);
rmSync(scratch, { recursive: true, force: true });

const pkg = JSON.parse(readFileSync(resolve(reactFancy, "package.json"), "utf8"));
const commit = execFileSync("git", ["-C", reactFancy, "rev-parse", "--short", "HEAD"], { encoding: "utf8" }).trim();

/** One row per string of `0`/`1`. */
const rows = (modules) => modules.map((row) => row.map((dark) => (dark ? "1" : "0")).join(""));
const digest = (modules) => createHash("sha256").update(rows(modules).join("\n")).digest("hex");

function entry(value, options, full) {
  const symbol = encodeQr(value, options);
  const out = {
    value,
    options,
    version: symbol.version,
    mode: symbol.mode,
    mask: symbol.mask,
    size: symbol.size,
    sha256: digest(symbol.modules),
  };
  if (full) {
    out.rows = rows(symbol.modules);
  }
  return out;
}

// The standard's 5-Q worked example. The input really contains a backslash
// before the apostrophe: 53 bytes, recovered from the published codewords.
const GOLDEN = "There\\'s a frood who really knows where his towel is.";

const values = [
  "",
  "0",
  "482913",
  "0012345",
  "12345678901234567890",
  "SN-4829-13",
  "HELLO WORLD",
  "A",
  "$%*+-./: ",
  "hello world",
  "sn-4829-13",
  "https://example.com/r/AbC123",
  "https://example.com/r/7hK2pQ9xZ",
  "WIFI:T:WPA;S:Guest;P:hunter2;;",
  "MECARD:N:Okafor,Ada;TEL:+15550100;EMAIL:ada@example.com;;",
  "tel:+15550100",
  "geo:51.5033,-0.1196",
  "x",
  GOLDEN,
  "héllo — 世界 🜛",
  "ÿþ\u0000\u0001 binary-ish",
  "y".repeat(400),
];

const cases = [];
for (const ec of ["L", "M", "Q", "H"]) {
  for (const value of values) {
    cases.push(entry(value, { errorCorrection: ec }, true));
  }
}

// Every mask, forced, on one value: mask APPLICATION parity, independent of
// which mask the penalty scoring would pick.
for (let mask = 0; mask < 8; mask += 1) {
  cases.push(entry("https://example.com/r/AbC123", { errorCorrection: "M", mask }, true));
}

// Forced modes and versions.
cases.push(entry("123", { mode: "alphanumeric" }, true));
cases.push(entry("123", { mode: "byte" }, true));
cases.push(entry("HELLO", { mode: "byte", errorCorrection: "Q" }, true));
cases.push(entry("x", { version: 7, errorCorrection: "L" }, true));
cases.push(entry("x", { version: 10, errorCorrection: "H" }, true));

// Large: hashes only, to keep the fixture small. A hash is exactly as strict.
cases.push(entry("z".repeat(1200), { errorCorrection: "M" }, false));
cases.push(entry("7".repeat(7089), { errorCorrection: "L" }, false));
cases.push(entry("A".repeat(4296), { errorCorrection: "L" }, false));
cases.push(entry("a".repeat(2953), { errorCorrection: "L" }, false));

// Every version at every level, sized to force it.
for (const ec of ["L", "M", "Q", "H"]) {
  for (let version = 1; version <= 40; version += 1) {
    const capacity = blockLayout(version, ec).dataCodewords - 3;
    cases.push(entry("a".repeat(capacity), { errorCorrection: ec, version }, false));
  }
}

// The shape a sign-up or referral link takes, many times over: the use case
// most likely to reach the server, and enough variety that the penalty scoring
// lands on every one of the eight masks.
for (let i = 0; i < 200; i += 1) {
  const token = ((i + 1) * 2654435761 % 4294967291).toString(36);
  cases.push(entry(`https://example.com/r/${token}`, { errorCorrection: i % 2 === 0 ? "M" : "Q" }, false));
}

const masks = new Set(cases.filter((c) => c.options.mask === undefined).map((c) => c.mask));
if (masks.size !== 8) {
  throw new Error(`the automatic cases choose only masks ${[...masks].sort().join(",")}; add values until all 8 appear`);
}
const modes = new Set(cases.map((c) => c.mode));
if (modes.size !== 3) {
  throw new Error(`only modes ${[...modes].join(",")} appear`);
}

// SVG files, byte for byte.
const svgCases = [
  ["https://example.com/r/AbC123", { label: "Your sign-up link" }],
  ["SN-4829-13", { label: "Asset tag for pump 14", errorCorrection: "Q", quietZone: 6, size: 300 }],
  ["482913", { label: "Order reference", foreground: "#1a1a2e", background: "#fffbe6" }],
  ["x", { label: "inherit", foreground: "currentColor", background: "transparent" }],
  ["héllo", { label: "</title><script>alert(1)</script> & \"quotes\" 'apostrophes'" }],
  // C0 controls XML cannot carry are dropped; tab and newline are kept.
  ["SN-1", { label: ["bell", "tab", "newline", "end"].join(String.fromCharCode(7)) + String.fromCharCode(9) + "x" + String.fromCharCode(10) + "y" }],
].map(([value, options]) => ({ value, options, svg: qrCodeSvg(value, options) }));

const fixture = {
  generatedFrom: `@particle-academy/react-fancy ${pkg.version} (${commit})`,
  generator: "tests/fixtures/export-parity.mjs",
  note: "rows: one string per module row, 1 = dark. sha256: of the rows joined by a newline.",
  masksCovered: [...masks].sort(),
  cases,
  svg: svgCases,
};

const target = resolve(here, "parity.json");
writeFileSync(target, `${JSON.stringify(fixture, null, 1)}\n`);
console.log(`wrote ${cases.length} matrix cases and ${svgCases.length} SVG cases to ${target}`);
console.log(`masks chosen automatically: ${[...masks].sort().join(", ")}`);

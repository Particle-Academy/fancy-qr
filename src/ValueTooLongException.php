<?php

declare(strict_types=1);

namespace FancyQr;

/**
 * The value does not fit in a QR code at the requested level, mode or version.
 *
 * Thrown rather than truncating: a truncated symbol scans cleanly and yields
 * the wrong string. The largest capacities, at version 40 level L, are 7089
 * digits, 4296 alphanumeric characters or 2953 bytes; a higher level holds
 * less.
 */
final class ValueTooLongException extends QrException {}

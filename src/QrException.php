<?php

declare(strict_types=1);

namespace FancyQr;

use InvalidArgumentException;

/** An input this package refuses: an unknown option, or a value a mode cannot carry. */
class QrException extends InvalidArgumentException {}

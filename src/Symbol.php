<?php

declare(strict_types=1);

namespace FancyQr;

/** An encoded QR symbol: the module matrix and how it was built. */
final readonly class Symbol
{
    /**
     * @param  list<list<bool>>  $modules  `size` rows of `size` modules; true is dark
     * @param  'L'|'M'|'Q'|'H'  $errorCorrection
     * @param  'numeric'|'alphanumeric'|'byte'  $mode
     */
    public function __construct(
        public array $modules,
        public int $size,
        public int $version,
        public string $errorCorrection,
        public string $mode,
        public int $mask,
    ) {}

    /**
     * One string per row, `1` for dark and `0` for light. The shape the parity
     * fixture uses, and handy for a terminal or a test.
     *
     * @return list<string>
     */
    public function rows(): array
    {
        return array_map(
            static fn (array $row): string => implode('', array_map(static fn (bool $dark): string => $dark ? '1' : '0', $row)),
            $this->modules,
        );
    }
}

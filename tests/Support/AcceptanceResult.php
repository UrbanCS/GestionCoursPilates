<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Support;

final class AcceptanceResult
{
    /**
     * @param array<string, scalar|null> $evidence Sanitized identifiers only.
     */
    public function __construct(
        public readonly bool $passed,
        public readonly string $summary,
        public readonly array $evidence = [],
    ) {
    }
}

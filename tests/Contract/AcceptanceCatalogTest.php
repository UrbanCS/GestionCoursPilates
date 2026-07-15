<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class AcceptanceCatalogTest extends TestCase
{
    public function testCatalogContainsEveryRequiredScenarioExactlyOnce(): void
    {
        /** @var array<string, array<string, mixed>> $scenarios */
        $scenarios = require dirname(__DIR__) . '/Fixtures/acceptance-scenarios.php';

        self::assertCount(26, $scenarios);
        self::assertSame(
            array_map(
                static fn (int $number): string => sprintf('AT-%02d', $number),
                range(1, 26)
            ),
            array_keys($scenarios)
        );
    }

    public function testEveryScenarioDefinesAStableContract(): void
    {
        /** @var array<string, array<string, mixed>> $scenarios */
        $scenarios = require dirname(__DIR__) . '/Fixtures/acceptance-scenarios.php';

        foreach ($scenarios as $id => $scenario) {
            self::assertNotSame('', $scenario['title'] ?? '', $id);
            self::assertNotSame('', $scenario['expected'] ?? '', $id);
            self::assertIsArray($scenario['layers'] ?? null, $id);
            self::assertNotEmpty($scenario['layers'], $id);
        }
    }
}

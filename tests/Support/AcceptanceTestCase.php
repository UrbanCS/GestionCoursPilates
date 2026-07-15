<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Support;

use PHPUnit\Framework\TestCase;

abstract class AcceptanceTestCase extends TestCase
{
    private ?AcceptanceDriver $driver = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function scenarios(): array
    {
        /** @var array<string, array<string, mixed>> $scenarios */
        $scenarios = require dirname(__DIR__) . '/Fixtures/acceptance-scenarios.php';

        return $scenarios;
    }

    protected function runScenario(string $scenarioId): void
    {
        $scenarios = $this->scenarios();
        self::assertArrayHasKey($scenarioId, $scenarios, 'Scenario must be defined in the acceptance catalog.');

        $driver = $this->driver();
        if ($driver === null) {
            self::markTestSkipped(
                'No isolated acceptance driver configured. '
                . 'Set MEMIPILATES_ACCEPTANCE_DRIVER only for a non-production test environment.'
            );
        }

        $driver->reset($scenarioId);
        $result = $driver->run($scenarioId, $scenarios[$scenarioId]);

        self::assertTrue($result->passed, $result->summary);
    }

    private function driver(): ?AcceptanceDriver
    {
        if ($this->driver instanceof AcceptanceDriver) {
            return $this->driver;
        }

        $class = getenv('MEMIPILATES_ACCEPTANCE_DRIVER');
        if (!is_string($class) || $class === '') {
            return null;
        }

        if (!class_exists($class)) {
            self::fail(sprintf('Configured acceptance driver "%s" is not autoloadable.', $class));
        }

        $driver = new $class();
        if (!$driver instanceof AcceptanceDriver) {
            self::fail(sprintf('Configured driver "%s" must implement AcceptanceDriver.', $class));
        }

        $this->driver = $driver;

        return $this->driver;
    }
}

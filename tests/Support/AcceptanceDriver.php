<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Support;

/**
 * Adapter boundary for acceptance tests.
 *
 * A concrete driver may use Joomla services, HTTP, a browser automation tool,
 * CLI commands, or a mix of these. It must use an isolated test environment.
 */
interface AcceptanceDriver
{
    /**
     * Clears or rolls back the fixture state before an acceptance scenario.
     */
    public function reset(string $scenarioId): void;

    /**
     * Runs one named scenario from Fixtures/acceptance-scenarios.php.
     */
    public function run(string $scenarioId, array $definition): AcceptanceResult;
}

<?php

namespace Symphoria\Apex\Services\Bench;

use InvalidArgumentException;
use Symphoria\Apex\Services\Bench\Scenarios\BenchScenario;
use Symphoria\Apex\Services\Bench\Scenarios\NoOpScenario;
use Symphoria\Apex\Services\Bench\Scenarios\ToolQueueScenario;

class ScenarioRegistry
{
    /**
     * Application-specific scenarios are registered by the host through
     * register(); the package only ships ones that need no domain models.
     *
     * @var array<string, class-string<BenchScenario>>
     */
    private array $scenarios = [
        'noop' => NoOpScenario::class,
        'tool-queue' => ToolQueueScenario::class,
    ];

    public function register(string $name, string $class): void
    {
        $this->scenarios[$name] = $class;
    }

    public function names(): array
    {
        return array_keys($this->scenarios);
    }

    public function resolve(string $name): BenchScenario
    {
        if (! isset($this->scenarios[$name])) {
            throw new InvalidArgumentException("Unknown bench scenario: {$name}. Available: ".implode(', ', $this->names()));
        }

        return app($this->scenarios[$name]);
    }
}

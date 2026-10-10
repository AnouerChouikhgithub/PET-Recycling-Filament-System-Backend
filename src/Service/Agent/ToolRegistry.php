<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Service\Agent\Tools\ExplainFaultCodeTool;
use App\Service\Agent\Tools\GetAuditLogTool;
use App\Service\Agent\Tools\GetLatestTelemetryTool;
use App\Service\Agent\Tools\GetRecentEventsTool;
use App\Service\Agent\Tools\ListMyMachinesTool;

/**
 * The COMPLETE set of tools the model may call in phase 1. Exactly five
 * READ-ONLY tools - locked by tests/Agent/ToolRegistryLockTest.php (T4.1):
 * the test fails if any name is added, renamed, or removed, so no write /
 * heater / PWM tool can ever appear without a deliberate, reviewed change.
 */
final class ToolRegistry
{
    /** @var array<string, AgentTool> name => tool */
    private array $tools;

    public function __construct(
        ListMyMachinesTool $listMyMachines,
        GetLatestTelemetryTool $getLatestTelemetry,
        GetRecentEventsTool $getRecentEvents,
        ExplainFaultCodeTool $explainFaultCode,
        GetAuditLogTool $getAuditLog,
    ) {
        $built = [
            $listMyMachines,
            $getLatestTelemetry,
            $getRecentEvents,
            $explainFaultCode,
            $getAuditLog,
        ];

        $this->tools = [];
        foreach ($built as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /** @return list<string> exact names, order-stable for the LLM payload */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?AgentTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * Tool definitions array for the LLM request (name, description, schema).
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->inputSchema(),
            ];
        }

        return $definitions;
    }
}

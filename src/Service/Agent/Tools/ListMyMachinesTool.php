<?php

declare(strict_types=1);

namespace App\Service\Agent\Tools;

use App\Service\Agent\AgentTool;
use App\Service\Machine\MachineQueryService;

/**
 * OWNER-SCOPED list: only machines the CURRENT user owns. The strict query
 * avoids the REST API admin bypass in MachineAccess.
 */
final class ListMyMachinesTool implements AgentTool
{
    public function __construct(
        private readonly MachineQueryService $queryService,
    ) {
    }

    public function name(): string
    {
        return 'list_my_machines';
    }

    public function description(): string
    {
        return 'List the machines owned by the current user with effective status and last-seen age.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['idle', 'heating', 'extruding', 'paused', 'error', 'offline'],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        $machines = $this->queryService->listMachinesStrict();
        $status = $input['status'] ?? null;

        $data = [];
        foreach ($machines as $machine) {
            if (\is_string($status) && '' !== $status && 'offline' !== $status && $machine->getStatus()->value !== $status) {
                continue;
            }

            $lastSeen = $machine->getLastSeenAt();
            $data[] = [
                'id' => $machine->getId()->toRfc4122(),
                'name' => $machine->getName(),
                'identifier' => $machine->getIdentifier(),
                'status' => $machine->getStatus()->value,
                'lastSeenAt' => null !== $lastSeen ? $lastSeen->format(\DateTimeInterface::ATOM) : null,
                'secondsSinceLastSeen' => null !== $lastSeen ? max(0, time() - $lastSeen->getTimestamp()) : null,
            ];
        }

        return ['ok' => true, 'data' => $data];
    }
}

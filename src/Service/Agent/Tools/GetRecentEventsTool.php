<?php

declare(strict_types=1);

namespace App\Service\Agent\Tools;

use App\Entity\MachineCommandAudit;
use App\Repository\MachineCommandAuditRepository;
use App\Service\Agent\AgentTool;
use App\Service\Machine\MachineQueryService;

/**
 * Recent events for ONE owned machine. There is no DB store for the MQTT
 * inbound "events" branch yet, so events = this machine's COMMAND AUDIT
 * history (docs/ai-agent-design.md section 4.3). Ownership in code, same as
 * the other tools.
 */
final class GetRecentEventsTool implements AgentTool
{
    public function __construct(
        private readonly MachineQueryService $queryService,
        private readonly MachineCommandAuditRepository $audits,
    ) {
    }

    public function name(): string
    {
        return 'get_recent_events';
    }

    public function description(): string
    {
        return 'Recent events of one of the user\'s machines: today that means its command audit history (no device event store exists yet).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'machineId' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ],
            'required' => ['machineId'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        $machineId = $input['machineId'] ?? null;
        if (!\is_string($machineId) || '' === $machineId) {
            return ['ok' => false, 'code' => 'VALIDATION_FAILED'];
        }

        $limit = $input['limit'] ?? 10;
        $limit = \is_int($limit) ? $limit : 10;
        $limit = max(1, min(20, $limit));

        try {
            $machine = $this->queryService->getMachine($machineId);
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'MACHINE_NOT_FOUND'];
        }

        $rows = $this->audits->createQueryBuilder('a')
            ->where('a.machine = :machine')
            ->setParameter('machine', $machine)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $data = array_map(static fn (array $r): array => [
            'commandId' => (string) $r['id'],
            'command' => $r['command'],
            'value' => $r['value'],
            'transport' => $r['transport'],
            'createdAt' => $r['createdAt'] instanceof \DateTimeInterface ? $r['createdAt']->format(\DateTimeInterface::ATOM) : null,
        ], $rows);

        return ['ok' => true, 'data' => $data, 'note' => 'events are command audit rows; device event payloads are not persisted yet'];
    }
}

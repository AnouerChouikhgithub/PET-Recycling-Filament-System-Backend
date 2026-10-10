<?php

declare(strict_types=1);

namespace App\Service\Agent\Tools;

use App\Entity\User;
use App\Repository\MachineCommandAuditRepository;
use App\Service\Agent\AgentTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The CURRENT USER'S OWN command audit history, optionally filtered to one
 * machine they own. The query is pinned to the audit rows whose machine
 * belongs to the user - machineId is joined against ownership so a foreign
 * machineId is silently EMPTY (never another user's rows).
 */
final class GetAuditLogTool implements AgentTool
{
    public function __construct(
        private readonly MachineCommandAuditRepository $audits,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function name(): string
    {
        return 'get_audit_log';
    }

    public function description(): string
    {
        return 'The user\'s own command audit history (which commands were sent, to which of their machines, when).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'machineId' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $input): array
    {
        $user = $this->currentUser();
        if (null === $user) {
            return ['ok' => false, 'code' => 'UNAUTHORIZED'];
        }

        $limit = $input['limit'] ?? 20;
        $limit = \is_int($limit) ? $limit : 20;
        $limit = max(1, min(50, $limit));

        $qb = $this->audits->createQueryBuilder('a')
            ->join('a.machine', 'm')
            ->where('m.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        // Optional machine filter: harmless either way - the owner pin on
        // the JOIN already bounds every row to the user's machines.
        $machineId = $input['machineId'] ?? null;
        if (\is_string($machineId) && '' !== $machineId) {
            $qb->andWhere('m.id = :mid')->setParameter('mid', $machineId, 'uuid');
        }

        $rows = $qb->select('a.id', 'a.command', 'a.value', 'a.transport', 'a.createdAt', 'm.identifier', 'm.name')
            ->getQuery()
            ->getArrayResult();

        $data = array_map(static fn (array $r): array => [
            'commandId' => (string) $r['id'],
            'command' => $r['command'],
            'value' => $r['value'],
            'transport' => $r['transport'],
            'machineIdentifier' => $r['identifier'],
            'machineName' => $r['name'],
            'createdAt' => $r['createdAt'] instanceof \DateTimeInterface ? $r['createdAt']->format(\DateTimeInterface::ATOM) : null,
        ], $rows);

        return ['ok' => true, 'data' => $data];
    }

    private function currentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}

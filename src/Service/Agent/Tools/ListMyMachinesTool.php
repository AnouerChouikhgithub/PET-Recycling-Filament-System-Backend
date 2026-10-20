<?php

declare(strict_types=1);

namespace App\Service\Agent\Tools;

use App\Entity\User;
use App\Repository\MachineRepository;
use App\Service\Agent\AgentTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * OWNER-SCOPED list: only machines the CURRENT user owns. DECIDED (session
 * 1): admin users get NO agent-wide view - MachineAccess::visibleMachines()
 * is deliberately NOT used here because it returns everything for admins.
 */
final class ListMyMachinesTool implements AgentTool
{
    public function __construct(
        private readonly MachineRepository $machines,
        private readonly TokenStorageInterface $tokenStorage,
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
        $user = $this->currentUser();
        if (null === $user) {
            return ['ok' => false, 'code' => 'UNAUTHORIZED'];
        }

        $qb = $this->machines->createQueryBuilder('m')
            ->select('m.id', 'm.name', 'm.identifier', 'm.status', 'm.lastSeenAt')
            ->where('m.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('m.createdAt', 'ASC');

        // Optional user-selected filter. Only known values pass; anything
        // else is ignored (never interpolated).
        $status = $input['status'] ?? null;
        if (\is_string($status) && '' !== $status && 'offline' !== $status) {
            $qb->andWhere($qb->expr()->eq('m.status', ':st'))->setParameter('st', $status);
        }

        $rows = $qb->getQuery()->getArrayResult();

        $data = [];
        foreach ($rows as $row) {
            $lastSeen = $row['lastSeenAt'];
            $data[] = [
                'id' => $row['id'] instanceof \Stringable ? (string) $row['id'] : $row['id'],
                'name' => $row['name'],
                'identifier' => $row['identifier'],
                'status' => $row['status'],
                'lastSeenAt' => null !== $lastSeen ? $lastSeen->format(\DateTimeInterface::ATOM) : null,
                'secondsSinceLastSeen' => null !== $lastSeen ? max(0, time() - $lastSeen->getTimestamp()) : null,
            ];
        }

        return ['ok' => true, 'data' => $data];
    }

    private function currentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}

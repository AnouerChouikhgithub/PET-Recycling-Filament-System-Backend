<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\Exception\ResourceNotFoundException;
use App\Entity\Machine;
use App\Entity\User;
use App\Repository\MachineRepository;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Machine ownership — the single enforcement point for every /api/machines/*
 * route (list, detail, status, dashboard, telemetry, sessions, production,
 * recycling, commands).
 *
 * Rules:
 *   - ROLE_ADMIN sees every machine;
 *   - regular users see only machines they own;
 *   - a machine another user owns (or no user owns) yields the SAME 404
 *     envelope as an unknown id — never a 403 — so machine UUIDs cannot be
 *     enumerated.
 *
 * Services call this instead of touching the repository directly; controllers
 * stay thin.
 */
final class MachineAccess
{
    public function __construct(
        private readonly MachineRepository $machines,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * Device-surface access: the authenticated principal IS the machine
     * (DeviceTokenAuthenticator builds a DeviceUser from the token's machine).
     * Binding the token's machine to the URL's machine id here means a token
     * issued for machine 1 is worthless on machine 2's endpoints — by
     * construction, not by convention.
     *
     * @throws ResourceNotFoundException
     */
    public function getDeviceMachine(string $id, UserInterface $principal): Machine
    {
        $machine = $this->machines->find($id);

        if (null === $machine
            || $machine->getIdentifier() !== $principal->getUserIdentifier()
        ) {
            // Same 404 envelope as everywhere else — no enumeration, no 403.
            throw new ResourceNotFoundException('Machine not found.', 'MACHINE_NOT_FOUND');
        }

        return $machine;
    }

    /**
     * Telemetry ingest: a DEVICE principal is bound to exactly its own
     * machine; a USER principal keeps normal ownership rules (admin sees all).
     *
     * @throws ResourceNotFoundException
     */
    public function getMachineForDeviceOrOwner(string $id): Machine
    {
        $token = $this->tokenStorage->getToken();
        $principal = $token?->getUser();

        if ($principal instanceof DeviceUser) {
            return $this->getDeviceMachine($id, $principal);
        }

        return $this->getOwnedMachine($id);
    }

    /** Machines visible to the current user (all of them for admins).
     *
     * @return list<Machine>
     */
    public function visibleMachines(): array
    {
        $user = $this->currentUser();

        if (null === $user) {
            return [];
        }

        if ($this->isAdmin($user)) {
            return $this->machines->findBy([], ['createdAt' => 'ASC']);
        }

        return $this->machines->findByOwner($user);
    }

    /**
     * The machine with this id IF the current user may see it, otherwise the
     * same "Machine not found." error an unknown id produces.
     *
     * @throws ResourceNotFoundException
     */
    public function getOwnedMachine(string $id): Machine
    {
        $user = $this->currentUser();
        $machine = $this->machines->find($id);

        $allowed = null !== $machine
            && null !== $user
            && ($this->isAdmin($user) || $machine->getOwner()?->getId()->equals($user->getId()));

        if (!$allowed) {
            throw new ResourceNotFoundException('Machine not found.', 'MACHINE_NOT_FOUND');
        }

        return $machine;
    }

    private function currentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function isAdmin(UserInterface $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Machine;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adapts a Machine to the security UserInterface so the device firewall can
 * authenticate a MACHINE (not a user) on the IoT surface. Deliberately holds
 * no roles: devices are not users.
 */
final class DeviceUser implements UserInterface
{
    public function __construct(private readonly Machine $machine)
    {
    }

    public function getMachine(): Machine
    {
        return $this->machine;
    }

    public function getRoles(): array
    {
        return ['ROLE_DEVICE'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->machine->getIdentifier();
    }
}

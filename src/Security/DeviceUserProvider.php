<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads the DeviceUser for the device firewall. The identifier is the machine
 * identifier carried by the UserBadge; the machine instance itself was already
 * resolved by DeviceTokenAuthenticator, so this provider only adapts it.
 */
/**
 * @implements UserProviderInterface<DeviceUser>
 */
final class DeviceUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // DeviceTokenAuthenticator always passes a closure resolving to the
        // machine, so this path only runs if that badge was misconfigured.
        throw new UserNotFoundException(sprintf('Machine "%s" could not be loaded as a device.', $identifier));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof DeviceUser) {
            throw new \InvalidArgumentException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        return $user; // stateless — nothing to refresh
    }

    public function supportsClass(string $class): bool
    {
        return DeviceUser::class === $class;
    }
}

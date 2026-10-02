<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\DependencyInjection\Security\Factory\AuthenticatorFactoryInterface;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers the device-token authenticator (App\Security\DeviceTokenAuthenticator)
 * on any firewall configured with `device-token: ~` — here, the IoT surface
 * (POST /api/machines/{id}/telemetry).
 */
final class DeviceTokenAuthenticatorFactory implements AuthenticatorFactoryInterface
{
    public function getPriority(): int
    {
        return -10; // after nothing else matters: the device firewall has a single authenticator
    }

    public function getKey(): string
    {
        return 'device-token';
    }

    public function addConfiguration(NodeDefinition $builder): void
    {
        // No per-firewall options on purpose: header name and hashing are fixed
        // by App\Entity\DeviceToken so every environment behaves identically.
    }

    public function createAuthenticator(ContainerBuilder $container, string $firewallName, array $config, string $userProviderId): string
    {
        $authenticatorId = 'security.authenticator.device_token.'.$firewallName;

        $container->setDefinition($authenticatorId, new ChildDefinition(DeviceTokenAuthenticator::class));

        return $authenticatorId;
    }
}

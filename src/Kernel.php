<?php

namespace App;

use App\Security\DeviceTokenAuthenticatorFactory;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\DependencyInjection\SecurityExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);

        // Every stored timestamp and every comparison is UTC. Enforcing this
        // here also makes local Docker/Windows runs match production.
        date_default_timezone_set('UTC');
    }

    protected function build(ContainerBuilder $container): void
    {
        // Registers the `device-token` firewall key (App\Security\DeviceTokenAuthenticator):
        // per-machine hashed API tokens authenticate the IoT surface.
        /** @var SecurityExtension $security */
        $security = $container->getExtension('security');
        $security->addAuthenticatorFactory(new DeviceTokenAuthenticatorFactory());
    }
}

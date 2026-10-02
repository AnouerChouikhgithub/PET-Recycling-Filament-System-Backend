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

    protected function build(ContainerBuilder $container): void
    {
        // Registers the `device-token` firewall key (App\Security\DeviceTokenAuthenticator):
        // per-machine hashed API tokens authenticate the IoT surface.
        /** @var SecurityExtension $security */
        $security = $container->getExtension('security');
        $security->addAuthenticatorFactory(new DeviceTokenAuthenticatorFactory());
    }
}

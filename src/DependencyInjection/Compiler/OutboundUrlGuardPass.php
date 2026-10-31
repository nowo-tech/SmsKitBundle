<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\DependencyInjection\Compiler;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Registers a default {@see OutboundUrlGuard} when NowoOutboundUrlGuardBundle is not registered (applications without
 * Symfony Flex), so the provider HTTP client always has its guard. The bundle's own service, when present, wins.
 * SmsKit passes `allow_private` and `resolve_dns` on every call, so only the DNS timeout of the guard bundle applies.
 */
final class OutboundUrlGuardPass implements CompilerPassInterface
{
    /**
     * Adds the fallback guard definition when missing.
     *
     * @param ContainerBuilder $container container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->has(OutboundUrlGuard::class)) {
            return;
        }

        $container->setDefinition(OutboundUrlGuard::class, new Definition(OutboundUrlGuard::class));
    }
}

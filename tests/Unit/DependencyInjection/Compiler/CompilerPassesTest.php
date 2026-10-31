<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\DependencyInjection\Compiler\OutboundUrlGuardPass;
use Nowo\SmsKitBundle\DependencyInjection\Compiler\TestActionPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class CompilerPassesTest extends TestCase
{
    private function container(bool $controller = true, bool $tokenManager = true, bool $formCsrf = true, ?bool $csrfEnabled = null, bool $checker = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($controller) {
            $container->setDefinition(SendTestSmsController::class, new Definition(SendTestSmsController::class));
        }
        if ($tokenManager) {
            $container->setDefinition('security.csrf.token_manager', new Definition('stdClass'));
        }
        if ($formCsrf) {
            $container->setDefinition('form.type_extension.csrf', new Definition('stdClass'));
        }
        if ($csrfEnabled !== null) {
            $container->setParameter('form.type_extension.csrf.enabled', $csrfEnabled);
        }
        if ($checker) {
            $container->setDefinition(InstanceSettingsAccessCheckerInterface::class, new Definition('stdClass'));
        }

        return $container;
    }

    public function testTestActionPassAcceptsAProtectedSetup(): void
    {
        (new TestActionPass())->process($this->container(csrfEnabled: true));
        (new TestActionPass())->process($this->container(false, false, false, false, false));
        $this->addToAssertionCount(1);
    }

    public function testTestActionPassFailsClosedWithoutCsrf(): void
    {
        foreach ([$this->container(tokenManager: false), $this->container(formCsrf: false), $this->container(csrfEnabled: false)] as $container) {
            try {
                (new TestActionPass())->process($container);
                self::fail('Expected a CSRF failure.');
            } catch (LogicException $e) {
                self::assertStringContainsString('requires CSRF protection', $e->getMessage());
            }
        }
    }

    public function testTestActionPassNeedsTheAccessChecker(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_instance_settings.web_ui.enabled: true');
        (new TestActionPass())->process($this->container(checker: false));
    }

    public function testOutboundUrlGuardFallback(): void
    {
        $container = new ContainerBuilder();
        (new OutboundUrlGuardPass())->process($container);
        self::assertSame(OutboundUrlGuard::class, $container->getDefinition(OutboundUrlGuard::class)->getClass());

        $existing = new Definition(OutboundUrlGuard::class, [true]);
        $container->setDefinition(OutboundUrlGuard::class, $existing);
        (new OutboundUrlGuardPass())->process($container);
        self::assertSame($existing, $container->getDefinition(OutboundUrlGuard::class));
    }
}

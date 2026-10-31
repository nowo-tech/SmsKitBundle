<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the send-test action is enabled without form CSRF protection (REQ-SEC-005, pattern P2):
 * `form.type_extension.csrf`, the token manager, and `framework.form.csrf_protection` not false. (The instance settings
 * access checker is always registered by InstanceSettingsBundle 1.0.)
 */
final class TestActionPass implements CompilerPassInterface
{
    /**
     * Throws when the controller is registered and form CSRF is unavailable.
     *
     * @param ContainerBuilder $container container being compiled
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SendTestSmsController::class)) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;
        if (!$container->has('security.csrf.token_manager') || !$container->hasDefinition('form.type_extension.csrf') || $formCsrfDisabled) {
            throw new LogicException('NowoSmsKitBundle: the send-test action (test_action.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection, or set nowo_sms_kit.test_action.enabled: false.');
        }

    }
}

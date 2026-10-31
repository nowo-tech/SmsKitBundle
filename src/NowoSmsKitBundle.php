<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle;

use Nowo\SmsKitBundle\DependencyInjection\Compiler\OutboundUrlGuardPass;
use Nowo\SmsKitBundle\DependencyInjection\Compiler\TestActionPass;
use Nowo\SmsKitBundle\DependencyInjection\NowoSmsKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * SMS sending for Symfony: a registry of providers (Twilio, SMS Bridge, log, null and host providers), the provider and
 * its credentials stored in the `sms` instance settings section (secrets encrypted), a test command, a send-test admin
 * action and an AuthKit password-reset adapter.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoSmsKitBundle extends Bundle
{
    /** Translation domain of the bundle copy (REQ-I18N-003). */
    public const TRANSLATION_DOMAIN = 'NowoSmsKitBundle';

    /** Service tag of the SMS providers ({@see Provider\SmsProviderInterface}), autoconfigured. */
    public const PROVIDER_TAG = 'nowo_sms_kit.provider';

    /**
     * Registers the outbound URL guard fallback and the fail-closed CSRF guard of the send-test admin action.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new OutboundUrlGuardPass());
        $container->addCompilerPass(new TestActionPass());
    }

    /**
     * The extension alias is `nowo_sms_kit`.
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoSmsKitExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}

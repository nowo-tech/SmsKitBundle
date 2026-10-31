<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Provider;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Nowo\SmsKitBundle\Settings\SmsConfig;

/**
 * An SMS transport. Every service implementing this interface is autoconfigured with the tag
 * {@see NowoSmsKitBundle::PROVIDER_TAG} and becomes a choice of the `sms.provider` setting.
 *
 * Implementations must be stateless (worker-safe): the configuration of the current send is passed in, never stored.
 * Host providers that need their own credentials read them from their own settings section or configuration.
 */
interface SmsProviderInterface
{
    /**
     * Stable provider name (lower snake case, unique): the value stored in `sms.provider`.
     */
    public function getName(): string;

    /**
     * Translation key (domain `NowoSmsKitBundle`) or plain text of the provider choice label.
     */
    public function getLabel(): string;

    /**
     * Whether the configuration holds everything this provider needs to send.
     *
     * @param SmsConfig $config effective configuration
     */
    public function isConfigured(SmsConfig $config): bool;

    /**
     * Sends one message. The sender has already normalized the destination (E.164), the body (trimmed, not empty)
     * and the sender id.
     *
     * @param SmsMessage $message normalized message
     * @param SmsConfig $config effective configuration
     *
     * @throws SmsSendException when the provider is not configured, refuses the message or cannot be reached
     */
    public function send(SmsMessage $message, SmsConfig $config): SmsSendResult;
}

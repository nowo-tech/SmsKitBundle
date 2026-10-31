<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Sender;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;

/**
 * Instance-wide SMS delivery (one-time codes, notifications, test sends). The provider and its configuration are
 * resolved on every call, so a change in the admin UI applies to the next message.
 */
interface SmsSenderInterface
{
    /**
     * Sends one message through the active provider.
     *
     * @param SmsMessage $message destination, body and optional overrides
     *
     * @throws SmsSendException when the provider is not configured, the number / body is invalid or the provider fails
     */
    public function send(SmsMessage $message): SmsSendResult;

    /**
     * Whether the active provider is fully configured (a send would be attempted).
     */
    public function isConfigured(): bool;

    /**
     * Name of the active provider (`null`, `log`, `twilio`, `sms_bridge`, or a host provider).
     */
    public function getProviderId(): string;
}

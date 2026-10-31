<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Provider;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Settings\SmsConfig;

/**
 * SMS disabled: never configured, every send fails. The default provider, so a fresh install sends nothing.
 */
final class NullSmsProvider implements SmsProviderInterface
{
    /** Provider name. */
    public const NAME = 'null';

    /**
     * `null`.
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Choice label key.
     */
    public function getLabel(): string
    {
        return 'settings.sms.provider_null';
    }

    /**
     * Never configured.
     *
     * @param SmsConfig $config effective configuration (unused)
     */
    public function isConfigured(SmsConfig $config): bool
    {
        return false;
    }

    /**
     * Always throws.
     *
     * @param SmsMessage $message message (unused)
     * @param SmsConfig $config effective configuration (unused)
     *
     * @throws SmsSendException always
     */
    public function send(SmsMessage $message, SmsConfig $config): SmsSendResult
    {
        throw new SmsSendException('SMS sending is disabled (provider "null").');
    }
}

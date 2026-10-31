<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Message;

/**
 * Answer of a provider that accepted (queued or sent) a message.
 *
 * `raw` is the decoded provider response: it may contain the destination number, so callers must not log it.
 */
final readonly class SmsSendResult
{
    /**
     * @param string $providerId provider name (`twilio`, `sms_bridge`, `log`, …)
     * @param string $providerMessageId message id assigned by the provider ('' when it returns none)
     * @param string $status provider status (`queued`, `sent`, `logged`, …)
     * @param array<string, mixed> $raw decoded provider response body
     */
    public function __construct(
        public string $providerId,
        public string $providerMessageId,
        public string $status,
        public array $raw = [],
    ) {
    }
}

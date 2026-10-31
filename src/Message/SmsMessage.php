<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Message;

/**
 * Outbound SMS: an E.164 destination and a body, plus optional per-message overrides understood by the providers that
 * support them (SMS Bridge device id, client idempotency id, delivery status callback).
 */
final readonly class SmsMessage
{
    /** Pattern of an SMS Bridge device id (also the `sms.bridge_device_id` setting). */
    public const DEVICE_ID_PATTERN = '/^[A-Za-z0-9-]{1,64}$/D';

    /** Pattern of a client message (idempotency) id. */
    public const CLIENT_MESSAGE_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/D';

    /**
     * @param string $to destination in E.164 (`+` followed by 7 to 15 digits; spaces are removed)
     * @param string $body message text (trimmed; must not be empty)
     * @param string|null $from sender override (E.164 number or alphanumeric sender id); null = the configured sender
     * @param string|null $deviceId SMS Bridge device id override ({@see self::DEVICE_ID_PATTERN}); null = the configured device
     * @param string|null $clientMessageId idempotency id forwarded to providers that support it ({@see self::CLIENT_MESSAGE_ID_PATTERN})
     * @param string|null $statusCallbackUrl delivery status callback URL (public https, checked by the outbound URL guard); never take it from end-user input
     */
    public function __construct(
        public string $to,
        public string $body,
        public ?string $from = null,
        public ?string $deviceId = null,
        public ?string $clientMessageId = null,
        public ?string $statusCallbackUrl = null,
    ) {
    }

    /**
     * Copy with the normalized destination, body and sender (used by the sender before calling a provider).
     *
     * @param string $to normalized E.164 destination
     * @param string $body trimmed body
     * @param string|null $from normalized sender, or null
     */
    public function withNormalized(string $to, string $body, ?string $from): self
    {
        return new self($to, $body, $from, $this->deviceId, $this->clientMessageId, $this->statusCallbackUrl);
    }
}

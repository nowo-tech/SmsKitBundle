<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Phone;

use Nowo\SmsKitBundle\Exception\InvalidPhoneNumberException;

use function preg_match;
use function str_starts_with;
use function trim;

/**
 * Sender of a message: an E.164 number, or an alphanumeric sender id (1 to 11 ASCII letters, digits or spaces with at
 * least one letter, as accepted by most carriers and by Twilio).
 */
final class SenderId
{
    /** Pattern of an alphanumeric sender id. */
    public const ALPHANUMERIC_PATTERN = '/^(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11}$/';

    /** Pattern accepted by the `sms.sender` setting (E.164 or alphanumeric). */
    public const PATTERN = '/^(\+[1-9]\d{6,14}|(?=.*[A-Za-z])[A-Za-z0-9 ]{1,11})$/';

    /**
     * Normalizes a sender: E.164 numbers lose their whitespace, alphanumeric ids are trimmed.
     *
     * @param string $sender candidate sender
     *
     * @throws InvalidPhoneNumberException when it is neither
     */
    public static function normalize(string $sender): string
    {
        $sender = trim($sender);
        if (str_starts_with($sender, '+')) {
            return E164::normalize($sender);
        }
        if (preg_match(self::ALPHANUMERIC_PATTERN, $sender) !== 1) {
            throw new InvalidPhoneNumberException('Sender must be an E.164 number or an alphanumeric sender id (1-11 letters, digits or spaces).');
        }

        return $sender;
    }

    /**
     * Whether the sender is an E.164 number (and not an alphanumeric id).
     *
     * @param string $sender normalized sender
     */
    public static function isNumber(string $sender): bool
    {
        return str_starts_with($sender, '+');
    }
}

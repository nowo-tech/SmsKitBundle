<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Phone;

use Nowo\SmsKitBundle\Exception\InvalidPhoneNumberException;

use function mb_strlen;
use function preg_match;
use function preg_replace;
use function str_repeat;
use function strlen;
use function substr;
use function trim;

/**
 * E.164 phone number helpers (ITU-T E.164: `+`, a country code that does not start with 0, at most 15 digits).
 *
 * A strict syntax check is enough here: the provider is the authority on reachability, and a full numbering-plan
 * check (libphonenumber) would add a large runtime dependency for no delivery guarantee. Whitespace is the only
 * separator removed; `(`, `-` and `.` are refused so a typo is never "fixed" into another number.
 */
final class E164
{
    /** Pattern of a normalized number: `+` and 7 to 15 digits, first digit 1-9. */
    public const PATTERN = '/^\+[1-9]\d{6,14}$/D';

    /**
     * Removes whitespace and validates the result.
     *
     * @param string $phone candidate number
     *
     * @throws InvalidPhoneNumberException when the result is not E.164
     */
    public static function normalize(string $phone): string
    {
        $normalized = self::strip($phone);
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidPhoneNumberException('Phone number must be in E.164 format (for example +15550100123).');
        }

        return $normalized;
    }

    /**
     * Whether the number is E.164 once whitespace is removed.
     *
     * @param string|null $phone candidate number
     */
    public static function isValid(?string $phone): bool
    {
        return $phone !== null && preg_match(self::PATTERN, self::strip($phone)) === 1;
    }

    /**
     * Masked form for logs and messages: the `+`, the first two digits and the last two digits stay, the rest becomes
     * `*` (`+15550100123` → `+15*******23`). Anything that is not E.164 is fully masked.
     *
     * @param string|null $phone number to mask
     */
    public static function mask(?string $phone): string
    {
        $normalized = self::strip((string) $phone);
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            return $normalized === '' ? '' : str_repeat('*', mb_strlen($normalized));
        }

        return substr($normalized, 0, 3) . str_repeat('*', strlen($normalized) - 5) . substr($normalized, -2);
    }

    private static function strip(string $phone): string
    {
        return (string) preg_replace('/\s+/u', '', trim($phone));
    }
}

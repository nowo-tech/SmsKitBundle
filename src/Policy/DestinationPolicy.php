<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Policy;

use Nowo\SmsKitBundle\Exception\DestinationNotAllowedException;
use Nowo\SmsKitBundle\Phone\E164;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

use function hash;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Destination and cost policy applied to every send (`nowo_sms_kit.destinations`), against SMS pumping / toll fraud and
 * SMS bombing:
 *
 * - `allowed_country_codes`: calling codes a destination must start with (empty = every country);
 * - `denied_prefixes`: prefixes always refused (default: premium / satellite / international-network ranges such as
 *   +870, +881, +882, +883, +979);
 * - optional send budgets: per destination number and global (Symfony RateLimiter), consumed only for a destination
 *   that passed the prefix checks.
 */
final readonly class DestinationPolicy
{
    /** Default denied prefixes (calling-code digits without "+"): Inmarsat, UPT, GMSS satellite, international networks, IPRS, OCHA, ITPCS, shared cost. */
    public const DEFAULT_DENIED_PREFIXES = ['870', '878', '881', '882', '883', '888', '979', '991', '808'];

    /**
     * @param list<string> $allowedCountryCodes calling codes (digits) a destination must start with; empty = all
     * @param list<string> $deniedPrefixes prefixes (digits) always refused
     * @param RateLimiterFactoryInterface|null $perDestination budget per destination number (null = none)
     * @param RateLimiterFactoryInterface|null $global budget for every send (null = none)
     */
    public function __construct(
        private array $allowedCountryCodes = [],
        private array $deniedPrefixes = self::DEFAULT_DENIED_PREFIXES,
        private ?RateLimiterFactoryInterface $perDestination = null,
        private ?RateLimiterFactoryInterface $global = null,
    ) {
    }

    /**
     * Refuses a destination outside the policy, then consumes the send budgets.
     *
     * @param string $to normalized E.164 destination
     *
     * @throws DestinationNotAllowedException when the destination or a budget refuses the send
     */
    public function assertAllowed(string $to): void
    {
        $digits = substr($to, 1);
        foreach ($this->deniedPrefixes as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                throw new DestinationNotAllowedException(sprintf('Destination %s is in a denied range (nowo_sms_kit.destinations.denied_prefixes).', E164::mask($to)));
            }
        }

        if ($this->allowedCountryCodes !== []) {
            $allowed = false;
            foreach ($this->allowedCountryCodes as $code) {
                $allowed = $allowed || str_starts_with($digits, $code);
            }
            if (!$allowed) {
                throw new DestinationNotAllowedException(sprintf('Destination %s is outside nowo_sms_kit.destinations.allowed_country_codes.', E164::mask($to)));
            }
        }

        if ($this->perDestination instanceof RateLimiterFactoryInterface && !$this->perDestination->create('to:' . hash('sha256', $to))->consume()->isAccepted()) {
            throw new DestinationNotAllowedException(sprintf('Send budget for %s exhausted (nowo_sms_kit.destinations.rate_limit.per_destination).', E164::mask($to)));
        }
        if ($this->global instanceof RateLimiterFactoryInterface && !$this->global->create('global')->consume()->isAccepted()) {
            throw new DestinationNotAllowedException('Global SMS send budget exhausted (nowo_sms_kit.destinations.rate_limit.global).');
        }
    }

    /**
     * Whether a calling code / prefix list entry is digits only (configuration validation).
     *
     * @param string $code candidate
     */
    public static function isDigits(string $code): bool
    {
        return preg_match('/^[0-9]{1,6}$/D', $code) === 1;
    }
}

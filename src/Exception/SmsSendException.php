<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * A message could not be sent: provider not configured, invalid number or body, destination refused, endpoint
 * refused by the outbound URL guard, transport error or provider rejection.
 *
 * The message is written by the bundle (safe to show to an operator: no credentials, no provider response text,
 * numbers masked). Anything the provider or the transport said is kept apart in {@see self::getDetail()} (masked,
 * truncated) for server-side logs only.
 */
class SmsSendException extends RuntimeException
{
    /**
     * @param string $message bundle-written reason
     * @param string|null $detail masked, truncated provider / transport detail (logs only)
     * @param Throwable|null $previous previous exception
     */
    public function __construct(string $message = '', private readonly ?string $detail = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Masked, truncated provider / transport detail for server-side logs; never show it to users.
     */
    public function getDetail(): ?string
    {
        return $this->detail;
    }
}

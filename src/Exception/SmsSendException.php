<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Exception;

use RuntimeException;

/**
 * A message could not be sent: provider not configured, invalid number or body, endpoint refused by the outbound URL
 * guard, transport error or provider rejection. Messages never contain credentials; phone numbers are masked.
 */
class SmsSendException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Exception;

/**
 * The destination is refused by the destination policy (`nowo_sms_kit.destinations`: allowed country codes, denied
 * premium / satellite / international-network prefixes) or by a send budget.
 */
final class DestinationNotAllowedException extends SmsSendException
{
}

<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Exception;

/**
 * A destination or sender number is not a valid E.164 number (or the sender is not a valid alphanumeric sender id).
 */
final class InvalidPhoneNumberException extends SmsSendException
{
}

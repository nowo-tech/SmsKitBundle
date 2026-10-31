<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Support;

use function str_repeat;

/**
 * Obvious placeholders built at runtime (no realistic phone numbers or credentials in fixtures): the North American
 * 555-01xx range is reserved for fiction, and SIDs / tokens are repeated characters.
 */
final class Numbers
{
    /** Destination in the reserved fictional range. */
    public const TO = '+15550100123';

    /** Second fictional number. */
    public const OTHER = '+15550100199';

    public static function accountSid(): string
    {
        return 'AC' . str_repeat('0', 32);
    }

    public static function messagingServiceSid(): string
    {
        return 'MG' . str_repeat('0', 32);
    }

    public static function token(): string
    {
        return 'test-token-' . str_repeat('x', 8);
    }

    public static function bridgeKey(): string
    {
        return 'smscli_' . str_repeat('0', 16);
    }
}

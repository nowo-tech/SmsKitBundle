<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Log;

use Nowo\SmsKitBundle\Phone\E164;

use function preg_replace_callback;
use function str_repeat;
use function strlen;
use function substr;

/**
 * Masks personal data and credential-shaped values in free text before it is logged or shown (provider error
 * messages, transport errors that echo the request URL): phone numbers, Twilio account / messaging service SIDs and
 * SMS Bridge client keys.
 */
final class Redactor
{
    /**
     * Returns the text with phone numbers and identifiers masked.
     *
     * @param string $text provider or transport message
     */
    public static function redact(string $text): string
    {
        // Phone numbers: "+" and 7-15 digits, optionally split by single spaces (provider messages echo them).
        $text = (string) preg_replace_callback('/\+[1-9](?: ?\d){6,14}/', static fn (array $m): string => E164::mask($m[0]), $text);
        // Twilio Account / Messaging Service SIDs (ACxxxxxxxx…, MGxxxxxxxx…): keep the prefix and the last 4 characters.
        $text = (string) preg_replace_callback('/\b(AC|MG|SM)[0-9a-fA-F]{32}\b/', static fn (array $m): string => $m[1] . str_repeat('*', 28) . substr($m[0], -4), $text);

        // SMS Bridge client / device keys.
        return (string) preg_replace_callback('/\bsms(cli|dev)_[A-Za-z0-9]+/', static fn (array $m): string => 'sms' . $m[1] . '_' . str_repeat('*', strlen($m[0]) - 7), $text);
    }
}

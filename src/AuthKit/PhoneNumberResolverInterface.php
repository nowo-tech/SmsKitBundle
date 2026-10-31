<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\AuthKit;

/**
 * Reads the phone number of an account for the AuthKit SMS notifier. Implement it (and set
 * `nowo_sms_kit.auth_kit.password_reset.phone_resolver`) when the number does not live in a property of the user.
 */
interface PhoneNumberResolverInterface
{
    /**
     * E.164 number stored on the account, or null when it has none (no SMS is sent).
     *
     * @param object $user resolved AuthKit account
     */
    public function resolve(object $user): ?string;
}

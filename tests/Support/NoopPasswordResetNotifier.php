<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Support;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;

/**
 * Stand-in for AuthKit's own (e-mail) password-reset notifier, decorated by the SmsKit adapter in kernel tests.
 */
final class NoopPasswordResetNotifier implements PasswordResetNotifierInterface
{
    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
    }
}

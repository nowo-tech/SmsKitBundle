<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\AuthKit;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\SmsKitBundle\Delivery\SmsDeliveryInterface;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function strtr;

/**
 * AuthKit password-reset notifier that also sends the reset **code** by SMS (delivery modes `code` and `both`).
 *
 * Decorates AuthKit's `PasswordResetNotifierInterface` alias: the decorated notifier (usually the host e-mail
 * notifier) runs first, then the code is handed to the configured {@see SmsDeliveryInterface} for the number stored on
 * the resolved account — never request input (AuthKit 1.31 security contract). The default delivery sends after the
 * response (`kernel.terminate`), so the provider latency does not show in the reset response time. Every failure of
 * the SMS part (\Throwable) is logged without the code and never changes the reset response.
 */
final readonly class SmsPasswordResetNotifier implements PasswordResetNotifierInterface
{
    /** Translation key of the SMS body (`%code%` placeholder). */
    public const BODY_KEY = 'auth_kit.password_reset.sms_body';

    /**
     * @param PasswordResetNotifierInterface|null $inner decorated notifier (null when used alone)
     * @param SmsDeliveryInterface $delivery deferred, sync or Messenger delivery
     * @param PhoneNumberResolverInterface $phones reads the account phone number
     * @param TranslatorInterface|null $translator translator of the SMS body
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private ?PasswordResetNotifierInterface $inner,
        private SmsDeliveryInterface $delivery,
        private PhoneNumberResolverInterface $phones,
        private ?TranslatorInterface $translator = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Calls the decorated notifier, then hands the code to the SMS delivery when the account has a phone.
     *
     * @param PasswordResetTokenResult $token plain credential (link token and / or code)
     * @param PasswordResetNotificationContext $context stored identifier, reset URL, locale and account
     */
    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        $this->inner?->notify($token, $context);

        try {
            $code = $token->code();
            if ($code === null || $code === '') {
                return;
            }

            $phone = $this->phones->resolve($context->user ?? $token->user);
            if ($phone === null) {
                return;
            }

            $params = ['%code%' => $code];
            $body   = $this->translator?->trans(self::BODY_KEY, $params, NowoSmsKitBundle::TRANSLATION_DOMAIN, $context->recipientLocale)
                ?? strtr('Your password reset code is %code%.', $params);

            $this->delivery->deliver(new SmsMessage($phone, $body));
        } catch (Throwable $e) {
            // Never log the code; keep the reset flow going.
            $this->logger->warning('Password-reset code SMS not scheduled.', ['exception_class' => $e::class]);
        }
    }
}

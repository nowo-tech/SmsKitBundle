<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\AuthKit;

use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

use function strtr;

/**
 * AuthKit password-reset notifier that also sends the reset **code** by SMS (delivery modes `code` and `both`).
 *
 * Decorates AuthKit's `PasswordResetNotifierInterface` alias: the decorated notifier (usually the host e-mail
 * notifier) runs first, then the code goes by SMS to the number stored on the resolved account — never to request
 * input (AuthKit 1.31 security contract). Nothing is sent when the provider is not configured, the account has no
 * valid number or the delivery mode has no code; a failed SMS is logged and does not break the reset flow (the
 * e-mail still went out and the response must not reveal anything).
 */
final readonly class SmsPasswordResetNotifier implements PasswordResetNotifierInterface
{
    /** Translation key of the SMS body (`%code%` placeholder). */
    public const BODY_KEY = 'auth_kit.password_reset.sms_body';

    /**
     * @param PasswordResetNotifierInterface|null $inner decorated notifier (null when used alone)
     * @param SmsSenderInterface $sender SMS sender
     * @param PhoneNumberResolverInterface $phones reads the account phone number
     * @param TranslatorInterface|null $translator translator of the SMS body
     * @param LoggerInterface $logger PSR-3 logger
     */
    public function __construct(
        private ?PasswordResetNotifierInterface $inner,
        private SmsSenderInterface $sender,
        private PhoneNumberResolverInterface $phones,
        private ?TranslatorInterface $translator = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Calls the decorated notifier, then sends the code by SMS when possible.
     *
     * @param PasswordResetTokenResult $token plain credential (link token and / or code)
     * @param PasswordResetNotificationContext $context stored identifier, reset URL, locale and account
     */
    public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
    {
        $this->inner?->notify($token, $context);

        $code = $token->code();
        if ($code === null || $code === '' || !$this->sender->isConfigured()) {
            return;
        }

        $phone = $this->phones->resolve($context->user ?? $token->user);
        if ($phone === null) {
            return;
        }

        $params = ['%code%' => $code];
        $body   = $this->translator?->trans(self::BODY_KEY, $params, NowoSmsKitBundle::TRANSLATION_DOMAIN, $context->recipientLocale)
            ?? strtr('Your password reset code is %code%.', $params);

        try {
            $this->sender->send(new SmsMessage($phone, $body));
        } catch (SmsSendException) {
            // The sender already logged the masked failure; keep the reset flow going.
            $this->logger->warning('Password-reset code SMS not sent.', ['provider' => $this->sender->getProviderId()]);
        }
    }
}

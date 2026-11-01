<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Delivery;

use Nowo\SmsKitBundle\Exception\DestinationNotAllowedException;
use Nowo\SmsKitBundle\Exception\InvalidPhoneNumberException;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Log\Redactor;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;

use function sprintf;

/**
 * Handles {@see SendSmsMessage}: sends through the active provider when configured.
 *
 * By default (`messenger_failures: log`) every failure is logged and swallowed by {@see SafeSender}, so Messenger
 * never retries the message and nothing reaches the failure transport (a one-time code may already be stale). With
 * `messenger_failures: rethrow` the handler receives the sender itself: a provider or transport failure is thrown
 * again (redacted) so the transport's retry strategy and failure transport apply, while a refused destination or an
 * invalid number is thrown as {@see UnrecoverableMessageHandlingException} (a retry cannot succeed).
 */
final readonly class SendSmsMessageHandler
{
    /**
     * @param SafeSender $sender failure-safe sender (default mode)
     * @param SmsSenderInterface|null $rethrowingSender sender used instead when failures must propagate (`messenger_failures: rethrow`)
     */
    public function __construct(
        private SafeSender $sender,
        private ?SmsSenderInterface $rethrowingSender = null,
    ) {
    }

    /**
     * Sends the message.
     *
     * @param SendSmsMessage $message queued message
     *
     * @throws SmsSendException when the send fails and failures propagate (Messenger retries it)
     * @throws UnrecoverableMessageHandlingException when the destination is refused and failures propagate
     */
    public function __invoke(SendSmsMessage $message): void
    {
        $sms = new SmsMessage($message->to, $message->body);
        if (!$this->rethrowingSender instanceof SmsSenderInterface) {
            $this->sender->send($sms);

            return;
        }
        if (!$this->rethrowingSender->isConfigured()) {
            return;
        }

        try {
            $this->rethrowingSender->send($sms);
        } catch (DestinationNotAllowedException|InvalidPhoneNumberException $e) {
            throw new UnrecoverableMessageHandlingException($this->describe($e), $e->getCode(), $e);
        } catch (Throwable $e) {
            // The original exception is not chained: provider texts may echo numbers or keys (only the redacted text is
            // stored with the failed message).
            throw new SmsSendException($this->describe($e));
        }
    }

    /**
     * Exception class and redacted message, safe to store with a failed message.
     *
     * @param Throwable $e failure
     */
    private function describe(Throwable $e): string
    {
        return sprintf('SMS not sent (%s): %s', $e::class, Redactor::redact($e->getMessage()));
    }
}

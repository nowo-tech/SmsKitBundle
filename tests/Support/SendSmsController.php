<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Support;

use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Host controller of the worker-mode test: sends one SMS through the bundle port.
 */
final readonly class SendSmsController
{
    public function __construct(
        private SmsSenderInterface $sender,
    ) {
    }

    public function __invoke(string $tenant): Response
    {
        return new Response($this->sender->send(new SmsMessage(Numbers::TO, 'Hello ' . $tenant))->status);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Support;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Nowo\SmsKitBundle\Provider\SmsProviderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfig;

use function count;

/**
 * Configurable provider recording what it was asked to send.
 */
final class StubProvider implements SmsProviderInterface
{
    /** @var list<SmsMessage> */
    public array $sent = [];

    public function __construct(
        private readonly string $name = 'stub',
        private readonly bool $configured = true,
        private readonly ?string $failWith = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return 'Stub ' . $this->name;
    }

    public function isConfigured(SmsConfig $config): bool
    {
        return $this->configured;
    }

    public function send(SmsMessage $message, SmsConfig $config): SmsSendResult
    {
        if ($this->failWith !== null) {
            throw new SmsSendException($this->failWith);
        }
        $this->sent[] = $message;

        return new SmsSendResult($this->name, 'id-' . count($this->sent), 'queued');
    }
}

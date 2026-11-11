<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Support;

use Nowo\SmsKitBundle\Settings\SmsConfig;
use Nowo\SmsKitBundle\Settings\SmsConfigProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function is_string;
use function ucfirst;

/**
 * Host configuration port of a multi-tenant DDD application: the `stub` provider with the sender id of the tenant of
 * the current request (route attribute `tenant`), `Cli` outside a request. Reads per call, keeps nothing (worker mode).
 */
final readonly class RequestTenantSmsConfigProvider implements SmsConfigProviderInterface
{
    public function __construct(
        private RequestStack $requests,
    ) {
    }

    public function current(): SmsConfig
    {
        $request = $this->requests->getCurrentRequest();
        $tenant  = $request instanceof Request ? $request->attributes->get('tenant') : null;

        return new SmsConfig('stub', ucfirst(is_string($tenant) && $tenant !== '' ? $tenant : 'cli'));
    }
}

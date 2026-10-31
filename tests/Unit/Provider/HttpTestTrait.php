<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Provider;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SmsKitBundle\Http\HttpPolicy;
use Nowo\SmsKitBundle\Http\ProviderHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_shift;
use function json_encode;

/**
 * Builds a {@see ProviderHttpClient} on a {@see MockHttpClient} (no DNS, no network).
 */
trait HttpTestTrait
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function http(array $responses, ?HttpPolicy $policy = null): ProviderHttpClient
    {
        $this->requests = [];
        $client         = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 201]);
        });

        return new ProviderHttpClient($client, new OutboundUrlGuard(resolveDns: false), $policy ?? new HttpPolicy(resolveDns: false));
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(array $body, int $status = 201): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}

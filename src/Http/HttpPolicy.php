<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Http;

/**
 * Limits of every provider HTTP call (`nowo_sms_kit.http`): timeouts (REQ-RUNTIME-001) and the outbound URL policy.
 */
final readonly class HttpPolicy
{
    /**
     * @param float $timeout idle timeout in seconds (no byte received for that long → transport error)
     * @param float $maxDuration total duration cap in seconds of one request, connect included
     * @param bool $allowPrivateEndpoints allow loopback / RFC 1918 endpoints (self-hosted SMS Bridge); cloud metadata stays blocked
     * @param bool $resolveDns resolve the endpoint host and pin the checked address (DNS rebinding protection)
     * @param bool $requireHttps refuse plain `http://` endpoints (credentials travel in every request)
     */
    public function __construct(
        public float $timeout = 5.0,
        public float $maxDuration = 15.0,
        public bool $allowPrivateEndpoints = false,
        public bool $resolveDns = true,
        public bool $requireHttps = true,
    ) {
    }
}

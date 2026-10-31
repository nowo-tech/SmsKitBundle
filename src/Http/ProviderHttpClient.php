<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Http;

use Nowo\OutboundUrlGuardBundle\Exception\UnsafeOutboundUrlException;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Log\Redactor;
use Nowo\SmsKitBundle\Message\SmsSendResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SensitiveParameter;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_array;
use function is_scalar;
use function is_string;
use function json_decode;
use function mb_substr;
use function parse_url;
use function rtrim;
use function sprintf;
use function strlen;
use function strpbrk;
use function strtolower;
use function trim;

/**
 * The only way providers talk HTTP: every endpoint goes through the outbound URL guard (HTTPS required by default,
 * no credentials in the URL, private ranges refused unless allowed, DNS pinned), every request has an idle timeout,
 * a total duration cap, a response size cap and no redirects, and transport / provider errors become
 * {@see SmsSendException} with a bundle-written message (the masked provider / transport detail is kept for logs only).
 */
final readonly class ProviderHttpClient
{
    /** Longest provider / transport detail kept for logs. */
    private const MAX_DETAIL = 120;

    /**
     * @param HttpClientInterface $httpClient Symfony HttpClient
     * @param OutboundUrlGuard $guard outbound URL policy (nowo-tech/outbound-url-guard-bundle)
     * @param HttpPolicy $policy timeouts and endpoint policy
     * @param LoggerInterface $logger PSR-3 logger (configuration warnings)
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private OutboundUrlGuard $guard,
        private HttpPolicy $policy,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * POSTs to a provider endpoint and returns the HTTP status and the decoded JSON body (empty when not JSON).
     *
     * @param string $url absolute endpoint URL
     * @param array<string, mixed> $options HttpClient options (body / json / headers / auth); timeouts and redirects are forced
     * @param string $label provider label for messages (`Twilio`, `SMS Bridge`)
     *
     * @throws SmsSendException when the endpoint is refused or the request fails
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function post(string $url, #[SensitiveParameter] array $options, string $label): array
    {
        $this->assertEndpoint($url, $label);

        if (!$this->policy->resolveDns && $this->policy->allowPrivateEndpoints) {
            // Hostnames are then neither resolved nor checked: a name pointing at cloud metadata is not detected.
            $this->logger->warning('SMS provider endpoint checked without DNS resolution while private endpoints are allowed (nowo_sms_kit.http.resolve_dns: false): hostnames are not checked against cloud metadata.', ['provider' => $label]);
        }

        try {
            $pin = $this->guard->httpClientOptions($url, $this->policy->allowPrivateEndpoints, $this->policy->resolveDns);
        } catch (UnsafeOutboundUrlException) {
            throw new SmsSendException(sprintf('%s endpoint URL is refused by the outbound URL guard (private, metadata or invalid address).', $label));
        }

        $cap     = $this->policy->maxResponseBytes;
        $options = [
            ...$options,
            ...$pin,
            'timeout'       => $this->policy->timeout,
            'max_duration'  => $this->policy->maxDuration,
            'max_redirects' => 0,
            // L3: abort as soon as the announced or received body exceeds the cap (never buffer an unbounded reply).
            'on_progress' => static function (int $downloaded, int $total) use ($cap): void {
                if ($downloaded > $cap || $total > $cap) {
                    throw new ResponseTooLargeException(sprintf('Response body larger than %d bytes.', $cap));
                }
            },
        ];

        try {
            $response = $this->httpClient->request('POST', $url, $options);
            $status   = $response->getStatusCode();
            $content  = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            $tooLarge = $e instanceof ResponseTooLargeException || $e->getPrevious() instanceof ResponseTooLargeException;

            throw new SmsSendException($tooLarge ? sprintf('%s response is larger than nowo_sms_kit.http.max_response_bytes (%d).', $label, $cap) : sprintf('%s could not be reached (timeout, connection or TLS error).', $label), $this->detail($e->getMessage()), $e);
        }
        if (strlen($content) > $cap) {
            throw new SmsSendException(sprintf('%s response is larger than nowo_sms_kit.http.max_response_bytes (%d).', $label, $cap));
        }

        $payload = json_decode($content, true, 64);

        return [$status, is_array($payload) ? $this->stringKeys($payload) : []];
    }

    /**
     * Twilio Programmable Messaging call shared by Twilio and the SMS Bridge Twilio-compatible API:
     * `POST <base>/2010-04-01/Accounts/<sid>/Messages.json`, form body, HTTP Basic (Account SID + Auth Token).
     *
     * @param string $baseUrl API base URL
     * @param string $accountSid Account SID (`AC…`)
     * @param string $authToken Auth Token / client key
     * @param array<string, string> $form Messages API fields (`To`, `Body`, `From`, …)
     * @param string $providerId provider name of the result
     * @param string $label provider label for messages
     * @param string $errorDetailKey response field holding the error text after `message`
     *
     * @throws SmsSendException when the request fails or the provider answers outside 2xx
     */
    public function twilioMessages(
        string $baseUrl,
        string $accountSid,
        #[SensitiveParameter]
        string $authToken,
        array $form,
        string $providerId,
        string $label,
        string $errorDetailKey,
    ): SmsSendResult {
        [$status, $payload] = $this->post(rtrim($baseUrl, '/') . '/2010-04-01/Accounts/' . $accountSid . '/Messages.json', [
            'auth_basic' => [$accountSid, $authToken],
            'headers'    => ['Accept' => 'application/json'],
            'body'       => $form,
        ], $label);

        $this->assertAccepted($status, $payload, $label, ['message', $errorDetailKey]);

        return new SmsSendResult($providerId, self::text($payload['sid'] ?? null), self::text($payload['status'] ?? null, 'queued'), $payload);
    }

    /**
     * Throws when the status is not 2xx. The exception message holds the label and the status only; the provider
     * error text (string value of the first documented key, masked, truncated) goes to {@see SmsSendException::getDetail()}
     * for server-side logs, never into the message shown to users.
     *
     * @param int $status HTTP status
     * @param array<string, mixed> $payload decoded body
     * @param string $label provider label
     * @param list<string> $detailKeys response fields holding the error text, in order
     *
     * @throws SmsSendException when the status is not 2xx
     */
    public function assertAccepted(int $status, array $payload, string $label, array $detailKeys): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $detail = null;
        foreach ($detailKeys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $detail = $this->detail($value);
                break;
            }
        }

        throw new SmsSendException(sprintf('%s rejected the message (HTTP %d).', $label, $status), $detail);
    }

    /**
     * Scalar response value as a string, else the default.
     *
     * @param mixed $value decoded value
     * @param string $default value when not scalar or empty
     */
    public static function text(mixed $value, string $default = ''): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? $default : $text;
    }

    /**
     * Whether a credential can travel in a header (no CR, LF or NUL): a token that cannot is treated as not configured.
     *
     * @param string $value credential
     */
    public static function isHeaderSafe(#[SensitiveParameter] string $value): bool
    {
        return strpbrk($value, "\r\n\0") === false;
    }

    /** Masked, single-line, truncated detail for logs. */
    private function detail(string $text): string
    {
        return mb_substr(Redactor::redact($text), 0, self::MAX_DETAIL);
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function stringKeys(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /** HTTPS (unless allowed otherwise) and no user info, query or fragment in the endpoint. */
    private function assertEndpoint(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new SmsSendException(sprintf('%s endpoint URL is not a valid absolute URL.', $label));
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && ($scheme !== 'http' || $this->policy->requireHttps)) {
            throw new SmsSendException(sprintf('%s endpoint URL must use https (nowo_sms_kit.http.require_https).', $label));
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new SmsSendException(sprintf('%s endpoint URL must not contain credentials, a query string or a fragment.', $label));
        }
    }
}

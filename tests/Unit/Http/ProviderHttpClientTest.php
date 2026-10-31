<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Http;

use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Http\HttpPolicy;
use Nowo\SmsKitBundle\Http\ProviderHttpClient;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\RecordingLogger;
use Nowo\SmsKitBundle\Tests\Unit\Provider\HttpTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

use function str_repeat;

final class ProviderHttpClientTest extends TestCase
{
    use HttpTestTrait;

    public function testForcesTimeoutsAndNoRedirects(): void
    {
        $http = $this->http([self::json(['ok' => true], 200)], new HttpPolicy(2.5, 9.0, resolveDns: false));

        [$status, $payload] = $http->post('https://api.example.test/send', ['timeout' => 999, 'max_redirects' => 5, 'json' => ['a' => 1]], 'Acme');

        self::assertSame(200, $status);
        self::assertSame(['ok' => true], $payload);
        $options = $this->requests[0]['options'];
        self::assertSame(2.5, $options['timeout']);
        self::assertSame(9.0, $options['max_duration']);
        self::assertSame(0, $options['max_redirects']);
    }

    public function testTimeoutBecomesASendException(): void
    {
        $http = $this->http([new MockResponse('', ['error' => 'Idle timeout reached for "https://api.example.test/2010-04-01/Accounts/' . Numbers::accountSid() . '/Messages.json".'])]);

        try {
            $http->post('https://api.example.test/send', [], 'Acme');
            self::fail('Expected a transport error.');
        } catch (SmsSendException $e) {
            self::assertSame('Acme could not be reached (timeout, connection or TLS error).', $e->getMessage());
            self::assertStringStartsWith('Idle timeout reached', (string) $e->getDetail());
            self::assertStringNotContainsString(Numbers::accountSid(), (string) $e->getDetail());
            self::assertNotNull($e->getPrevious());
        }
    }

    public function testNonJsonBodyGivesAnEmptyPayload(): void
    {
        $http = $this->http([new MockResponse('<html>bad gateway</html>', ['http_code' => 502])]);

        self::assertSame([502, []], $http->post('https://api.example.test/send', [], 'Acme'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedEndpoints(): iterable
    {
        yield 'relative' => ['/send', 'not a valid absolute URL'];
        yield 'not a URL' => ['http:///nohost', 'not a valid absolute URL'];
        yield 'plain http' => ['http://api.example.test/send', 'must use https'];
        yield 'ftp' => ['ftp://api.example.test/send', 'must use https'];
        yield 'user info' => ['https://user:pass@api.example.test/send', 'must not contain credentials'];
        yield 'query' => ['https://api.example.test/send?token=1', 'must not contain credentials'];
        yield 'fragment' => ['https://api.example.test/send#x', 'must not contain credentials'];
        yield 'loopback' => ['https://127.0.0.1/send', 'refused by the outbound URL guard'];
        yield 'metadata' => ['https://169.254.169.254/latest', 'refused by the outbound URL guard'];
    }

    #[DataProvider('refusedEndpoints')]
    public function testRefusesUnsafeEndpoints(string $url, string $message): void
    {
        $http = $this->http([]);

        try {
            $http->post($url, [], 'Acme');
            self::fail('Expected a refusal.');
        } catch (SmsSendException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame([], $this->requests, 'no request leaves');
    }

    public function testPrivateAndPlainHttpEndpointsWhenAllowed(): void
    {
        $http = $this->http([self::json([], 200)], new HttpPolicy(allowPrivateEndpoints: true, resolveDns: false, requireHttps: false));

        self::assertSame(200, $http->post('http://127.0.0.1:8080/api/v1/sms', [], 'SMS Bridge')[0]);
    }

    public function testMetadataStaysBlockedWhenPrivateIsAllowed(): void
    {
        $http = $this->http([], new HttpPolicy(allowPrivateEndpoints: true, resolveDns: false, requireHttps: false));

        $this->expectException(SmsSendException::class);
        $http->post('http://169.254.169.254/latest/meta-data', [], 'SMS Bridge');
    }

    public function testAssertAccepted(): void
    {
        $http = $this->http([]);
        $http->assertAccepted(204, [], 'Acme', ['message']);

        $cases = [
            [[], null],
            [['detail' => ['nested' => true]], null],
            [['message' => str_repeat('a', 300)], str_repeat('a', 120)],
            [['message' => ['not text'], 'error' => 'second ' . Numbers::TO], 'second +15*******23'],
        ];
        foreach ($cases as [$payload, $detail]) {
            try {
                $http->assertAccepted(500, $payload, 'Acme', ['message', 'error']);
                self::fail('Expected a rejection.');
            } catch (SmsSendException $e) {
                self::assertSame('Acme rejected the message (HTTP 500).', $e->getMessage(), 'the provider text never reaches the message');
                self::assertSame($detail, $e->getDetail());
            }
        }
    }

    public function testCapsTheResponseSizeFromContentLength(): void
    {
        $http = $this->http([new MockResponse('{"a":1}', ['http_code' => 200, 'response_headers' => ['content-length' => '999999']])], new HttpPolicy(resolveDns: false, maxResponseBytes: 1024));

        $this->expectExceptionMessage('response is larger than nowo_sms_kit.http.max_response_bytes (1024)');
        $http->post('https://api.example.test/send', [], 'Acme');
    }

    public function testCapsTheResponseSizeWhileStreaming(): void
    {
        $chunks = (static function () {
            for ($i = 0; $i < 10; ++$i) {
                yield str_repeat('x', 512);
            }
        })();
        $http = $this->http([new MockResponse($chunks, ['http_code' => 200])], new HttpPolicy(resolveDns: false, maxResponseBytes: 1024));

        $this->expectExceptionMessage('response is larger than nowo_sms_kit.http.max_response_bytes (1024)');
        $http->post('https://api.example.test/send', [], 'Acme');
    }

    public function testRefusesABodyOverTheCapWithoutProgressEvents(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(str_repeat('x', 2048), ['http_code' => 200]));
        $http   = new ProviderHttpClient(new class($client) implements HttpClientInterface {
            public function __construct(private readonly HttpClientInterface $inner)
            {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                unset($options['on_progress']);

                return $this->inner->request($method, $url, $options);
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                return $this->inner->stream($responses, $timeout);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function withOptions(array $options): static
            {
                return $this;
            }
        }, new OutboundUrlGuard(resolveDns: false), new HttpPolicy(resolveDns: false, maxResponseBytes: 1024));

        $this->expectExceptionMessage('response is larger than');
        $http->post('https://api.example.test/send', [], 'Acme');
    }

    public function testWarnsWhenHostnamesAreNotResolvedForPrivateEndpoints(): void
    {
        $logger = new RecordingLogger();
        $http   = new ProviderHttpClient(new MockHttpClient(new MockResponse('{}', ['http_code' => 200])), new OutboundUrlGuard(resolveDns: false), new HttpPolicy(allowPrivateEndpoints: true, resolveDns: false), $logger);
        $http->post('https://sms-bridge.example.test/api', [], 'SMS Bridge');

        self::assertStringContainsString('without DNS resolution', $logger->records[0]['message']);
    }

    public function testHeaderSafe(): void
    {
        self::assertTrue(ProviderHttpClient::isHeaderSafe('abc'));
        self::assertFalse(ProviderHttpClient::isHeaderSafe("abc\r\nX-Injected: 1"));
    }

    public function testText(): void
    {
        $http = $this->http([]);

        self::assertSame('7', $http::text(7));
        self::assertSame('x', $http::text(' x '));
        self::assertSame('fallback', $http::text(null, 'fallback'));
        self::assertSame('fallback', $http::text(['a'], 'fallback'));
        self::assertSame('', $http::text(''));
    }
}

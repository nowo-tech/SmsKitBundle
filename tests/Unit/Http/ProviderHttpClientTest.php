<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Http;

use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Http\HttpPolicy;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Unit\Provider\HttpTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

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
            self::assertStringStartsWith('Acme transport error: Idle timeout reached', $e->getMessage());
            self::assertStringNotContainsString(Numbers::accountSid(), $e->getMessage());
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
            [[], 'no details'],
            [['detail' => ['nested' => true]], '{"detail":{"nested":true}}'],
            [['message' => str_repeat('a', 300)], str_repeat('a', 200)],
            [['message' => ['not text'], 'error' => 'second'], 'second'],
        ];
        foreach ($cases as [$payload, $expected]) {
            try {
                $http->assertAccepted(500, $payload, 'Acme', ['message', 'error']);
                self::fail('Expected a rejection.');
            } catch (SmsSendException $e) {
                self::assertSame('Acme rejected the message (HTTP 500): ' . $expected, $e->getMessage());
            }
        }
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

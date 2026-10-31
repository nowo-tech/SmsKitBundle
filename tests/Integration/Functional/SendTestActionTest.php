<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Functional;

use Nowo\SmsKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SmsKitBundle\Tests\Support\Numbers;

use function array_filter;
use function array_values;
use function json_encode;

/**
 * The `sms` section page and its "Send a test SMS" action, end to end through the instance settings admin UI.
 */
final class SendTestActionTest extends KernelTestCase
{
    public function testTheSectionShowsTheFieldsAndTheTestButton(): void
    {
        $client  = $this->client($this->bootKernel());
        $crawler = $client->request('GET', '/admin/settings/sections/sms');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Twilio Auth Token', $html);
        self::assertStringContainsString('SMS Bridge client key', $html);
        self::assertCount(1, $crawler->filter('form[action="/admin/settings/sms/test"]'));
        self::assertStringContainsString('Send a test SMS', $html);
    }

    public function testSendsATestMessageThroughTheLogProvider(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('sms', ['provider' => 'log', 'test_recipient' => Numbers::TO]);
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/settings/sections/sms');
        $client->submit($crawler->filter('form[action="/admin/settings/sms/test"]')->form());

        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame('/admin/settings/sections/sms', $client->getResponse()->headers->get('Location'));
        $client->followRedirect();
        self::assertStringContainsString('Test SMS sent to +15*******23 via log (status: logged).', (string) $client->getResponse()->getContent());

        $records = $this->logger($kernel)->records;
        $logged  = array_values(array_filter($records, static fn (array $r): bool => $r['message'] === 'SMS written to the log (log provider).'));
        self::assertCount(1, $logged);
        self::assertSame('+15*******23', $logged[0]['context']['to']);
        self::assertStringNotContainsString(Numbers::TO, (string) json_encode($records));
    }

    public function testReportsAnUnconfiguredProvider(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('sms', ['provider' => 'twilio', 'test_recipient' => Numbers::TO]);
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/settings/sections/sms');
        $client->submit($crawler->filter('form[action="/admin/settings/sms/test"]')->form());
        $client->followRedirect();

        self::assertStringContainsString('The test SMS could not be sent: SMS provider &quot;twilio&quot; is selected but not fully configured.', (string) $client->getResponse()->getContent());
    }

    public function testRateLimit(): void
    {
        $kernel = $this->bootKernel(['test_action' => ['rate_limit' => ['limit' => 1, 'interval' => '1 hour']]]);
        $this->settings($kernel)->save('sms', ['provider' => 'log', 'test_recipient' => Numbers::TO]);
        $client = $this->client($kernel);

        for ($i = 0; $i < 2; ++$i) {
            $crawler = $client->request('GET', '/admin/settings/sections/sms');
            $client->submit($crawler->filter('form[action="/admin/settings/sms/test"]')->form());
            $client->followRedirect();
        }

        self::assertStringContainsString('Too many test messages. Try again later.', (string) $client->getResponse()->getContent());
    }

    public function testRefusesAForgedToken(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('POST', '/admin/settings/sms/test', ['csrf_only' => ['_token' => 'forged']]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRefusesGet(): void
    {
        $client = $this->client($this->bootKernel());
        $client->request('GET', '/admin/settings/sms/test');

        self::assertSame(405, $client->getResponse()->getStatusCode());
    }

    public function testDeniesUsersWithoutTheAdminRole(): void
    {
        $client = $this->client($this->bootKernel(), 'viewer');
        $client->request('POST', '/admin/settings/sms/test', ['csrf_only' => ['_token' => 'any']]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testAsksAnonymousUsersToLogIn(): void
    {
        $client = $this->client($this->bootKernel(), null);
        $client->request('POST', '/admin/settings/sms/test', ['csrf_only' => ['_token' => 'any']]);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testActionOutsideTheSettingsPrefixIsStillProtected(): void
    {
        $client = $this->client($this->bootKernel(['test_action' => ['path' => '/ops/sms-test']]), 'viewer');
        $client->request('POST', '/ops/sms-test', ['csrf_only' => ['_token' => 'any']]);

        self::assertSame(403, $client->getResponse()->getStatusCode(), 'the controller checks access itself');
    }
}

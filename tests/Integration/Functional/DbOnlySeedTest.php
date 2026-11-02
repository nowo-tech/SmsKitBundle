<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Integration\Functional;

use Doctrine\DBAL\Connection;
use Nowo\InstanceSettingsBundle\Command\SeedFromFallbacksCommand;
use Nowo\SmsKitBundle\Settings\SmsConfigStatus;
use Nowo\SmsKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function bin2hex;
use function random_bytes;

/**
 * DB-only mode: the `sms` fallbacks are seeded once through InstanceSettings 1.2, secrets encrypted, names only in
 * the output; the section page and {@see SmsConfigStatus} warn when the active provider misses required fields.
 */
final class DbOnlySeedTest extends KernelTestCase
{
    public function testSeedCopiesTheEnvFallbacksOnce(): void
    {
        $token  = 'tok' . bin2hex(random_bytes(16));
        $kernel = $this->bootKernel([
            'default_provider' => 'twilio',
            'sender'           => 'Acme',
            'providers'        => ['twilio' => ['account_sid' => Numbers::accountSid(), 'auth_token' => '%env(default:sms_kit_test_token:SMS_KIT_TEST_UNDEFINED)%']],
        ], parameters: ['sms_kit_test_token' => $token]);

        $tester = new CommandTester((new Application($kernel))->find(SeedFromFallbacksCommand::NAME));
        self::assertSame(0, $tester->execute(['--section' => ['sms']]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('sms.twilio_auth_token', $display);
        self::assertStringContainsString('Seeded 4 setting(s)', $display);
        self::assertStringNotContainsString($token, $display);

        self::assertSame('twilio', $this->settings($kernel)->get('sms.provider'));
        self::assertSame($token, $this->settings($kernel)->getSecret('sms.twilio_auth_token'));
        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertStringNotContainsString($token, (string) $connection->fetchOne("SELECT secret_value FROM instance_setting_value WHERE section = 'sms' AND name = 'twilio_auth_token'"));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Nothing to seed', $tester->getDisplay());

        $status = $this->container($kernel)->get(SmsConfigStatus::class);
        self::assertInstanceOf(SmsConfigStatus::class, $status);
        self::assertSame([], $status->missingFields());
    }

    public function testThePageWarnsAboutMissingCredentials(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/settings/sections/sms');
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-notice"]'), 'null provider: no warning');

        $this->settings($kernel)->save('sms', ['provider' => 'twilio', 'sender' => 'Acme']);
        $crawler = $client->request('GET', '/admin/settings/sections/sms');
        $notice  = $crawler->filter('[data-testid="instance-settings-notice"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('The active provider "twilio" is missing required settings: twilio_account_sid, twilio_auth_token.', $notice->text());
    }
}

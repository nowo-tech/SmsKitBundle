<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\AuthKit;

use DateTimeImmutable;
use Nowo\AuthKitBundle\Enum\PasswordResetDeliveryMode;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotificationContext;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetNotifierInterface;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetTokenResult;
use Nowo\SmsKitBundle\AuthKit\PropertyPhoneNumberResolver;
use Nowo\SmsKitBundle\AuthKit\SmsPasswordResetNotifier;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Sender\ConfiguredSmsSender;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\RecordingLogger;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class SmsPasswordResetNotifierTest extends TestCase
{
    private StubProvider $provider;

    private RecordingLogger $logger;

    private function notifier(?PasswordResetNotifierInterface $inner = null, bool $configured = true, ?string $failWith = null, bool $translator = true): SmsPasswordResetNotifier
    {
        $this->provider = new StubProvider(configured: $configured, failWith: $failWith);
        $this->logger   = new RecordingLogger();
        $sender         = new ConfiguredSmsSender(new SmsProviderRegistry([$this->provider]), new SmsConfigResolver(new FakeInstanceSettings(['sms.provider' => 'stub']), new SmsFallbacks()));

        $trans = null;
        if ($translator) {
            $trans = new Translator('en');
            $trans->addLoader('array', new ArrayLoader());
            $trans->addResource('array', ['auth_kit.password_reset.sms_body' => 'Code %code%'], 'en', 'NowoSmsKitBundle');
            $trans->addResource('array', ['auth_kit.password_reset.sms_body' => 'Código %code%'], 'es', 'NowoSmsKitBundle');
        }

        return new SmsPasswordResetNotifier($inner, $sender, new PropertyPhoneNumberResolver(), $trans, $this->logger);
    }

    private function user(?string $phone = Numbers::TO): stdClass
    {
        $user        = new stdClass();
        $user->phone = $phone;

        return $user;
    }

    private function token(PasswordResetDeliveryMode $mode, string $plain = '123456', ?object $user = null): PasswordResetTokenResult
    {
        return new PasswordResetTokenResult($user ?? $this->user(), $plain, new DateTimeImmutable('+1 hour'), $mode);
    }

    private function context(?object $user = null, ?string $locale = null): PasswordResetNotificationContext
    {
        return new PasswordResetNotificationContext('user@example.test', 'https://app.example.test/reset', PasswordResetDeliveryMode::Code, null, $locale, $user);
    }

    public function testSendsTheCodeAfterTheDecoratedNotifier(): void
    {
        $inner = new class implements PasswordResetNotifierInterface {
            public int $calls = 0;

            public function notify(PasswordResetTokenResult $token, PasswordResetNotificationContext $context): void
            {
                ++$this->calls;
            }
        };

        $this->notifier($inner)->notify($this->token(PasswordResetDeliveryMode::Both, 'link-token:654321'), $this->context($this->user(), 'es'));

        self::assertSame(1, $inner->calls);
        self::assertCount(1, $this->provider->sent);
        self::assertSame(Numbers::TO, $this->provider->sent[0]->to);
        self::assertSame('Código 654321', $this->provider->sent[0]->body);
    }

    public function testUsesTheTokenUserAndTheDefaultBodyWithoutTranslator(): void
    {
        $this->notifier(translator: false)->notify($this->token(PasswordResetDeliveryMode::Code), $this->context());

        self::assertSame('Your password reset code is 123456.', $this->provider->sent[0]->body);
    }

    public function testSkipsWhenThereIsNothingToSend(): void
    {
        $notifier = $this->notifier();
        $notifier->notify($this->token(PasswordResetDeliveryMode::Link), $this->context());
        $notifier->notify($this->token(PasswordResetDeliveryMode::Both, 'link-only'), $this->context());
        $notifier->notify($this->token(PasswordResetDeliveryMode::Code), $this->context($this->user(null)));
        self::assertSame([], $this->provider->sent);

        $unconfigured = $this->notifier(configured: false);
        $unconfigured->notify($this->token(PasswordResetDeliveryMode::Code), $this->context());
        self::assertSame([], $this->provider->sent);
    }

    public function testAFailedSmsDoesNotBreakTheResetFlow(): void
    {
        $this->notifier(failWith: 'Provider down')->notify($this->token(PasswordResetDeliveryMode::Code), $this->context());

        self::assertSame('Password-reset code SMS not sent.', $this->logger->records[0]['message']);
        self::assertSame('stub', $this->logger->records[0]['context']['provider']);
    }
}

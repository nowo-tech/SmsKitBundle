<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Unit\Controller;

use ArrayObject;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\SmsKitBundle\Controller\SendTestSmsController;
use Nowo\SmsKitBundle\Provider\SmsProviderRegistry;
use Nowo\SmsKitBundle\Sender\ConfiguredSmsSender;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsFallbacks;
use Nowo\SmsKitBundle\Tests\Support\FakeInstanceSettings;
use Nowo\SmsKitBundle\Tests\Support\Forms;
use Nowo\SmsKitBundle\Tests\Support\Numbers;
use Nowo\SmsKitBundle\Tests\Support\RecordingLogger;
use Nowo\SmsKitBundle\Tests\Support\StubProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function strlen;

final class SendTestSmsControllerTest extends TestCase
{
    private CsrfTokenManager $tokens;

    private StubProvider $provider;

    /** @var ArrayObject<int, array{object|null, string|null}> */
    private ArrayObject $checks;

    /**
     * @param array<string, string> $settings
     */
    private function controller(array $settings = ['sms.provider' => 'stub', 'sms.test_recipient' => Numbers::TO], bool $allowed = true, ?StubProvider $provider = null, int $limit = 5, bool $user = true, bool $translator = true, bool $sectionRoute = true, ?RecordingLogger $logger = null): SendTestSmsController
    {
        $this->tokens   = Forms::tokens();
        $this->provider = $provider ?? new StubProvider();
        $resolver       = new SmsConfigResolver(new FakeInstanceSettings($settings), new SmsFallbacks());
        $routes         = new RouteCollection();
        $routes->add(SendTestSmsController::ROUTE, new Route('/admin/settings/sms/test'));
        if ($sectionRoute) {
            $routes->add('nowo_instance_settings_section', new Route('/admin/settings/sections/{section}'));
        }

        $tokenStorage = new TokenStorage();
        if ($user) {
            $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        }

        $this->checks = new ArrayObject();
        $access       = new class($allowed, $this->checks) implements InstanceSettingsAccessCheckerInterface {
            /**
             * @param ArrayObject<int, array{object|null, string|null}> $checks
             */
            public function __construct(private readonly bool $allowed, private readonly ArrayObject $checks)
            {
            }

            public function canAccess(?object $user, ?string $area = null): bool
            {
                $this->checks[] = [$user, $area];

                return $this->allowed;
            }
        };

        $trans = null;
        if ($translator) {
            $trans = new Translator('en');
            $trans->addLoader('array', new ArrayLoader());
            $trans->addResource('array', ['flash.test_sent' => 'Sent to %recipient% via %provider% (%status%)', 'test_sms.body' => 'Test body'], 'en', 'NowoSmsKitBundle');
        }

        return new SendTestSmsController(
            $access,
            Forms::csrf($this->tokens),
            new ConfiguredSmsSender(new SmsProviderRegistry([$this->provider]), $resolver),
            $resolver,
            new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'], new InMemoryStorage()),
            new UrlGenerator($routes, new RequestContext()),
            $tokenStorage,
            $trans,
            $logger ?? new RecordingLogger(),
        );
    }

    private function request(bool $validToken = true, bool $session = true): Request
    {
        $request = Request::create('/admin/settings/sms/test', 'POST', ['csrf_only' => ['_token' => $validToken ? $this->tokens->getToken(SendTestSmsController::CSRF_TOKEN_ID)->getValue() : 'forged']], server: ['REMOTE_ADDR' => '203.0.113.7']);
        if ($session) {
            $request->setSession(new Session(new MockArraySessionStorage()));
        }

        return $request;
    }

    /**
     * @return array<string, list<string>>
     */
    private function flashes(Request $request): array
    {
        $session = $request->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session->getFlashBag()->all();
    }

    public function testSendsToTheTestRecipient(): void
    {
        $controller = $this->controller();
        $request    = $this->request();

        $response = $controller($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/settings/sections/sms', $response->getTargetUrl());
        self::assertSame(['success' => ['Sent to +15*******23 via stub (queued)']], $this->flashes($request));
        self::assertSame('Test body', $this->provider->sent[0]->body);
        $check = $this->checks->getArrayCopy()[0];
        self::assertSame('sms', $check[1]);
        self::assertInstanceOf(InMemoryUser::class, $check[0]);
    }

    public function testDeniesUsersWithoutAccess(): void
    {
        $controller = $this->controller(allowed: false);

        $this->expectException(AccessDeniedException::class);
        $controller($this->request());
    }

    public function testRejectsAMissingOrForgedToken(): void
    {
        $controller = $this->controller();

        try {
            $controller($this->request(false));
            self::fail('Expected a CSRF failure.');
        } catch (AccessDeniedHttpException) {
        }
        $this->expectException(AccessDeniedHttpException::class);
        $controller(Request::create('/admin/settings/sms/test', 'POST'));
    }

    public function testRateLimitsPerUser(): void
    {
        $controller = $this->controller(limit: 1);
        $controller($this->request());

        $request = $this->request();
        $controller($request);

        self::assertSame(['error' => ['flash.test_rate_limited']], $this->flashes($request));
        self::assertCount(1, $this->provider->sent);
    }

    public function testRateLimitsAnonymousRequestsPerIp(): void
    {
        $controller = $this->controller(limit: 1, user: false);
        $controller($this->request());
        $request = $this->request();
        $controller($request);

        self::assertSame(['error' => ['flash.test_rate_limited']], $this->flashes($request));
        self::assertNull($this->checks->getArrayCopy()[0][0]);
    }

    public function testNeedsATestRecipient(): void
    {
        $controller = $this->controller(['sms.provider' => 'stub']);
        $request    = $this->request();
        $controller($request);

        self::assertSame(['error' => ['flash.test_no_recipient']], $this->flashes($request));
    }

    public function testReportsFailuresRedacted(): void
    {
        $logger     = new RecordingLogger();
        $controller = $this->controller(provider: new StubProvider(failWith: 'Bad number ' . Numbers::TO), translator: false, logger: $logger);
        $request    = $this->request();
        $controller($request);

        self::assertSame(['error' => ['flash.test_failed']], $this->flashes($request));
        self::assertSame('Test SMS failed.', $logger->records[0]['message']);
        self::assertSame(12, strlen((string) $logger->records[0]['context']['reference']));
        self::assertSame('Bad number +15*******23', $logger->records[0]['context']['error']);
    }

    public function testWorksWithoutSession(): void
    {
        $controller = $this->controller();

        self::assertSame(303, $controller($this->request(session: false))->getStatusCode());
    }

    public function testRedirectsHomeWithoutTheSettingsRoutes(): void
    {
        $controller = $this->controller(sectionRoute: false);

        self::assertSame('/', $controller($this->request())->getTargetUrl());
    }
}

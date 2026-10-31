<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\SmsKitBundle\Exception\SmsSendException;
use Nowo\SmsKitBundle\Log\Redactor;
use Nowo\SmsKitBundle\Message\SmsMessage;
use Nowo\SmsKitBundle\NowoSmsKitBundle;
use Nowo\SmsKitBundle\Phone\E164;
use Nowo\SmsKitBundle\Sender\SmsSenderInterface;
use Nowo\SmsKitBundle\Settings\SmsConfigResolver;
use Nowo\SmsKitBundle\Settings\SmsSettingsSection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function bin2hex;
use function hash;
use function random_bytes;
use function strtr;

/**
 * "Send a test SMS" action of the `sms` settings section: POST only, the instance settings access checker for the
 * `sms` area, a valid CSRF token, a per-user rate limit, then one message to the stored test recipient through the
 * active provider. Always redirects back to the section page with a flash message (the recipient is masked).
 *
 * The route exists only through the `nowo_sms_kit` route loader import (REQ-SF-004 rule 7).
 */
final readonly class SendTestSmsController
{
    /** Route name. */
    public const ROUTE = 'nowo_sms_kit_send_test';

    /** CSRF token id of the action form. */
    public const CSRF_TOKEN_ID = 'nowo_sms_kit_send_test';

    /**
     * @param InstanceSettingsAccessCheckerInterface $access instance settings access checker (area `sms`)
     * @param CsrfOnlyFormFactory $csrfForms FormKit CSRF-only form factory
     * @param SmsSenderInterface $sender SMS sender
     * @param SmsConfigResolver $resolver effective configuration (test recipient)
     * @param RateLimiterFactoryInterface $rateLimiter per-user limiter of the action
     * @param UrlGeneratorInterface $urls router
     * @param TokenStorageInterface|null $tokens security token storage (null without SecurityBundle)
     * @param TranslatorInterface|null $translator translator of the flash messages
     * @param LoggerInterface $logger PSR-3 logger (failure detail with the reference shown to the operator)
     */
    public function __construct(
        private InstanceSettingsAccessCheckerInterface $access,
        private CsrfOnlyFormFactory $csrfForms,
        private SmsSenderInterface $sender,
        private SmsConfigResolver $resolver,
        private RateLimiterFactoryInterface $rateLimiter,
        private UrlGeneratorInterface $urls,
        private ?TokenStorageInterface $tokens = null,
        private ?TranslatorInterface $translator = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Sends the test message and redirects to the `sms` section page.
     *
     * @param Request $request current request
     *
     * @throws AccessDeniedException when the user may not open the `sms` section
     * @throws AccessDeniedHttpException when the CSRF token is missing or invalid
     */
    #[Route(path: '', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$this->access->canAccess($user, SmsSettingsSection::NAME)) {
            throw new AccessDeniedException('Access denied to the SMS settings.');
        }

        $back = $this->sectionUrl();
        $form = $this->csrfForms->createNamed($this->urls->generate(self::ROUTE), self::CSRF_TOKEN_ID);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $key = $user instanceof UserInterface ? 'user:' . hash('sha256', $user->getUserIdentifier()) : 'ip:' . hash('sha256', (string) $request->getClientIp());
        if (!$this->rateLimiter->create($key)->consume()->isAccepted()) {
            return $this->back($request, $back, 'error', 'flash.test_rate_limited');
        }

        $recipient = $this->resolver->current()->testRecipient;
        if ($recipient === null) {
            return $this->back($request, $back, 'error', 'flash.test_no_recipient');
        }

        try {
            $result = $this->sender->send(new SmsMessage($recipient, $this->trans('test_sms.body')));
        } catch (SmsSendException $e) {
            // The flash carries the bundle-written reason and a reference only; the provider / transport detail stays in the log.
            $reference = bin2hex(random_bytes(6));
            $this->logger->warning('Test SMS failed.', ['reference' => $reference, 'error' => Redactor::redact($e->getMessage()), 'detail' => $e->getDetail()]);

            return $this->back($request, $back, 'error', 'flash.test_failed', ['%reason%' => Redactor::redact($e->getMessage()), '%reference%' => $reference]);
        }

        return $this->back($request, $back, 'success', 'flash.test_sent', [
            '%provider%'  => $result->providerId,
            '%recipient%' => E164::mask($recipient),
            '%status%'    => $result->status,
        ]);
    }

    /** The `sms` section page, or `/` when the InstanceSettings admin routes are not imported (web UI disabled). */
    private function sectionUrl(): string
    {
        try {
            return $this->urls->generate(InstanceSettingsRoutes::SECTION, ['section' => SmsSettingsSection::NAME]);
        } catch (RouteNotFoundException) {
            return '/';
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function back(Request $request, string $url, string $type, string $key, array $params = []): RedirectResponse
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $this->trans($key, $params));
        }

        return new RedirectResponse($url, RedirectResponse::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, NowoSmsKitBundle::TRANSLATION_DOMAIN) ?? strtr($key, $params);
    }
}

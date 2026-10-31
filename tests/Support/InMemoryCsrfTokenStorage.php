<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Tests\Support;

use SensitiveParameter;
use Symfony\Component\Security\Csrf\Exception\TokenNotFoundException;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

/**
 * CSRF token storage kept in memory (unit tests).
 */
final class InMemoryCsrfTokenStorage implements TokenStorageInterface
{
    /** @var array<string, string> */
    private array $tokens = [];

    public function getToken(string $tokenId): string
    {
        return $this->tokens[$tokenId] ?? throw new TokenNotFoundException($tokenId);
    }

    public function setToken(string $tokenId, #[SensitiveParameter] string $token): void
    {
        $this->tokens[$tokenId] = $token;
    }

    public function removeToken(string $tokenId): ?string
    {
        $token = $this->tokens[$tokenId] ?? null;
        unset($this->tokens[$tokenId]);

        return $token;
    }

    public function hasToken(string $tokenId): bool
    {
        return isset($this->tokens[$tokenId]);
    }
}

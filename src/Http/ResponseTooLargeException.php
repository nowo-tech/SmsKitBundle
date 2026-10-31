<?php

declare(strict_types=1);

namespace Nowo\SmsKitBundle\Http;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown from the `on_progress` callback when a provider response exceeds `http.max_response_bytes`; HttpClient
 * aborts the transfer.
 */
final class ResponseTooLargeException extends RuntimeException implements TransportExceptionInterface
{
}

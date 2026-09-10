<?php

declare(strict_types=1);

namespace Sie\Tests\Client\Fixtures;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * A no-response transport failure shaped like the ones Guzzle 8 reports as
 * `NetworkException` (send/receive errors, network timeouts) and Saloon's
 * synchronous sender leaves unwrapped.
 *
 * Built here rather than instantiating Guzzle's own class so the test runs
 * unchanged on Guzzle 7, which has no `NetworkException`.
 */
final class FakeNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request)
    {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}

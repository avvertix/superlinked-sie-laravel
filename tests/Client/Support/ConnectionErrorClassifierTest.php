<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Sie\Client\Support\ConnectionErrorClassifier;

$connectException = fn (string $message = 'connect failed'): ConnectException => new ConnectException(
    $message,
    new Psr7Request('GET', 'https://example.test'),
);

it('treats connection-refused/timeout curl errors as transient', function () use ($connectException) {
    expect(ConnectionErrorClassifier::isTransient($connectException('cURL error 7: Failed to connect to example.test port 443')))->toBeTrue();
    expect(ConnectionErrorClassifier::isTransient($connectException('cURL error 28: Operation timed out after 5000 milliseconds')))->toBeTrue();
});

it('never treats SSL errors as transient', function () use ($connectException) {
    expect(ConnectionErrorClassifier::isTransient($connectException('cURL error 35: TCP connection reset by peer')))->toBeFalse();
    expect(ConnectionErrorClassifier::isTransient($connectException('cURL error 60: server certificate verification failed')))->toBeFalse();
    expect(ConnectionErrorClassifier::isTransient($connectException('SSL certificate problem')))->toBeFalse();
});

it('fails open (treats as transient) when unclassifiable', function () use ($connectException) {
    expect(ConnectionErrorClassifier::isTransient($connectException('mystery error')))->toBeTrue();
    expect(ConnectionErrorClassifier::isTransient($connectException('cURL error 47: Number of redirects hit maximum amount')))->toBeTrue();
});

it('reads the curl errno from the Guzzle 7 handler context', function () {
    $exception = new ConnectException(
        'connect failed',
        new Psr7Request('GET', 'https://example.test'),
        null,
        ['errno' => 35],
    );

    expect(ConnectionErrorClassifier::isTransient($exception))->toBeFalse();
})->skip(
    ! method_exists(ConnectException::class, 'getHandlerContext'),
    'Guzzle 8 removed the connect exception handler context.',
);

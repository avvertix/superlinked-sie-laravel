<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Sie\Client\Support\ConnectionErrorClassifier;

$connectException = fn (string $message = 'connect failed', array $handlerContext = []): ConnectException => new ConnectException(
    $message,
    new Psr7Request('GET', 'https://example.test'),
    null,
    $handlerContext,
);

it('treats connection-refused/timeout curl errors as transient', function () use ($connectException) {
    expect(ConnectionErrorClassifier::isTransient($connectException(handlerContext: ['errno' => 7])))->toBeTrue();
    expect(ConnectionErrorClassifier::isTransient($connectException(handlerContext: ['errno' => 28])))->toBeTrue();
});

it('never treats SSL errors as transient', function () use ($connectException) {
    expect(ConnectionErrorClassifier::isTransient($connectException(handlerContext: ['errno' => 35])))->toBeFalse();
    expect(ConnectionErrorClassifier::isTransient($connectException('SSL certificate problem')))->toBeFalse();
});

it('fails open (treats as transient) when unclassifiable', function () use ($connectException) {
    expect(ConnectionErrorClassifier::isTransient($connectException('mystery error')))->toBeTrue();
});

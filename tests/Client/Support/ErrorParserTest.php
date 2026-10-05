<?php

declare(strict_types=1);

use Saloon\Http\Faking\MockResponse;
use Sie\Client\Exceptions\InputTooLongException;
use Sie\Client\Exceptions\ModelLoadFailedException;
use Sie\Client\Exceptions\ProvisioningException;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Support\ErrorParser;

it('reads the error code from the X-SIE-Error-Code header first', function () {
    $response = sendMocked(MockResponse::make(
        ['error' => ['code' => 'IGNORED', 'message' => 'x']],
        503,
        ['X-SIE-Error-Code' => 'PROVISIONING'],
    ));

    expect(ErrorParser::getErrorCode($response))->toBe('PROVISIONING');
});

it('falls back to the error.code body field (OpenAI-style envelope)', function () {
    $response = sendMocked(MockResponse::make(['error' => ['code' => 'MODEL_LOADING', 'message' => 'loading']], 503));

    expect(ErrorParser::getErrorCode($response))->toBe('MODEL_LOADING');
});

it('falls back to the detail.code body field (SIE-native envelope)', function () {
    $response = sendMocked(MockResponse::make(['detail' => ['code' => 'LORA_LOADING', 'message' => 'loading']], 503));

    expect(ErrorParser::getErrorCode($response))->toBe('LORA_LOADING');
});

it('normalizes a lowercase "provisioning" body code', function () {
    $response = sendMocked(MockResponse::make(['detail' => ['code' => 'provisioning']], 503));

    expect(ErrorParser::getErrorCode($response))->toBe('PROVISIONING');
});

it('raises ModelLoadFailedException for a 502 MODEL_LOAD_FAILED envelope', function () {
    $response = sendMocked(MockResponse::make([
        'error' => ['code' => 'MODEL_LOAD_FAILED', 'message' => 'gated repo', 'error_class' => 'GATED', 'permanent' => true, 'attempts' => 3],
    ], 502));

    expect(fn () => ErrorParser::raiseIfModelLoadFailed($response, 'bge-m3'))
        ->toThrow(ModelLoadFailedException::class, 'gated repo');
});

it('does not raise for a 502 without the MODEL_LOAD_FAILED code', function () {
    $response = sendMocked(MockResponse::make(['error' => ['code' => 'SOMETHING_ELSE']], 502));

    ErrorParser::raiseIfModelLoadFailed($response, 'bge-m3');
})->throwsNoExceptions();

it('raises InputTooLongException for a 400 INPUT_TOO_LONG envelope', function () {
    $response = sendMocked(MockResponse::make(['error' => ['code' => 'INPUT_TOO_LONG', 'message' => 'too long']], 400));

    expect(fn () => ErrorParser::raiseIfInputTooLong($response, 'bge-m3'))
        ->toThrow(InputTooLongException::class, 'too long');
});

it('handleError throws ProvisioningException for 503 PROVISIONING', function () {
    $response = sendMocked(MockResponse::make(['error' => ['code' => 'PROVISIONING', 'message' => 'scaling']], 503, ['Retry-After' => '5']));

    expect(fn () => ErrorParser::handleError($response))->toThrow(ProvisioningException::class, 'scaling');
});

it('handleError throws RequestException for other 4xx responses', function () {
    $response = sendMocked(MockResponse::make(['detail' => ['message' => 'not found']], 404));

    try {
        ErrorParser::handleError($response);
        expect(false)->toBeTrue('expected an exception');
    } catch (RequestException $exception) {
        expect($exception->getMessage())->toBe('not found');
        expect($exception->statusCode)->toBe(404);
    }
});

it('handleError throws ServerException for 5xx responses', function () {
    $response = sendMocked(MockResponse::make(['error' => 'boom'], 500));

    expect(fn () => ErrorParser::handleError($response))->toThrow(ServerException::class, 'boom');
});

it('getRetryAfter parses the Retry-After header', function () {
    $response = sendMocked(MockResponse::make([], 503, ['Retry-After' => '12']));

    expect(ErrorParser::getRetryAfter($response))->toBe(12.0);
});

it('handleError includes per-item failures from an all_items_failed envelope', function () {
    $response = sendMocked(MockResponse::make([
        'error' => 'all_items_failed',
        'details' => [
            ['item_index' => 0, 'error' => 'mean must have 1 elements if it is an iterable, got 3', 'code' => 'inference_error'],
            ['item_index' => 2, 'error' => 'bad pixels', 'code' => 'inference_error'],
        ],
    ], 400));

    expect(fn () => ErrorParser::handleError($response))->toThrow(
        RequestException::class,
        'all_items_failed (item 0: mean must have 1 elements if it is an iterable, got 3; item 2: bad pixels)',
    );
});

<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use Saloon\Http\Response;
use Sie\Client\Exceptions\InputTooLongException;
use Sie\Client\Exceptions\ModelLoadFailedException;
use Sie\Client\Exceptions\ProvisioningException;
use Sie\Client\Exceptions\RequestException;
use Sie\Client\Exceptions\ServerException;
use Sie\Client\Http\SieResponse;

/**
 * Parses SIE error envelopes and header hints, and raises the matching typed
 * exception. Direct port of `_shared.py`'s `get_error_code` /
 * `get_error_detail` / `handle_error` / `raise_if_model_load_failed` /
 * `raise_if_input_too_long`, minus the msgpack branch (the PHP client is
 * JSON-only).
 */
final class ErrorParser
{
    public static function getErrorCode(Response $response): ?string
    {
        $headerCode = self::stringHeader($response, ErrorCodes::ERROR_CODE_HEADER);

        if ($headerCode !== null) {
            return $headerCode;
        }

        $detail = self::getErrorDetail($response);

        if ($detail === null || ! is_string($detail['code'] ?? null)) {
            return null;
        }

        return ErrorCodes::normalize($detail['code']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getErrorDetail(Response $response): ?array
    {
        // Decoded rather than json()'d: an error body is JSON on every path,
        // but going through the same decoder keeps one way of reading a body
        // and guarantees an array without a redundant check.
        $data = SieResponse::decode($response);

        if (array_key_exists('error', $data)) {
            return is_array($data['error']) ? $data['error'] : null;
        }

        if (array_key_exists('detail', $data)) {
            return is_array($data['detail']) ? $data['detail'] : null;
        }

        return null;
    }

    public static function getRetryAfter(Response $response): ?float
    {
        return RetryAfter::parse(self::stringHeader($response, 'Retry-After'));
    }

    public static function raiseIfModelLoadFailed(Response $response, ?string $model = null): void
    {
        if ($response->status() !== ErrorCodes::HTTP_BAD_GATEWAY) {
            return;
        }

        $detail = self::getErrorDetail($response);

        if ($detail === null || ($detail['code'] ?? null) !== ErrorCodes::MODEL_LOAD_FAILED) {
            return;
        }

        $errorClass = $detail['error_class'] ?? null;

        throw new ModelLoadFailedException(
            (string) ($detail['message'] ?? "Model '{$model}' failed to load"),
            model: $model,
            errorClass: is_string($errorClass) ? $errorClass : null,
            permanent: (bool) ($detail['permanent'] ?? true),
            attempts: self::coerceAttempts($detail['attempts'] ?? 1),
            request: RequestMetadataParser::parse($response),
        );
    }

    public static function raiseIfInputTooLong(Response $response, ?string $model = null): void
    {
        if ($response->status() !== ErrorCodes::HTTP_CLIENT_ERROR) {
            return;
        }

        $detail = self::getErrorDetail($response);

        if ($detail === null || ($detail['code'] ?? null) !== ErrorCodes::INPUT_TOO_LONG) {
            return;
        }

        throw new InputTooLongException(
            (string) ($detail['message'] ?? "Input exceeds the model's maximum token capacity"),
            model: $model,
            request: RequestMetadataParser::parse($response),
        );
    }

    public static function handleError(Response $response): never
    {
        $status = $response->status();
        $code = null;
        $message = "HTTP {$status}";

        $data = SieResponse::decode($response);

        if (array_key_exists('error', $data)) {
            $error = $data['error'];

            if (is_array($error)) {
                $code = $error['code'] ?? null;
                $message = (string) ($error['message'] ?? $message);
            } else {
                $message = self::withItemDetails((string) $error, $data['details'] ?? null);
            }
        } elseif (array_key_exists('detail', $data)) {
            $detail = $data['detail'];

            if (is_array($detail)) {
                $code = $detail['code'] ?? null;
                $message = (string) ($detail['message'] ?? json_encode($detail));
            } else {
                $message = (string) $detail;
            }
        } elseif ($data === []) {
            // Nothing structured came back — an intermediary's HTML error page,
            // say. Its raw text tells the caller more than "HTTP 502" does.
            $body = $response->body();
            $message = $body !== '' ? $body : $message;
        }

        $headerCode = self::stringHeader($response, ErrorCodes::ERROR_CODE_HEADER);
        $code = $headerCode ?? (is_string($code) ? ErrorCodes::normalize($code) : null);

        if ($status === ErrorCodes::HTTP_SERVICE_UNAVAILABLE && $code === ErrorCodes::PROVISIONING) {
            throw new ProvisioningException($message, retryAfter: self::getRetryAfter($response));
        }

        $request = RequestMetadataParser::parse($response);

        if ($status === ErrorCodes::HTTP_CLIENT_ERROR && $code === ErrorCodes::INPUT_TOO_LONG) {
            throw new InputTooLongException($message, request: $request);
        }

        if ($status >= ErrorCodes::HTTP_SERVER_ERROR) {
            throw new ServerException($message, errorCode: $code, statusCode: $status, request: $request);
        }

        throw new RequestException($message, errorCode: $code, statusCode: $status, request: $request);
    }

    /**
     * Append per-item failures to a bare error string, so an envelope like
     * `{"error": "all_items_failed", "details": [{"item_index": 0, "error": "…"}]}`
     * surfaces the cause rather than only its label.
     */
    private static function withItemDetails(string $message, mixed $details): string
    {
        if (! is_array($details)) {
            return $message;
        }

        $items = [];

        foreach ($details as $item) {
            if (! is_array($item) || ! is_string($item['error'] ?? null)) {
                continue;
            }

            $prefix = is_int($item['item_index'] ?? null) ? "item {$item['item_index']}: " : '';
            $items[] = $prefix.$item['error'];
        }

        return $items === [] ? $message : $message.' ('.implode('; ', $items).')';
    }

    private static function stringHeader(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function coerceAttempts(mixed $raw): int
    {
        if (is_numeric($raw) && is_finite((float) $raw)) {
            return max((int) $raw, 1);
        }

        return 1;
    }
}

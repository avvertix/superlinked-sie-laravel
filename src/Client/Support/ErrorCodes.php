<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/** Wire-level error codes, header names, and retry/backoff defaults shared across requests. */
final class ErrorCodes
{
    public const PROVISIONING = 'PROVISIONING';

    public const MODEL_LOADING = 'MODEL_LOADING';

    public const LORA_LOADING = 'LORA_LOADING';

    public const RESOURCE_EXHAUSTED = 'RESOURCE_EXHAUSTED';

    public const MODEL_LOAD_FAILED = 'MODEL_LOAD_FAILED';

    public const INPUT_TOO_LONG = 'INPUT_TOO_LONG';

    public const ERROR_CODE_HEADER = 'X-SIE-Error-Code';

    public const SDK_VERSION_HEADER = 'X-SIE-SDK-Version';

    public const SERVER_VERSION_HEADER = 'X-SIE-Server-Version';

    public const REQUEST_ID_HEADER = 'X-SIE-Request-ID';

    public const MACHINE_PROFILE_HEADER = 'X-SIE-MACHINE-PROFILE';

    public const POOL_HEADER = 'X-SIE-Pool';

    public const HTTP_CLIENT_ERROR = 400;

    public const HTTP_BAD_GATEWAY = 502;

    public const HTTP_SERVER_ERROR = 500;

    public const HTTP_SERVICE_UNAVAILABLE = 503;

    public const HTTP_GATEWAY_TIMEOUT = 504;

    public const DEFAULT_PROVISION_TIMEOUT_S = 900.0;

    public const DEFAULT_RETRY_DELAY_S = 5.0;

    public const LORA_LOADING_MAX_RETRIES = 10;

    public const LORA_LOADING_DEFAULT_DELAY_S = 1.0;

    public const MODEL_LOADING_DEFAULT_DELAY_S = 5.0;

    public const RESOURCE_EXHAUSTED_MAX_RETRIES = 3;

    public const RESOURCE_EXHAUSTED_DEFAULT_DELAY_S = 5.0;

    public const RESOURCE_EXHAUSTED_MAX_DELAY_S = 30.0;

    public const RETRY_JITTER_FRACTION = 0.25;

    public static function normalize(?string $code): ?string
    {
        return $code === 'provisioning' ? self::PROVISIONING : $code;
    }
}

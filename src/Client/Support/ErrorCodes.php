<?php

declare(strict_types=1);

namespace Sie\Client\Support;

/** Wire-level error codes, header names, and retry/backoff defaults shared across requests. */
final class ErrorCodes
{
    public const string PROVISIONING = 'PROVISIONING';

    public const string MODEL_LOADING = 'MODEL_LOADING';

    public const string LORA_LOADING = 'LORA_LOADING';

    public const string RESOURCE_EXHAUSTED = 'RESOURCE_EXHAUSTED';

    public const string MODEL_LOAD_FAILED = 'MODEL_LOAD_FAILED';

    public const string INPUT_TOO_LONG = 'INPUT_TOO_LONG';

    public const string ERROR_CODE_HEADER = 'X-SIE-Error-Code';

    public const string SDK_VERSION_HEADER = 'X-SIE-SDK-Version';

    public const string SERVER_VERSION_HEADER = 'X-SIE-Server-Version';

    public const string REQUEST_ID_HEADER = 'X-SIE-Request-ID';

    public const string MACHINE_PROFILE_HEADER = 'X-SIE-MACHINE-PROFILE';

    public const string POOL_HEADER = 'X-SIE-Pool';

    public const int HTTP_CLIENT_ERROR = 400;

    public const int HTTP_BAD_GATEWAY = 502;

    public const int HTTP_SERVER_ERROR = 500;

    public const int HTTP_SERVICE_UNAVAILABLE = 503;

    public const int HTTP_GATEWAY_TIMEOUT = 504;

    public const float DEFAULT_PROVISION_TIMEOUT_S = 900.0;

    public const float DEFAULT_RETRY_DELAY_S = 5.0;

    public const int LORA_LOADING_MAX_RETRIES = 10;

    public const float LORA_LOADING_DEFAULT_DELAY_S = 1.0;

    public const float MODEL_LOADING_DEFAULT_DELAY_S = 5.0;

    public const int RESOURCE_EXHAUSTED_MAX_RETRIES = 3;

    public const float RESOURCE_EXHAUSTED_DEFAULT_DELAY_S = 5.0;

    public const float RESOURCE_EXHAUSTED_MAX_DELAY_S = 30.0;

    public const float RETRY_JITTER_FRACTION = 0.25;

    public static function normalize(?string $code): ?string
    {
        return $code === 'provisioning' ? self::PROVISIONING : $code;
    }
}

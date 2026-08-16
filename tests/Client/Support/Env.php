<?php

declare(strict_types=1);

namespace Sie\Tests\Client\Support;

/**
 * Minimal `.env` reader for the integration suite.
 *
 * Deliberately not `vlucas/phpdotenv`: this package stays dependency-light,
 * and the integration suite needs exactly "read KEY=value lines from a file".
 *
 * A value already present in the real environment always wins, so CI can inject
 * `SIE_ENDPOINT` / `SIE_KEY` without a `.env` file existing at all.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $loaded = null;

    public static function get(string $key): ?string
    {
        $fromEnvironment = getenv($key);

        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            return $fromEnvironment;
        }

        return self::fromFile()[$key] ?? null;
    }

    /** Forget the parsed file so a test can point at a different `.env`. */
    public static function flush(): void
    {
        self::$loaded = null;
    }

    /**
     * @return array<string, string>
     */
    private static function fromFile(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        $path = dirname(__DIR__, 2).'/.env';

        if (! is_readable($path)) {
            return self::$loaded = [];
        }

        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip one layer of matching quotes, the only quoting form we emit.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return self::$loaded = $values;
    }
}

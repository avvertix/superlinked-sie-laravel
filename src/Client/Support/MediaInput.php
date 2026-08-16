<?php

declare(strict_types=1);

namespace Sie\Client\Support;

use RuntimeException;
use Sie\File;
use SplFileInfo;

/**
 * Converts image/document item inputs to the wire shape `{"data": string,
 * "format": string|null}`, where `data` is standard base64 — see
 * {@see ByteArray} for why.
 *
 * Per the porting decision, this is bytes/path passthrough only: a plain
 * `string` is treated as already-loaded binary content (no re-encoding,
 * unlike the Python SDK which normalizes every image to JPEG via Pillow);
 * an `SplFileInfo` is treated as a file to read, with the format inferred
 * from its extension when not explicitly supplied. The caller is
 * responsible for sending a format the server accepts.
 */
final class MediaInput
{
    private const IMAGE_EXTENSIONS = [
        'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png',
        'gif' => 'gif', 'webp' => 'webp', 'bmp' => 'bmp', 'tiff' => 'tiff',
    ];

    private const DOCUMENT_EXTENSIONS = [
        'pdf' => 'pdf', 'docx' => 'docx', 'doc' => 'doc',
        'html' => 'html', 'htm' => 'html', 'xhtml' => 'html',
        'md' => 'md', 'markdown' => 'md', 'txt' => 'txt',
        'rtf' => 'rtf', 'odt' => 'odt', 'pptx' => 'pptx',
        'xlsx' => 'xlsx', 'csv' => 'csv',
    ];

    /**
     * @param  string|SplFileInfo|array{data: string|SplFileInfo, format?: string|null}  $image
     * @return array{data: string, format: string|null}
     */
    public static function image(string|SplFileInfo|array $image): array
    {
        return self::convert($image, self::IMAGE_EXTENSIONS);
    }

    /**
     * @param  string|SplFileInfo|array{data: string|SplFileInfo, format?: string|null}  $document
     * @return array{data: string, format: string|null}
     */
    public static function document(string|SplFileInfo|array $document): array
    {
        return self::convert($document, self::DOCUMENT_EXTENSIONS);
    }

    /**
     * Infer the wire format for a path, using the extension table for `$kind`.
     *
     * Exposed so callers holding a path but not an `SplFileInfo` — notably
     * {@see File}, which reads from a Laravel disk — infer formats from
     * the same tables rather than keeping a second copy.
     *
     * @param  'document'|'image'  $kind
     */
    public static function formatFor(string $kind, string $path): ?string
    {
        return self::inferFormat($path, $kind === 'image' ? self::IMAGE_EXTENSIONS : self::DOCUMENT_EXTENSIONS);
    }

    /**
     * @param  string|SplFileInfo|array{data: string|SplFileInfo, format?: string|null}  $input
     * @param  array<string, string>  $extensionMap
     * @return array{data: string, format: string|null}
     */
    private static function convert(string|SplFileInfo|array $input, array $extensionMap): array
    {
        // Presence check, not a null check: a caller-supplied `format => null`
        // says "no hint, don't guess" and must not be replaced by the inferred
        // suffix. Mirrors Python's `document.get("format", inferred)`.
        $hasExplicitFormat = is_array($input) && array_key_exists('format', $input);
        $source = is_array($input) ? $input['data'] : $input;

        [$bytes, $inferredFormat] = self::resolve($source, $extensionMap);

        return [
            'data' => ByteArray::fromBinary($bytes),
            'format' => $hasExplicitFormat ? $input['format'] : $inferredFormat,
        ];
    }

    /**
     * @param  array<string, string>  $extensionMap
     * @return array{0: string, 1: string|null}
     */
    private static function resolve(string|SplFileInfo $source, array $extensionMap): array
    {
        if ($source instanceof SplFileInfo) {
            $path = $source->getPathname();

            if (! is_readable($path)) {
                throw new RuntimeException("Unable to read file: {$path}");
            }

            return [file_get_contents($path), self::inferFormat($path, $extensionMap)];
        }

        return [$source, null];
    }

    /**
     * @param  array<string, string>  $extensionMap
     */
    private static function inferFormat(string $path, array $extensionMap): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extensionMap[$extension] ?? null;
    }
}

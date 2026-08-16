<?php

declare(strict_types=1);

namespace Sie;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Sie\Client\Support\MediaInput;

/**
 * A file on a Laravel filesystem disk, resolved lazily.
 *
 * Nothing is read until the owning {@see Input} is turned into its wire shape,
 * so a half-built request stays serializable and can be handed to a queued job
 * (see ADR 0005). Contents are then buffered in memory rather than streamed —
 * SIE's wire body is JSON with base64 `data`, so there is no streaming win to
 * be had (see ADR 0001).
 */
final class File
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $disk = null,
        public readonly ?string $format = null,
    ) {}

    /**
     * A file on a named filesystem disk, e.g. `File::disk('s3', 'invoices/x.pdf')`.
     */
    public static function disk(string $disk, string $path, ?string $format = null): self
    {
        return new self($path, $disk, $format);
    }

    /**
     * A file on the application's default filesystem disk.
     */
    public static function make(string $path, ?string $format = null): self
    {
        return new self($path, null, $format);
    }

    public function contents(): string
    {
        $contents = Storage::disk($this->disk)->get($this->path);

        if ($contents === null) {
            throw new RuntimeException("Unable to read [{$this->path}] from the [".($this->disk ?? 'default').'] disk.');
        }

        return $contents;
    }

    /**
     * The file's size, asked of the disk rather than derived from its contents.
     *
     * This costs a second round trip on a remote disk (one `size`, one `get`),
     * and that is the point: the `max_request_bytes` guard has to be able to
     * reject an oversized batch *without* first pulling it into memory, which
     * is exactly what the guard exists to prevent.
     */
    public function bytes(): int
    {
        return Storage::disk($this->disk)->size($this->path);
    }

    /**
     * The wire shape for this file.
     *
     * `$kind` selects which extension table infers the format, because the same
     * suffix means different things to the image and document endpoints.
     *
     * @param  'document'|'image'  $kind
     * @return array{data: string, format: string|null}
     */
    public function toArray(string $kind = 'document'): array
    {
        return [
            'data' => $this->contents(),
            'format' => $this->format ?? MediaInput::formatFor($kind, $this->path),
        ];
    }
}

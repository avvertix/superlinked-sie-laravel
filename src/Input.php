<?php

declare(strict_types=1);

namespace Sie;

use InvalidArgumentException;
use Sie\Client\Support\MediaInput;
use SplFileInfo;

/**
 * One thing handed to a model — text, one or more images, or a document.
 *
 * A model declares which input kinds it accepts: `docling` takes image and
 * document but not text, `BAAI/bge-m3` takes text only. We do not enforce that
 * locally (see ADR 0003) — the cluster is authoritative.
 *
 * Instances are immutable; every `with*()` returns a copy. Files stay
 * unresolved until {@see toArray()} so a half-built request survives queue
 * serialization (see ADR 0005).
 */
final class Input
{
    /**
     * @param  list<File|SplFileInfo|string>  $images
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly array $images = [],
        public readonly File|SplFileInfo|string|null $document = null,
        public readonly ?string $id = null,
    ) {}

    public static function text(string $text, ?string $id = null): self
    {
        return new self(text: $text, id: $id);
    }

    public static function image(File|SplFileInfo|string ...$images): self
    {
        return new self(images: array_values($images));
    }

    public static function document(File|SplFileInfo|string $document, ?string $id = null): self
    {
        return new self(document: $document, id: $id);
    }

    /**
     * Normalise whatever a caller passed into an `Input`.
     *
     * A bare string is text. An array is treated as an already-shaped wire
     * item, which is how callers reach fields this class does not model yet.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(self|string|array $input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        if (is_string($input)) {
            return self::text($input);
        }

        $images = $input['images'] ?? [];

        if (! is_array($images)) {
            throw new InvalidArgumentException('The "images" key must be a list of images.');
        }

        return new self(
            text: $input['text'] ?? null,
            images: array_values($images),
            document: $input['document'] ?? null,
            id: $input['id'] ?? null,
        );
    }

    /**
     * Normalise one input or a batch of them into a list of `Input`.
     *
     * @param  self|string|iterable<mixed>  $inputs
     * @return list<self>
     */
    public static function listFrom(self|string|iterable $inputs): array
    {
        if ($inputs instanceof self || is_string($inputs)) {
            return [self::from($inputs)];
        }

        // A single wire-shaped item is an associative array; a batch is a list.
        if (is_array($inputs) && ! array_is_list($inputs)) {
            return [self::from($inputs)];
        }

        $list = [];

        foreach ($inputs as $input) {
            $list[] = self::from($input);
        }

        return $list;
    }

    public function withText(string $text): self
    {
        return new self($text, $this->images, $this->document, $this->id);
    }

    public function withImage(File|SplFileInfo|string ...$images): self
    {
        return new self($this->text, [...$this->images, ...array_values($images)], $this->document, $this->id);
    }

    public function withDocument(File|SplFileInfo|string $document): self
    {
        return new self($this->text, $this->images, $document, $this->id);
    }

    public function withId(string $id): self
    {
        return new self($this->text, $this->images, $this->document, $id);
    }

    /**
     * Resolve to the wire shape, reading any file contents at this point.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $item = [];

        if ($this->id !== null) {
            $item['id'] = $this->id;
        }

        if ($this->text !== null) {
            $item['text'] = $this->text;
        }

        if ($this->images !== []) {
            $item['images'] = array_map(
                static fn (File|SplFileInfo|string $image): array|string|SplFileInfo => self::resolve($image, 'image'),
                $this->images,
            );
        }

        if ($this->document !== null) {
            $item['document'] = self::resolve($this->document, 'document');
        }

        return $item;
    }

    /**
     * Approximate byte size of this input once resolved, used to enforce
     * `max_request_bytes` before a request is built.
     */
    public function bytes(): int
    {
        $bytes = strlen($this->text ?? '');

        foreach ($this->images as $image) {
            $bytes += self::sizeOf($image);
        }

        if ($this->document !== null) {
            $bytes += self::sizeOf($this->document);
        }

        return $bytes;
    }

    /**
     * @param  'document'|'image'  $kind
     * @return array{data: string, format: string|null}|string|SplFileInfo
     */
    private static function resolve(File|SplFileInfo|string $source, string $kind): array|string|SplFileInfo
    {
        if ($source instanceof File) {
            return $source->toArray($kind);
        }

        // An uploaded file's pathname is an extensionless temp path, so the
        // usual suffix inference would silently yield `format: null`. Its
        // original client name is the only place the real extension survives.
        if ($source instanceof SplFileInfo && method_exists($source, 'getClientOriginalName')) {
            /** @var string $originalName */
            $originalName = $source->getClientOriginalName();

            return [
                'data' => (string) file_get_contents($source->getPathname()),
                'format' => MediaInput::formatFor($kind, $originalName),
            ];
        }

        // Plain `SplFileInfo` and raw binary strings are handed on untouched
        // for MediaInput to read and interpret.
        return $source;
    }

    private static function sizeOf(File|SplFileInfo|string $source): int
    {
        return match (true) {
            $source instanceof File => $source->bytes(),
            $source instanceof SplFileInfo => (int) $source->getSize(),
            default => strlen($source),
        };
    }
}

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
     * An input read from a filesystem disk, with its kind taken from the
     * file's extension.
     *
     * Document and image are different wire fields with different format
     * tables, so this has to decide which one a path is. The two extension
     * tables do not overlap, which makes the decision safe for anything they
     * cover — and for anything they do not, this refuses rather than guessing.
     * Reach for {@see documentFromDisk()} or {@see imageFromDisk()} then.
     */
    public static function fromDisk(string $disk, string $path, ?string $id = null): self
    {
        return match (self::kindOf($path)) {
            'image' => self::imageFromDisk($disk, $path, $id),
            'document' => self::documentFromDisk($disk, $path, $id),
            default => throw new InvalidArgumentException(
                "Cannot tell whether [{$path}] is a document or an image. "
                .'Use Input::documentFromDisk() or Input::imageFromDisk() to say which.',
            ),
        };
    }

    public static function documentFromDisk(string $disk, string $path, ?string $id = null): self
    {
        return new self(document: File::disk($disk, $path), id: $id);
    }

    public static function imageFromDisk(string $disk, string $path, ?string $id = null): self
    {
        return new self(images: [File::disk($disk, $path)], id: $id);
    }

    /**
     * An input wrapping a file we already hold, kind taken from its name.
     *
     * An uploaded file's pathname is an extensionless temp path, so its
     * original client name is the only place the real extension survives.
     */
    private static function fromFile(File|SplFileInfo $file): self
    {
        $name = match (true) {
            $file instanceof File => $file->path,
            method_exists($file, 'getClientOriginalName') => (string) $file->getClientOriginalName(),
            default => $file->getPathname(),
        };

        return match (self::kindOf($name)) {
            'image' => new self(images: [$file]),
            'document' => new self(document: $file),
            default => throw new InvalidArgumentException(
                "Cannot tell whether [{$name}] is a document or an image. "
                .'Use Input::document() or Input::image() to say which.',
            ),
        };
    }

    /**
     * Which wire field a path belongs in, or null when the extension does not
     * appear in either table.
     */
    private static function kindOf(string $path): ?string
    {
        return match (true) {
            MediaInput::formatFor('image', $path) !== null => 'image',
            MediaInput::formatFor('document', $path) !== null => 'document',
            default => null,
        };
    }

    /**
     * Normalise whatever a caller passed into an `Input`.
     *
     * A bare string is text. A `File` or `SplFileInfo` is a document or an
     * image, decided the same way {@see fromDisk()} decides. An array is
     * treated as an already-shaped wire item, which is how callers reach
     * fields this class does not model yet.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(self|File|SplFileInfo|string|array $input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        if ($input instanceof File || $input instanceof SplFileInfo) {
            return self::fromFile($input);
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
     * @param  self|File|SplFileInfo|string|iterable<mixed>  $inputs
     * @return list<self>
     */
    public static function listFrom(self|File|SplFileInfo|string|iterable $inputs): array
    {
        if ($inputs instanceof self || $inputs instanceof File || is_string($inputs)) {
            return [self::from($inputs)];
        }

        // SplFileInfo is not iterable, but checking it after the iterable
        // branch would be too late for the ones that are (DirectoryIterator).
        if ($inputs instanceof SplFileInfo) {
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

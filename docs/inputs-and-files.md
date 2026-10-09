# Inputs and files

A plain string is treated as text. For anything else, use `Input`:

```php
use Sie\File;
use Sie\Input;

Input::text('a duck paddles on a pond');
Input::text('…', id: 'doc-1');                       // ids come back on the result
Input::image($uploadedFile);                          // UploadedFile or SplFileInfo
Input::document(File::disk('s3', 'invoices/x.pdf'));  // any filesystem disk
Input::document(File::make('local/path.pdf'));        // the default disk

Input::text('caption')->withImage($a, $b);            // one input, several parts
```

## Files on a disk

`Input::fromDisk()` is a shorthand for files. It looks at the extension to decide whether the file is a document or an image:

```php
Input::fromDisk('s3', 'invoices/march.pdf');   // → document
Input::fromDisk('s3', 'cover.jpg');            // → image
Input::fromDisk('s3', 'march.pdf', 'doc-1');   // …with an id

// A file is an input on its own, so the capability methods take one directly.
SIE::model('docling')->extract(File::disk('s3', 'invoices/march.pdf'));
SIE::model('docling')->extract($request->file('upload'));

$paths = ['a.pdf', 'b.pdf', 'c.pdf'];
SIE::model('docling')->extract(array_map(fn ($p) => Input::fromDisk('s3', $p), $paths));
```

If the extension doesn't give it away, `fromDisk()` throws. Use `documentFromDisk()` or `imageFromDisk()` to say which one you mean:

```php
Input::fromDisk('s3', 'archive.bin');
// InvalidArgumentException: Cannot tell whether [archive.bin] is a document or
// an image. Use Input::documentFromDisk() or Input::imageFromDisk() to say which.

Input::documentFromDisk('s3', 'archive.bin');
Input::imageFromDisk('scans', 'page-1');
```

## Queued jobs

Files are read lazily: nothing touches the disk until the request is sent. That means you can build part of a chain and pass it to a queued job:

```php
class EmbedDocument implements ShouldQueue
{
    public function __construct(private Sie\PendingRequest $request, private string $path) {}

    public function handle(): void
    {
        $this->request->encode(Input::document(File::disk('s3', $this->path)));
    }
}

dispatch(new EmbedDocument(SIE::model('BAAI/bge-m3')->pool('eval-bench'), 'document.pdf'));
```

## Memory

The whole batch is serialized before it is sent, so all of it sits in memory at once. Over msgpack that costs the raw size of your files. Over JSON, add about 37% for base64.

A request over `max_request_bytes` (32 MB by default, measured on raw bytes) throws `RequestTooLargeException` before it gets near your memory limit. The package won't split a large batch for you, so split it yourself.

[ADR 0001](adr/0001-documents-are-buffered-in-memory.md) explains why documents are buffered.

# Extract

Extraction pulls structured data out of inputs: entities, relations, classifications, or a schema of your own.

```php
use Sie\Facades\SIE;

$results = SIE::model('urchade/gliner_multi-v2.1')
    ->labels(['person', 'location'])
    ->extract('Ada Lovelace was born in London.');

$results->sole()->entities; // [Entity{text: 'Ada Lovelace', label: 'person', …}, …]
```

## Parsing documents

Document parsing is an extraction too:

```php
use Sie\File;
use Sie\Input;

$parsed = SIE::model('docling')
    ->extract(Input::document(File::disk('documents', 'invoices/march.pdf')));

$parsed->sole()->data; // the parsed document structure
```

## When some inputs fail

A batch does not abort when one input fails. That input stays in the collection with its error attached, so an empty result can mean a failure as easily as "nothing found". Check `hasFailures()` first. [Results](results.md) covers the details.

## Next

- [Results](results.md): mixed-success batches
- [Inputs and files](inputs-and-files.md): documents and images from any disk
- [Profiles, pools and GPUs](capacity.md): profiles such as `docling:ocr`
- [Laravel AI](laravel-ai.md): classification through `laravel/ai`

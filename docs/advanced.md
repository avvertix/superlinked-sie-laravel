# Advanced

## Accessing the underlying client

For anything the package doesn't model, such as OpenAI-compatible chat completions, you can drop down to the client:

```php
SIE::raw();                    // Saloon connector for the default connection
SIE::connection('eu')->raw();  // …and for a named one
SIE::connection()->client();   // the lower-level SieClient
```

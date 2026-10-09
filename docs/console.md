# Console

The `sie:models` Artisan command lists the models a cluster serves.

```bash
php artisan sie:models
```

The output looks like this:

```
 INFO  Models served by the [default] connection (msgpack).

+-----------------+-----------------+------------+------------------+--------+
| Model           | Inputs          | Outputs    | Dimensions       | Loaded |
+-----------------+-----------------+------------+------------------+--------+
| BAAI/bge-m3     | text            | dense, ... | dense: 1024, ... | yes    |
| docling         | image, document | json       | -                | yes    |
+-----------------+-----------------+------------+------------------+--------+
```

A cluster can serve more than a hundred models, so you will usually want a filter:

```bash
php artisan sie:models bge                 # only names containing "bge"
php artisan sie:models --loaded            # only what is loaded on a worker right now
php artisan sie:models bge --loaded        # both
php artisan sie:models --connection=eu     # read a named connection
```

In code, `SIE::models()` returns the same catalog.

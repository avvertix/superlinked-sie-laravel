# Concepts

The package uses SIE's own vocabulary. These are the terms you will meet first:

| Term | Meaning |
| --- | --- |
| **Capability** | Something a model can do: encode, score, extract or generate. The cluster declares which ones each model supports, and the mapping is many-to-many: `BAAI/bge-m3` both encodes and scores, while `docling` does neither. |
| **Profile** | A named variant of a model, written `model:profile`. For example `docling:ocr` or `BAAI/bge-m3:multivector`. |
| **Output** | A representation a model produces: `dense`, `sparse`, `multivector`, `score` or `json`. |
| **Input** | One thing you hand to a model: text, images or a document. |
| **Pool** / **GPU type** | Where the work runs. A pool is a slice of capacity you hold on a lease. A GPU type is a hardware profile. You can set either one without the other. |

To see what your cluster actually serves, run `SIE::models()` or `php artisan sie:models`. Both list each model's inputs, outputs and dimensions.

The full glossary lives in [`CONTEXT.md`](../CONTEXT.md).

## Next

- [Encode](encode.md), [Score](score.md), [Extract](extract.md), [Generate](generate.md)
- [Inputs and files](inputs-and-files.md)
- [Profiles, pools and GPUs](capacity.md)

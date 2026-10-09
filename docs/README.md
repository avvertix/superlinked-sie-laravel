# SIE for Laravel documentation

If you're new to the package, read the [README](../README.md) first for requirements, installation and a first request.

## Getting started

- [Concepts](concepts.md): capabilities, profiles, outputs, inputs, pools

## Capabilities

- [Encode](encode.md): turn inputs into vectors
- [Score](score.md): rank inputs against a query
- [Extract](extract.md): entities, classifications, parsed documents
- [Generate](generate.md): text from a prompt, whole or streamed

## Guides

- [Inputs and files](inputs-and-files.md): text, images, documents, disks, queued jobs, memory
- [Results](results.md): collections, partial failures, response details
- [Profiles, pools and GPUs](capacity.md): choosing where and how the work runs
- [Configuration](configuration.md): connections and wire format
- [Console](console.md): the `sie:models` command
- [Testing](testing.md): `SIE::fake()`, fake models, failures, recordings
- [Laravel AI](laravel-ai.md): embeddings, reranking and classification through `laravel/ai`
- [Advanced](advanced.md): the underlying Saloon connector and client

## Design decisions

The [architecture decision records](adr/) explain why the package behaves the way it does.

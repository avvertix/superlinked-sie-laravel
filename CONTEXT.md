# SIE for Laravel

A Laravel package that fronts the Superlinked Inference Engine (SIE) — a single multi-model
inference cluster serving encoding, scoring, extraction, and generation from one endpoint. This
context covers the vocabulary an application developer meets when reaching for the package.

## Language

### The engine

**SIE**:
The Superlinked Inference Engine — the cluster that serves every model behind one endpoint.
Written uppercase when it names the facade, lowercase-cased as `Sie` when it names the PHP
namespace.
_Avoid_: server, engine, API, gateway (see **Gateway**)

**Gateway**:
The SIE process that accepts requests and routes them to workers. Distinguished from a bare
worker because capacity, pools, and health only exist at the gateway.
_Avoid_: proxy, load balancer

**Connection**:
A named, configured SIE endpoint — one URL plus its credentials. An application may define
several; one is the default.
_Avoid_: instance, cluster, server, driver

### Models

**Model**:
A named inference artefact the cluster can serve, e.g. `BAAI/bge-m3` or `docling`. A model
declares which **Inputs** it accepts and which **Outputs** it produces, and those declarations —
not the caller's intent — decide which **Capabilities** it can serve.
_Avoid_: engine, backend, algorithm

**Profile**:
A named variant of a **Model**, addressed with colon syntax as `model:profile` — `docling:ocr`,
`BAAI/bge-m3:multivector`. Every model has a `default` profile; profiles change behaviour, not
identity.
_Avoid_: variant, preset, flavour, mode, version, revision (see **Revision**)

**Revision**:
The upstream weights commit a model is pinned to. Reported by the cluster, never chosen by the
caller.
_Avoid_: version

**Capability**:
One of the four things a model can be asked to do: **Encode**, **Score**, **Extract**,
**Generate**. Membership is derived from a model's declared **Outputs**, and is many-to-many —
`BAAI/bge-m3` both encodes and scores.
_Avoid_: task, operation, action, endpoint

**Input**:
One thing handed to a model — text, one or more images, or a document. A model declares which
input kinds it accepts; `docling` accepts image and document but not text.
_Avoid_: item, payload, content, record

**Output**:
A representation a model produces: `dense`, `sparse`, `multivector`, `score`, or `json`. A
model may declare several, and each carries its own dimensionality.
_Avoid_: result (see **Result**), vector, format, embedding

**Result**:
What one **Input** produced for one request. Always returned as a collection, one **Result** per
**Input**, even when a single input was sent.
_Avoid_: response, output (see **Output**), row

### Capabilities

**Encode**:
Turning **Inputs** into vector **Outputs** — `dense`, `sparse`, and/or `multivector`. Aliased as
`embeddings` for developers arriving from the wider Laravel ecosystem.
_Avoid_: embed as the canonical term, vectorise, index

**Score**:
Ranking **Inputs** by relevance to a query. Aliased as `rerank`.
_Avoid_: rerank as the canonical term, rank, sort, relevance

**Extract**:
Pulling structured `json` out of an **Input** — entities, relations, classifications, detected
objects, or a caller-supplied schema. Document parsing (`docling`) is an **Extract**, not an
**Encode**.
_Avoid_: parse, classify, NER, OCR, understand

**Generate**:
Producing text from a prompt, optionally streamed.
_Avoid_: complete, chat, prompt

**Instruction**:
A per-request hint that steers **Encode** and **Score** for models that were trained to accept
one. Distinct from a prompt, which is the whole input to **Generate**.
_Avoid_: prompt, system message, hint

**Partial failure**:
One **Input** in a batch failing while its siblings succeed. **Extract** batches are
mixed-success by design, so an empty **Result** means "nothing found" only once partial failure
has been ruled out.
_Avoid_: error, partial success, skipped

### Capacity

**Pool**:
A named, lease-held slice of cluster capacity that a caller can route work to. Created,
renewed, and deleted explicitly; garbage-collected after inactivity.
_Avoid_: queue, group, tenant, namespace

**GPU type**:
A hardware profile a worker runs on, e.g. `l4`. Independent of **Pool** — a request may name
either, both, or neither.
_Avoid_: device, machine, node, gpu as a single combined string

**Provisioning**:
The cluster bringing capacity online for a request that arrived before a worker was ready. A
request may wait for it rather than fail.
_Avoid_: scaling, cold start, warmup (see **Warmup**)

**Warmup**:
Deliberately loading a model onto a worker ahead of real traffic, so the first real request
does not pay **Provisioning** cost.
_Avoid_: preload, prime, cache

# A Fake holds a model's Answers, and refuses what it has no Answer for

`SIE::fake()` is configured as a map of **Model** name to a `FakeModel` carrying one **Answer**
per **Capability**, built by chaining immutably (`FakeModel::dense(1024)->scoring([...])`). A
`FakeModel` therefore describes what a model answers, not what it is: it holds no capability
classification of its own, and the faked catalog reports the union of the outputs its answers
imply. Anything the **Fake** has no answer for — an unknown route, a capability the model was
not given — raises rather than returning a plausible-looking empty response.

An imperative responder (`SIE::fake()->whenEncoding('bge-m3')->respondWith(…)`) was rejected: it
reads better for elaborate cases but doubles the vocabulary and breaks every existing call site.
Keeping a single capability per `FakeModel` was rejected because `BAAI/bge-m3` genuinely serves
both **Encode** and **Score**, and a double that cannot express one model doing two things
cannot express the cluster's own flagship model.

Failures are answered at the wire — a status and an error envelope — not by throwing a typed
exception directly, so that `ErrorParser`, the retry ladder in `RetryingRequestSender` and the
capability mapping in `PendingRequest` all run. A test that fakes a `503 PROVISIONING` is then
testing the package's own error path rather than asserting that the double was configured.

## Consequences

The **Fake** owns the response envelope and the caller owns their model's payload, because
`ExtractResult::$data` is opaque to this package — a package that never interprets a payload has
no basis for generating one. Where writing that payload by hand is unreasonable, a **Recording**
captures it from the cluster instead. Neither the package nor its documentation ever describes a
specific model's payload shape.

Because wire-level failures run the real retry ladder, `SIE::fake()` has to make retries instant.
The clock and the sleeper are resolved from the container — interfaces this package owns, which an
application may override — and the **Fake** binds one object that serves as both, so a wait costs
nothing and still spends the budget. The production sleeper delegates to Laravel's `Sleep`, but the
**Fake** never calls `Sleep::fake()`: that resets a sequence and callbacks the application may have
registered for its own code, and a test double has no business writing framework-global state.
Waits are asserted with `SIE::assertSlept()`. The delegation lives in the framework layer, never in
`src/Client`, which imports no Illuminate code and is pinned that way by an arch test.

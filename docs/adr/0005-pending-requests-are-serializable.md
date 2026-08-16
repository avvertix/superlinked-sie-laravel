# Pending requests hold a connection name, not a resolved client

`PendingRequest` stores the **Connection** name and resolves the underlying client only when a
terminal verb executes; `Sie\File` likewise resolves its disk contents at send time rather than
at construction. This makes a half-built chain serializable, so an application can put one
straight into its own queued job — the canonical thing a Laravel developer does with a batch of
documents to embed.

The package deliberately ships **no** queued jobs and **no** events in v1. Shipping jobs would
mean inventing a result-delivery contract we cannot yet guess correctly, and undefined events are
far easier to add later than to remove.

## Consequences

Anyone "simplifying" `PendingRequest` by injecting a resolved `SieClient` or Saloon `Connector`
will break queue serialization, with no failing type check to warn them — hence this record.

# Upgrade guide

## 1.x → 2.0

2.0 tracks the SDK 2.0 `WebhookEvent` contract and makes `EventType`
resolution total (it always returns a case, never `null`).

Requires `laulamanapps/document-signer-sdk:^2.0` — `composer update` pulls it
in automatically.

### `EventType::tryFromPayload()` no longer returns `null`

```php
// before
public static function tryFromPayload(array $payload): ?self   // null for unknown

// after
public static function tryFromPayload(array $payload): self     // EventType::Unknown for unknown
```

An unrecognised, empty, or missing token now resolves to the new
`EventType::Unknown` case instead of `null`.

**Migrate:** replace null checks with the `Unknown` case:

```php
// before
$event = EventType::tryFromPayload($payload);
if ($event === null) {
    // unknown / unmapped
}

// after
$event = EventType::tryFromPayload($payload);
if ($event === EventType::Unknown) {
    // unknown / unmapped
}
```

`EventType::Unknown` is semantically inert — all four `is…()` predicates
return `false` — so `match (true)` dispatch chains keyed on the predicates fall
through to `default` without a code change.

### New `EventType::Unknown` case

If you enumerate `EventType::cases()` (e.g. building a settings UI or a
translation table), the synthetic `Unknown` sentinel is now included. Skip it
where you only want real ValidSign tokens:

```php
$real = array_filter(EventType::cases(), fn (EventType $c) => $c !== EventType::Unknown);
```

### `EventType::provider()` removed

Following the SDK change, `EventType` no longer implements `provider()`. If you
called `$event->provider()` to learn the emitting provider, read it from your
integration instead — in the Laravel package it's
`DocumentSignerWebhookReceived::$provider`.

### No other public API changed

Case names/values, the `is…()` predicates, and `value()` are unchanged.

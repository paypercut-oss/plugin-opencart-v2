# Debug sessions (client telemetry)

A merchant-started, time-boxed diagnostic feed. Off until someone presses
**Start debug session** in Extensions → Payments → Paypercut → **Diagnostics**;
ends by itself after about an hour.

Nothing is sent when no session is running. `PaypercutEventRecorder::record()`
reads one already-loaded setting and returns, so call sites on the checkout path
report unconditionally.

## Shape

```
Start  →  POST {api}/v1/telemetry/tokens   (the store's API key, once)
          →  short-lived RS256 token
Events →  POST {edge}/v1/telemetry         (the token; never the API key)
```

The edge verifies the token offline against Ory's JWKS and never calls back into
the platform, so telemetry cannot block a payment.

### One environment value, both hosts

`paypercut_environment` (Extensions → Payments → Paypercut → **API
Configuration**) decides **both** hosts. They are resolved in one call sequence
in `mintDebugSession()`, never independently — a token minted for one
environment is rejected by every other environment's edge with a 401 that looks
exactly like a forged token.

| `paypercut_environment` | Payment API | Telemetry edge |
|---|---|---|
| `production` (default) | `https://api.paypercut.io/` | `https://telemetry.paypercut.io/` |
| `stage` | `https://api.stage.paypercut.net/` | `https://telemetry.stage.paypercut.net/` |
| `dev` | `https://api.dev.paypercut.net/` | `https://telemetry.dev.paypercut.net/` |
| unset (never saved) | `https://api.paypercut.io/` | `https://telemetry.paypercut.io/` |
| set but unknown | `https://api.paypercut.io/` (fallback) | **`''` — no session** |

The payment API falls back to production so stores that predate the setting keep
working. The edge deliberately does **not** fall back: an unknown environment
must yield no session rather than a confusing one.

`unset` means a store that predates the setting: `PaypercutEnvironment::stored()`
reads it as `production`, which is where its payments have always gone and what
the settings form shows. A value that **is** set but unrecognised is not guessed
at — it yields no session.

Both bases pass `PaypercutEnvironment::allowedPaypercutBase()`, which accepts
only `https` on a `paypercut.net` / `paypercut.io` host and rebuilds the URL from
its parts, dropping any query, fragment or `user:password@`. The store's API key
travels on the mint request, so the destination is validated rather than trusted.
There is **no host override**: a constant naming the edge directly could point it
at an environment the mint host does not follow, and the two hosts must always be
resolved from the one stored value.

| Piece | Role |
|---|---|
| `PaypercutEvent` | Named constructors, the deny assertion, the wire envelope |
| `PaypercutEventRecorder` | Buffers events; one queue write per request, at shutdown |
| `PaypercutEventQueue` | Capped store; splits batches to the edge's bounds |
| `PaypercutTelemetrySession` | Session record, token custody, locks, teardown |
| `PaypercutFlusher` | Delivers from admin requests only; handles 413 by splitting |
| `PaypercutTokenMinter` | Exchanges the API key for a token, on the mint host |
| `PaypercutEdgeClient` | POSTs a batch; reads `accepted`/`dropped` off a 202 |
| `PaypercutSentLog` | The tail of what this store actually delivered |
| `PaypercutFatalErrorWatch` | Shutdown handler for fatals that reach no catch block |
| `PaypercutTelemetryStore` | The three storage primitives (see below) |

## Storage

| Need | Where |
|---|---|
| Hot durable record | `oc_setting`, key `paypercut_telemetry_session` under setting code **`paypercut_telemetry`** |
| Cold blobs and expiring blobs | `oc_paypercut_telemetry_store` (`store_key`, `store_value`, `expires_at`) |
| Atomic lock | `oc_paypercut_telemetry_lock`, primary key on `lock_name` |
| Audit log | `$this->log->write()`, written whatever the merchant's logging preference |

The record uses its own setting code on purpose. Saving the settings form calls
`editSetting('paypercut', …)`, which **deletes and re-inserts every row with code
`paypercut`** — a live session's record stored there would vanish, stranding its
token. `reap()` still cleans up that case (`token_orphaned`) if it ever happens.

Both tables are created by `install()` and re-checked lazily at runtime, so a
store that upgrades without re-installing still gets them. The lazy check only
fires while a session is live.

Keys: `paypercut_telemetry_token`, `paypercut_telemetry_queue`,
`paypercut_telemetry_inflight`, `paypercut_telemetry_runtime`,
`paypercut_telemetry_sent_log`, plus the `paypercut_telemetry_start_lock` and
`paypercut_telemetry_flush_lock` rows. Uninstall deletes all seven and the
session record.

## Budgets

| Constant | Value |
|---|---|
| `MAX_QUEUE_EVENTS` | 200 |
| `MAX_QUEUE_BYTES` | 65536 |
| `MAX_BATCH_BYTES` | 16384 |
| `MAX_BATCH_EVENTS` | 50 |
| `PaypercutEvent::MAX_ATTRS` | 16 |
| `PaypercutEvent::MAX_TEXT_BYTES` | 256 |
| `PaypercutEvent::MAX_STACK_FRAMES` | 8 |
| `SentLog::MAX_ENTRIES` | 100 |
| `SESSION_MAX_SECONDS` | 3600 |
| `MAX_CONSECUTIVE_SEND_FAILURES` | 4 |

## Events

### Lifecycle

| Event | When |
|---|---|
| `session.started` | A session was published. `session_id`, `environment`, `expires_at` — and nothing else |
| `session.stopped` | The merchant pressed Stop. `session_id`, `reason`, `events_sent`, `events_dropped` |
| `environment.snapshot` | Extension, OpenCart, PHP and theme versions; multi-store and TLS flags |
| `environment.configuration` | How the extension is configured; re-sent when settings are saved mid-session |
| `environment.plugins` | Installed extensions and enabled OCMod modifications, chunked |

### Checkout

| Event | `error.code` | `attrs` |
|---|---|---|
| `checkout.hosted.redirected` | — | `order_status` |
| `checkout.hosted.redirect_missing` | `redirect_absent` | — |
| `checkout.embedded.session_created` | — | — |
| `checkout.embedded.create_failed` | `no_session_id` · `session_create` | — |
| `checkout.embedded.order_created` | — | `order_status`, `session_matched`, `verified_status` |
| `checkout.session_create_failed` | `transport` · `session_create` · `unsupported_currency` | `store_currency` on the currency branch |
| `checkout.return.pending` | — | `payment_status`, `session_status`, `order_status` |
| `checkout.return.unverifiable` | `no_session_meta` · `lookup_failed` | — |

### Payment and order

| Event | `error.code` | `attrs` |
|---|---|---|
| `payment.succeeded` | — | `session_status`, `order_status`, `order_updated` |
| `payment.failed` | `expired` · `<payment_status>` · `unknown` | `payment_status`, `session_status`, `order_status`, `order_updated` |
| `payment.closed_unpaid` | `open` | same |
| `order.marked_paid` | — | `source`, `from_status`, `to_status`, `target_status` |
| `order.marked_failed` | — | `source`, `payment_status`, `from_status`, `to_status` |
| `order.status_unhandled` | `unknown_payment_status` | `source`, `payment_status`, `order_status` |

`payment.failed` vs `payment.closed_unpaid` is deliberate. An expired checkout
session is unambiguously a failure. A session that is still `open` when the
shopper returns is not: the payment may still land, and reporting it as a
decline would put a false failure in front of a merchant whose payment worked.

### Webhook

| Event | `error.code` | `attrs` |
|---|---|---|
| `webhook.received` | — | `type`, `duplicate` |
| `webhook.rejected` | `missing_signature` · `invalid_signature` | `http_status` |
| `webhook.payload_invalid` | `empty_body` · `empty_or_unparsable` | `http_status` |
| `webhook.order_updated` | — | `payment_status`, `order_status` |
| `webhook.unresolved` | `order_not_found` | `http_status: 503`, `has_client_reference_id`, `has_metadata` |
| `webhook.skipped` | — | `webhook`, `reason` (+ `payment_status`, `session_status`) |
| `webhook.registered` / `webhook.deleted` | — | — |
| `webhook.registration_failed` | `http_<status>` | `api_code`, `trace_id`, `http_status` |
| `webhook.delete_failed` | `rejected` | `http_status` |

`webhook.rejected` is the most useful event a session carries: a merchant whose
orders never leave "pending" is almost always looking at a rotated webhook
secret or a signature that never matched, and none of it is visible from our
side.

### Refund

| Event | `error.code` | `attrs` |
|---|---|---|
| `refund.succeeded` | — | `is_partial`, `has_reason`, `has_refund_id` |
| `refund.rejected` | `missing_payment_intent` · `payment_not_succeeded` · `invalid_amount` | — |
| `refund.failed` | `transport` · `http_<status>` | `has_reason` (+ `api_code`, `trace_id`, `http_status`) |

`has_reason` is a **boolean**. The refund reason text a merchant types is theirs
and never leaves the store.

### Connection and settings

| Event | `error.code` | `attrs` |
|---|---|---|
| `connection.validated` | — | `source`, `is_bnpl`, `environment`, `api_key_mode` |
| `connection.tested` | `credentials_rejected` · `http_<status>` | `is_bnpl`, `ok`, `environment`, `api_key_mode` |
| `connection.webhook_registered` | — | `source` |
| `connection.webhook_registration_failed` | `already_exists` · `rejected` | `source` |
| `connection.payment_domain_registered` | — | `source` |
| `connection.payment_domain_registration_failed` | `rejected` | `source` |
| `payment_domain.registered` | — | — |
| `payment_domain.registration_failed` | `http_<status>` | `api_code`, `trace_id`, `http_status` |
| `settings.webhooks_unreadable` | `lookup_failed` | `http_status` |
| `settings.payment_domains_unreadable` | `lookup_failed` | `http_status` |
| `settings.payment_configs_unreadable` | `lookup_failed` | `http_status` |

### API and runtime

| Event | `error.code` | `attrs` |
|---|---|---|
| `api.request_failed` | `connect` · `transport` · `http_<status>` | `api_context`, `duration_ms`, `api_code`, `api_param`, `trace_id`, `http_status`, `body_parsable` |
| `api.request_slow` | — | `api_context`, `method`, `duration_ms` (only at ≥ 3000 ms) |
| `php.fatal` | `php_fatal` | `level`, `origin`, `origin_plugin` |

`api_context` is the caller's fixed phrase (`checkout_create`, `checkout_lookup`,
`refund_create`), never the path: a path carries ids and the merchant host in its
query string. Only slow calls are timed as events — timing every call would fill
the queue with the requests nobody is investigating.

Every failure built from an exception or a file path also carries `origin`
(`plugin` / `theme` / `core` / `paypercut`) and, where applicable,
`origin_plugin`. The wire values are identical across all Paypercut plugins so
support can compare stores; only merchant-facing copy says "extension".

## What never goes on the wire

Card data (every value is Luhn-screened), credentials of any kind, refund reason
text (only `has_reason: bool`), customer names, email addresses, billing or
shipping addresses, order totals, line items, absolute filesystem paths, the
OpenCart user id of whoever started the session, and upstream API prose.

Four things enforce that, in order:

1. **Named constructors.** There is no generic "record these fields"
   constructor. `environment.snapshot` and `environment.configuration` walk their
   **own** declared schema and read keys out of the caller's array, never the
   reverse — pulling keys from a settings array is how a credential ends up on
   the wire.
2. **The deny assertion** in `PaypercutEventQueue::append()`, the one funnel every
   producer goes through. It screens the **whole envelope** exactly as it will be
   serialised — `attrs`, `error` (including `error.stack`) and the top-level
   correlation ids alike — two levels deep, against denied key names, denied
   value shapes, a Luhn PAN check, and the store's **actual** credentials.
   Screening a named subset is what let a card number ride in `order_ref`, so
   `isEnvelopeDenied()` takes the envelope whole and any field added to
   `envelope()` is covered by construction. **Keys are screened by the value
   rules too**, not only by the name-shape regex — a key is serialised exactly
   as a value is, and PHP turns a digits-only key into an int on the way in. The
   PAN check slides a 13–19 digit window across a digit run (a PAN with other
   digits pressed against it is still a PAN); a window inside a longer run must
   also carry an issuer prefix, or one long order id in ten would be denied on
   arithmetic alone. A tripped assertion **drops the whole event**, not the
   offending field: an event assembled wrongly cannot be trusted in its other
   parts either. Only the event *name* is audit-logged.
3. **"Our text yes, upstream text no."** No exception message ever travels:
   `PaypercutEvent::failure()` takes an exception's type and `file:line` stack
   and discards its message, and `apiFailure()` never reads the platform's
   `message` — OpenCart's database layer puts the failing statement and the
   connection's `'user'@'host'` in one, and the API quotes submitted input back
   in the other. `api_code`, `api_param`, `trace_id` and `error.type` carry the
   diagnosis. A message this extension authored
   (`because('threw RuntimeException')`) is the diagnosis and stays. `php.fatal`
   has no exception to name, so its message is trimmed to the first line and
   anything shaped like an account or address is redacted.
4. **Bounding.** Strings are clamped to 256 **bytes** (UTF-8 preserved, control
   characters stripped); identifier-shaped fields are dropped rather than
   mangled; stacks are `file:line` only, at most 8 frames, relative to the
   OpenCart install with `[external]` for anything outside it. Never
   `getTraceAsString()` — it renders call arguments, which here are checkout
   payloads and credentials. Clamping happens **after** the screen: `text()`
   screens the value the caller passed, and hands the assertion the untruncated
   value when it trips, because 15 of 16 PAN digits is not redaction — Luhn
   completes the sixteenth uniquely. The three correlation ids
   (`payment_intent_id`, `payment_id`, `order_ref`) are bounded to an identifier
   charset rather than free text, because they are the only wire values fed
   straight from an upstream payload and the webhook feeding two of them is
   unauthenticated; lossless here, since `orderRef()` returns the bare order id.
   A correlation id that does not fit drops the **field**, never the event.

`PaypercutTelemetrySession::credentials()` enumerates
`paypercut_api_key`, `paypercut_webhook_secret` and the live telemetry token.
**A future gateway adding its own credential setting silently breaks the literal
comparison** unless it is added there too.

## Structural blind spots

These are documented rather than closed.

1. **A store that has never connected cannot start a session** — the token is
   minted from the store's API key. First-time onboarding failures are invisible
   by construction.
2. **A credential or environment change ends the session mid-request**
   (`key_changed` / `environment_changed`), so anything after that point in those
   requests is not recorded.
3. **A card refused inside the checkout iframe is not visible.** Closing that
   needs browser-side telemetry.
4. **Fatals are only seen on our own routes.** OpenCart 2 has no extension-wide
   bootstrap, so `PaypercutFatalErrorWatch` is registered from the Paypercut
   controllers and the admin header event. A fatal on an unrelated storefront
   page is not reported.
5. **OpenCart records no version per extension.** `environment.plugins` lists
   installed extension codes with an empty version, plus enabled OCMod
   modifications with theirs. It still names the conflict candidates.
6. **Only `merchant_stopped` emits a `session.stopped` wire event.** Every other
   end reason (`expired`, `key_changed`, `environment_changed`, `token_lost`,
   `token_orphaned`, `edge_rejected`, `send_failed`, `deactivated`) is recorded
   locally only — the session's deadline is the server-side bound.

## Delivery windows

`PaypercutFlusher::flushOnce()` runs **only from authenticated admin requests**,
guarded by `PaypercutTelemetryContext::isAdminRequest()`: the panel's status
poll, the Stop handler, and one backstop on the settings page render. Never from
a storefront request, never from the webhook.

The admin flag is set explicitly by whichever controller booted telemetry rather
than sniffed from constants — OpenCart's admin and catalog entry points share
`DIR_SYSTEM`, and getting it wrong would put a telemetry POST on the checkout
critical path.

## Turning the panel off

Define `PAYPERCUT_TELEMETRY_DISABLED` as true in `admin/config.php`. Only
**start** is gated: stop and status stay reachable, so a session that is already
running can always be ended.

## Tests

`php tests/telemetry_test.php` — the deny assertion, the environment pairing,
snapshot schema isolation, `apiFailure()` message dropping, string bounding,
queue capping and batch splitting, and the flusher's full decision table. Run by
CI on every push (`.github/workflows/tests.yml`), alongside `php -l` over every
PHP file.

# plugin-opencart-v2

Paypercut payment module for **OpenCart 2.x** (PHP / OCMod). Ships hosted and
embedded checkout, refunds, capture/cancel, HMAC webhooks, Apple Pay domain
verification, and 13-locale translations.

> `CLAUDE.md` and `AGENTS.md` are symlinks to this file — always edit
> `README.md`, never the symlinks.

## Layout

```
install.xml                              OCMod manifest (version must match the git tag)
upload/admin/controller/extension/payment/paypercut.php   settings, connection, webhooks, debug session
upload/admin/controller/sale/paypercut_order.php          order panel: refund, capture, cancel
upload/catalog/controller/extension/payment/paypercut.php checkout, return, webhook  (PAYPERCUT_PLUGIN_VERSION)
upload/system/library/paypercut/environment.php           environment → API / telemetry hosts
upload/system/library/paypercut/telemetry/                debug-session library
tests/telemetry_test.php                                  standalone test suite
docs/                                                     runbooks + telemetry catalogue
```

Everything under `upload/` is copied into the OpenCart root by OCMod. `docs/`,
`tests/` and `.github/` are excluded from the release zip.

## Settings

Extensions → Payments → **Paypercut**. Four tabs plus **Diagnostics**.

**Environment** (API Configuration tab) selects which Paypercut environment the
store talks to. It decides **both** the payment API host and the telemetry edge
host — never resolve them separately:

| Environment | Payment API | Telemetry edge |
|---|---|---|
| `production` (default) | `https://api.paypercut.io/` | `https://telemetry.paypercut.io/` |
| `stage` | `https://api.stage.paypercut.net/` | `https://telemetry.stage.paypercut.net/` |
| `dev` | `https://api.dev.paypercut.net/` | `https://telemetry.dev.paypercut.net/` |

An unset or unknown value falls back to production for the API (so stores that
predate the setting keep working) and yields **no** telemetry session at all.
Both bases are accepted only on an `https` `paypercut.io` / `paypercut.net` host.

## Debug sessions

The **Diagnostics** tab starts a merchant-consented, self-expiring (~1 hour)
diagnostic feed to Paypercut support. Off by default; nothing is sent when no
session is running. Full event catalogue, privacy contract and storage layout:
[`docs/telemetry.md`](docs/telemetry.md).

Set `PAYPERCUT_TELEMETRY_DISABLED` to true in `admin/config.php` to hide the
Start button; Stop and status stay reachable so a running session can be ended.

## Database

The module owns `oc_paypercut_customer`, `oc_paypercut_transaction`,
`oc_paypercut_refund`, `oc_paypercut_webhook_log`,
`oc_paypercut_telemetry_store` and `oc_paypercut_telemetry_lock`. `install()`
creates them; `uninstall()` deliberately keeps the payment tables (transaction
history) and clears every telemetry key.

Extension settings live under setting code `paypercut`. The debug-session record
lives under its own code, `paypercut_telemetry`, because saving the settings form
deletes and re-inserts every `paypercut` row.

## Tests

```bash
php tests/telemetry_test.php
find upload tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Both run in CI (`.github/workflows/tests.yml`). The payment paths themselves have
no automated coverage yet — smoke-test them on the hosted dev store.

## Dev environment

Hosted dev store: <https://opencart-v2.dev.paypercut.net/admin> (Cloudflare
Access; ask the team for the store login). Built from the `opencart-dev` repo's
`v2/` directory — see `docs/hosted-deployment.md` there. The box is ephemeral and
re-seeds on every pod start, so connect the module to a sandbox account by hand
after a redeploy (Extensions → Installer first).

## Release

Tag `vX.Y.Z`. CI validates that `install.xml` and `PAYPERCUT_PLUGIN_VERSION` in
`upload/catalog/controller/extension/payment/paypercut.php` both match the tag,
then builds `paypercut-opencartv2-X.Y.Z.ocmod.zip` and publishes a release. Full
steps: [`docs/runbooks/release-new-version.md`](docs/runbooks/release-new-version.md).

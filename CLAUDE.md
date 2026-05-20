# plugin-opencart-v2

Paypercut's **OpenCart 2.x payment module** (PHP). Distributed from the `paypercut-oss` GitHub org. Lets OpenCart 2.x merchants accept card / Google Pay / Apple Pay via Paypercut, using either a **hosted checkout** redirect or an **embedded** inline form. Sibling repos cover OpenCart v3 + v4; an older `OpenCart-plugin` lives under the `paypercut` org and is legacy.

**Audience**: OpenCart 2.x merchants and store integrators wiring Paypercut as a payment gateway.

## Layout

**Single OCMod extension** (no Composer / npm, no build step). Mirrors the OpenCart filesystem under `upload/`; an `install.xml` at the root declares the OCMod manifest. CI/CD is one GitHub Actions workflow that packages a release zip.

## Architecture

- **`install.xml`** — OCMod 2.x manifest. Extension code `paypercut`; version is rewritten by the release workflow from the git tag.
- **`upload/catalog/...`** — storefront (customer-facing) side:
  - `controller/extension/payment/paypercut.php` — main payment controller: `index`, `send`, `confirm`, `callback`, `webhook`, `success`/`failure`/`pending`
  - `model/extension/payment/paypercut.php` — payment-method availability + currency validation
  - `view/theme/default/template/extension/payment/*.tpl` — checkout templates (OpenCart 2.x `.tpl` syntax)
  - `language/{locale}/extension/payment/paypercut.php` — storefront translations (13 locales)
- **`upload/admin/...`** — admin (merchant-facing) side:
  - `controller/extension/payment/paypercut.php` — settings form + table install + webhook/domain registration
  - `controller/sale/paypercut_order.php`, `paypercut_logs.php` — order-detail extensions and debug log viewer
  - `view/template/extension/payment/paypercut.tpl` — settings form (tabs: API, Payment, Webhooks, General)
  - `language/{locale}/...` — matching 13 admin locales
- **`upload/system/library/paypercut/apple-pay/`** — `apple-developer-merchantid-domain-association` file, copied to the store's `/.well-known/` on install + every settings save.
- **`docs/runbooks/`** — install, webhook troubleshooting, Apple Pay domain, release procedures.

## Payment flow

- **Hosted (default)**: `send()` calls `POST /v1/checkouts` → receives `url` + `id` → redirects the buyer to Paypercut Hosted Checkout. Buyer returns via `callback()`, which verifies status via `GET /v1/checkouts/{id}`.
- **Embedded**: `send()` creates a checkout session, then JS embeds the Paypercut form using `checkout_id`. `confirm()` (AJAX) verifies status; no external redirect.
- **Webhook fallback** for users who don't return — `payment_intent.captured` / `checkout_session.completed` finalizes the order.

## API integration

- Base: `https://api.paypercut.io/v1/` (hardcoded).
- Endpoints used: `POST /checkouts`, `GET /checkouts/{id}`, `GET /payments/{id}`, `POST|GET|PATCH /customers`, `GET|POST|DELETE /webhooks`, `GET /payment-configs/{id}`, `GET /payment_method_domains`.
- Auth: `Authorization: Bearer <api_key>` (key stored in OpenCart `oc_setting` as `paypercut_api_key`). Key prefix `sk_test_` / `sk_live_` auto-detects mode.

## Database (4 custom tables)

Created with `CREATE TABLE IF NOT EXISTS` in the admin install hook; **never dropped on uninstall** (intentional — transaction history is preserved). Prefix is OpenCart's `DB_PREFIX`.

| Table | Purpose |
|---|---|
| `paypercut_customer` | OpenCart customer ID ↔ Paypercut customer ID, indexed on email |
| `paypercut_transaction` | order/payment/checkout IDs, amount, currency, status, JSON payment-method details |
| `paypercut_refund` | refund history linked to transactions |
| `paypercut_webhook_log` | event-id-indexed webhook audit / idempotency |

## Webhook

- Endpoint: `/index.php?route=extension/payment/paypercut/webhook` (catalog side).
- Signature: HMAC-SHA256 with `X-Paypercut-Signature` against the configured webhook secret.
- Events: `payment_intent.captured`, `checkout_session.completed`.
- Idempotency: event ID stored in `paypercut_webhook_log` (UNIQUE) — duplicates skipped.
- Admin AJAX can create/delete the webhook on Paypercut and stores the returned secret locally.

## Common commands

This module has **no build step and no test suite**. Operations are packaging and install/uninstall via OpenCart.

```bash
# Package a release zip (mirrors what .github/workflows/release-zip.yml does on a v* tag)
git tag v1.0.1 && git push --tags  # → paypercut-opencartv2-<version>.ocmod.zip

# Install: Admin → Extensions → Installer → upload the .ocmod.zip,
# then Extensions → Modifications → Refresh, then enable Payment > Paypercut.
```

## Conventions

- **OCMod, not vQmod** — no patches against core files; everything lives under `upload/` and the OpenCart loader picks it up.
- **Configuration keys** are namespaced `paypercut_*` and stored via OpenCart's `Configuration::get/updateValue` equivalent (`$this->config->set`).
- **Supported currencies** are hard-limited to 12: BGN, DKK, SEK, NOK, GBP, EUR, USD, CHF, CZK, HUF, PLN, RON. Unsupported currency in the cart auto-disables the payment method.
- **Translations** wrap user-facing strings via `$this->language->get('...')`. New strings need entries in all 13 locale files.
- **Logging** routes through `paypercut.log` / `paypercut_error.log` when `paypercut_logging` is enabled.

## Gotchas

- **PCI scope**: the plugin redirects to Paypercut Hosted Checkout or renders Paypercut's embedded iframe. It must **never** collect raw PAN data itself. Card fields belong to Paypercut.
- **Apple Pay**: the domain-association file is bundled and auto-copied to the store's `/.well-known/` on install + on every settings save. If the file isn't reachable over HTTPS or the domain isn't registered via `POST /v1/payment_method_domains`, Apple Pay silently fails at checkout.
- **Currency lock-in**: not just a warning — the payment method **disables itself** if the store currency isn't in the supported list. Merchants must switch currency or contact support.
- **Session-bound checkout ID**: `$this->session->data['paypercut_checkout_id']` carries state across the redirect round-trip. Session expiry mid-flow breaks the callback — no retry.
- **Webhook secret sync**: deleting the webhook in the Paypercut dashboard without deleting it in the OpenCart admin leaves the local secret stale and idempotency breaks.
- **Uninstall preserves data**: the four `paypercut_*` tables are NOT dropped on uninstall (intentional). Drop manually if needed for a clean re-test.
- **Currency in minor units**: API expects integer cents/pence. The plugin uses `round()` before casting to int — don't introduce float math upstream of that conversion.
- **Customer record drift**: if Paypercut deletes a mapped customer, the plugin silently re-creates it on next checkout. Local `paypercut_customer` rows can outlive their remote counterparts.
- **Payment handoff with paycore**: checkout sessions, statuses, refunds all flow through `api.paypercut.io` (paycore). This plugin is a thin adapter — non-2xx responses are user-visible; route them through `$this->log` for support diagnostics.

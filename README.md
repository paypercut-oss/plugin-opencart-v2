# plugin-opencart-v2

Paypercut payment module for **OpenCart 2.x** (PHP / OCMod). Ships hosted and
embedded checkout, refunds, capture/cancel, HMAC webhooks, Apple Pay domain
verification, and 13-locale translations.

> `CLAUDE.md` and `AGENTS.md` are symlinks to this file — always edit
> `README.md`, never the symlinks.

## Download the plugin (merchants start here)

The file you install in OpenCart is a ZIP named
`paypercut-opencartv2-X.Y.Z.ocmod.zip` (`X.Y.Z` is the version number, for
example `paypercut-opencartv2-1.1.2.ocmod.zip`). It is published on the
**Releases** page of this repository, not in the code files.

1. Open the latest release: <https://github.com/paypercut-oss/plugin-opencart-v2/releases/latest>
   (all versions: <https://github.com/paypercut-oss/plugin-opencart-v2/releases>).
2. Scroll down to the **Assets** section at the bottom of the release.
3. Click `paypercut-opencartv2-X.Y.Z.ocmod.zip` to download it.

Things to know:

- **Do not unzip it.** OpenCart installs the `.ocmod.zip` file as it is.
- **Do not use the green *Code → Download ZIP* button** on the repository
  page, and do not use the `Source code (zip)` / `Source code (tar.gz)` links in
  the Assets list. Those are a copy of the source code (including developer files),
  not the installable module.
- The `.ocmod.zip` is created automatically by the release workflow
  ([`.github/workflows/release-zip.yml`](.github/workflows/release-zip.yml)).
  The release marked **Latest** is the newest version.

### Install it in OpenCart 2.x

1. In OpenCart admin, go to **Extensions → Installer** and upload the
   `.ocmod.zip`. Wait for the success message.
2. Go to **Extensions → Modifications** and click **Refresh** (top-right).
3. Go to **Extensions → Extensions**, choose **Payments** in the filter, find
   **Paypercut Payments** and click **Install** (green plus).
4. Click **Edit** (blue pencil) to open the settings, fill them in and save.

Upgrading from an older version, rolling back and troubleshooting:
[`docs/runbooks/install-upgrade-module.md`](docs/runbooks/install-upgrade-module.md).

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

An **unset** value is a store that predates the setting: it reads as
`production`, which is where its payments have always gone. A value that **is**
set but unrecognised falls back to production for the API (so checkout keeps
working) and yields **no** telemetry session at all. Both bases are accepted only
on an `https` `paypercut.io` / `paypercut.net` host, and there is no override
that can move one host without the other.

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

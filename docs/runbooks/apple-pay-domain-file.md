# Apple Pay domain-association file is missing or unreachable

**Audience:** support, merchant-facing engineer
**Severity:** SEV-3
**Est. time:** ~10 minutes
**Last verified:** 2026-05-04

## When to use

- Apple Pay button does not appear at checkout despite `Enable Apple Pay = Yes`
  in the PayPerCut admin settings.
- PayPerCut dashboard shows the merchant's domain as `enabled: false` under
  **Payment Method Domains** even after re-saving credentials.
- Admin settings page shows the red "Apple Pay domain verification file is
  missing" or yellow "...storefront did not return it over HTTPS" banner under
  the Apple Pay row.
- Apple's domain-verification check fails when registering via the PayPerCut
  dashboard.

## Background

Apple Pay requires the merchant's storefront to serve a small verification
file at exactly:

```
https://<merchant-domain>/.well-known/apple-developer-merchantid-domain-association
```

The plugin places this file automatically on install and on every settings
save. The bytes come from `https://cdn.paypercut.io/.well-known/apple-developer-merchantid-domain-association`
(fresh fetch with 3s timeout) with a fallback to a copy bundled inside the
release zip at
[upload/system/library/paypercut/apple-pay/apple-developer-merchantid-domain-association](../../upload/system/library/paypercut/apple-pay/apple-developer-merchantid-domain-association).

The file is intentionally **not** removed on plugin uninstall.

## Preconditions

- OpenCart admin access for the affected merchant.
- SFTP/SSH access to the OpenCart filesystem (for permission fixes).
- The merchant's storefront URL.

## Procedure

1. Open **Extensions → Extensions → Payments → PayPerCut Payments → Edit**,
   scroll to **Payment Settings → Apple Pay**.
2. Click **Refresh from PayPerCut CDN**. The page reloads.
    - Green banner → file is in place and the storefront returned 200 OK on a
      self-test. Done — proceed to Verification.
    - Yellow banner → file is on disk but the storefront did not return it.
      Continue with step 3.
    - Red banner → write failed. Continue with step 4.
3. **Yellow banner — file present but unreachable.** Open the URL printed in
   the banner directly in a browser (or curl):
    ```bash
    curl -fsSI "https://<merchant-domain>/.well-known/apple-developer-merchantid-domain-association"
    ```
    - **404 / 403** → web server is blocking dotfile directories or rewriting
      the request to OpenCart's `index.php`. See Troubleshooting below.
    - **301 / 302** → redirect breaks Apple's verifier. Disable any redirect
      rule for `/.well-known/` (CDN, server config). The file must answer 200
      on the canonical HTTPS URL with no redirect.
    - **OpenCart in subpath** (e.g. `https://shop.example.com/store/`) →
      the plugin wrote the file at `<store>/.well-known/...`, but Apple
      checks at the domain root. Copy or symlink the file to
      `<domain-root>/.well-known/apple-developer-merchantid-domain-association`.
4. **Red banner — write failed.** SSH/SFTP into the host as the OpenCart
   process user and check filesystem permissions:
    ```bash
    ls -ld <opencart_root>
    ls -ld <opencart_root>/.well-known 2>/dev/null
    ```
    - Directory must be writable by the PHP process user (typically `www-data`,
      `apache`, or the merchant's cPanel user). Fix with `chmod 0755` and the
      correct owner.
    - If `open_basedir` restricts writes to OpenCart's tree, the auto-place
      will succeed (target is inside the tree). If `open_basedir` is tighter,
      drop the file manually as a fallback:
        ```bash
        cd <opencart_root>
        mkdir -p .well-known
        curl -fsSL "https://cdn.paypercut.io/.well-known/apple-developer-merchantid-domain-association" \
          -o .well-known/apple-developer-merchantid-domain-association
        chmod 0644 .well-known/apple-developer-merchantid-domain-association
        ```
5. Click **Refresh from PayPerCut CDN** again. The banner should turn green.
6. In the PayPerCut dashboard, re-trigger domain verification for Apple Pay
   (or save the plugin settings — the existing
   `ensurePaymentMethodDomain()` call does this automatically). Confirm the
   domain flips to `enabled: true`.

## Verification

- `curl -fsSI https://<merchant-domain>/.well-known/apple-developer-merchantid-domain-association`
  returns `HTTP/2 200` (or `HTTP/1.1 200 OK`) with no redirect.
- Body sha256 matches the CDN copy:
    ```bash
    diff \
      <(curl -fsSL "https://<merchant-domain>/.well-known/apple-developer-merchantid-domain-association") \
      <(curl -fsSL "https://cdn.paypercut.io/.well-known/apple-developer-merchantid-domain-association")
    ```
    Empty diff = match.
- PayPerCut dashboard shows the merchant's domain as `enabled: true` for
  Apple Pay.
- Apple Pay button appears at checkout on Safari (macOS / iOS) for an
  eligible cart.

## Rollback

Not destructive — re-running the refresh button re-writes the same bytes.
To remove the file entirely (e.g. merchant decommissioned Apple Pay):

```bash
rm <opencart_root>/.well-known/apple-developer-merchantid-domain-association
# Leave .well-known/.htaccess in place if other tools use it (Let's Encrypt etc.)
```

## Troubleshooting

| Symptom                                          | Likely cause                                                                           | Action                                                                                                                          |
| ------------------------------------------------ | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| 403 Forbidden on the verifier URL                | Apache/host blocks dotfile directories                                                 | Confirm `<opencart_root>/.well-known/.htaccess` exists (the plugin drops one). If host disables `.htaccess`, edit vhost config. |
| 404 Not Found, but file is on disk               | OpenCart's URL rewrite caught the request                                              | Confirm `RewriteCond %{REQUEST_FILENAME} !-f` is intact in `<opencart_root>/.htaccess` (default OpenCart config — should be).   |
| 200 but body differs from CDN                    | A stale copy was placed manually before the plugin update                              | Click Refresh in admin, or `curl -o` the CDN copy as in step 4.                                                                 |
| Banner stuck on yellow on a reachable host       | Self-test inside admin can't reach the catalog hostname (NAT / split-DNS / firewall)   | Verify with an external curl. If the external 200 OK passes, ignore the yellow banner — it's an admin-side reachability quirk. |
| Nginx host: file 404                             | No `.htaccess` equivalent — needs server-block config                                  | Add `location /.well-known/ { try_files $uri =404; }` ahead of OpenCart's PHP fall-through, then reload Nginx.                  |
| OpenCart at subpath, file at `/store/.well-known/...` | Domain root and OpenCart root differ                                              | Copy/symlink to the domain root (step 3, last bullet). The plugin can't write outside its tree.                                 |

## Escalation

- Persistent verification failures with the file confirmed at the right URL:
  hand off to PayPerCut platform team via the support channel — they can
  inspect the dashboard-side domain-verification logs.
- Hosting-specific permission issues that the merchant cannot fix: escalate
  to the merchant's hosting support with the exact commands from step 4.

## References

- Apple Pay setup guide: <https://docs.paypercut.io/docs/accept-payments/apple-pay>
- Canonical CDN copy: <https://cdn.paypercut.io/.well-known/apple-developer-merchantid-domain-association>
- Bundled fallback: [upload/system/library/paypercut/apple-pay/apple-developer-merchantid-domain-association](../../upload/system/library/paypercut/apple-pay/apple-developer-merchantid-domain-association)
- Place / refresh logic: [upload/admin/controller/extension/payment/paypercut.php](../../upload/admin/controller/extension/payment/paypercut.php) — `ensureAppleDomainAssociationFile()`, `refreshAppleDomainFile()`
- Admin UI banner: [upload/admin/view/template/extension/payment/paypercut.tpl](../../upload/admin/view/template/extension/payment/paypercut.tpl)
- Related: [install-upgrade-module.md](install-upgrade-module.md),
  [webhook-not-received.md](webhook-not-received.md)

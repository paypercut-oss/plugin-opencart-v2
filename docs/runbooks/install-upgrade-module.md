# Install or upgrade the PayPerCut module in OpenCart

**Audience:** support, merchant-facing engineer
**Severity:** routine
**Est. time:** ~15 minutes
**Last verified:** 2026-04-22

## When to use

- Guiding a merchant through a first-time install.
- Upgrading an existing install to a newer `.ocmod.zip`.

## Preconditions

- OpenCart 2.x admin access with permission to install extensions and modify
  modifications.
- The latest release zip from
  `https://github.com/paypercut/plugin-opencart-v2/releases` —
  `paypercut-opencartv2-X.Y.Z.ocmod.zip`.
- PayPerCut API credentials for the merchant's account.

## Procedure

1. **Back up** the store before upgrading: database dump + `upload/` filesystem
   snapshot. For fresh installs, skip this step.
2. In OpenCart admin, go to **Extensions → Installer** and upload the
   `.ocmod.zip`. Confirm the success banner.
3. Go to **Extensions → Modifications** and click **Refresh** (top-right).
   This applies the OCMOD to the running codebase.
4. Go to **Extensions → Extensions**, choose **Payments** in the filter, locate
   **PayPerCut Payments**, and click **Install** (green plus) if this is a
   first-time install. For upgrades, the extension remains installed.
5. Click **Edit** (blue pencil) on **PayPerCut Payments** and fill in:
    - API key / secret (merchant's PayPerCut credentials)
    - Status: `Enabled`
    - Order statuses as per merchant preference
6. Save. If this is an upgrade, clear any OpenCart cache the store uses
   (Twig/SASS cache, Modifications cache).

## Verification

- **Extensions → Modifications** lists `Paypercut Payments` with the expected
  version from [install.xml](../../install.xml).
- Storefront checkout shows PayPerCut as a payment option for an eligible cart.
- Place a test order; it reaches PayPerCut hosted checkout successfully.
- Admin **Sales → PayPerCut Logs** records the test order request/response.

## Rollback

1. In **Extensions → Modifications**, disable or delete the `paypercut` entry,
   then click **Refresh** to revert file patches.
2. In **Extensions → Extensions → Payments**, uninstall **PayPerCut Payments**.
3. If files remain, restore the pre-upgrade filesystem snapshot.
4. Restore the database backup only if data corruption is suspected — the
   module creates additive tables, so this is rarely needed.

## Troubleshooting

| Symptom                            | Likely cause                               | Action                                                                                               |
| ---------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------- |
| Upload fails with permission error | `upload/` or `system/storage` not writable | Fix filesystem permissions, retry                                                                    |
| Module not visible under Payments  | Modifications not refreshed                | Click **Refresh** in Extensions → Modifications                                                      |
| Checkout shows no PayPerCut option | Not enabled, or geo/currency restriction   | Re-check extension settings and cart currency                                                        |
| Test order errors out              | Bad API credentials                        | Re-enter credentials; see [webhook-not-received.md](webhook-not-received.md) for post-payment issues |

## Escalation

- If install succeeds but payments misbehave, hand off to
  [webhook-not-received.md](webhook-not-received.md) or
  [payment-stuck-pending.md](payment-stuck-pending.md).
- For permission/server issues, escalate to the merchant's hosting support.

## References

- Controllers: [upload/admin/controller/extension/payment/paypercut.php](../../upload/admin/controller/extension/payment/paypercut.php),
  [upload/catalog/controller/extension/payment/paypercut.php](../../upload/catalog/controller/extension/payment/paypercut.php)
- Manifest: [install.xml](../../install.xml)
- Related: [release-new-version.md](release-new-version.md),
  [rollback-release.md](rollback-release.md)

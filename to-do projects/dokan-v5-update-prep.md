# Dokan v5.0 Update Prep — Loothtool

**Date:** 2026-04-29
**Branch:** main
**Status:** In Progress

---

## Context

On 2026-04-27, Ian Davlin forwarded an email from `hello@dokan.co` titled "Dokan Plugin v5.0 Drops Very Soon!" to `vanlaarhovenguitars@gmail.com`. The email outlined a structural Dokan release with several breaking-change risks for any site running custom Dokan integrations.

As of 2026-04-29, **Dokan Lite v5.0.0 is already published** to the wp.org repo (confirmed via `wp plugin list` on the dev server — current 4.0.8 → available 5.0.0). Dokan Pro v5.0 is not yet visible in WP-CLI; it requires a manual zip download from the licensing portal.

Loothtool runs 5 custom plugins + a child theme that all hook into Dokan's vendor dashboard, registration, commission, and order-payout pipelines. This project mapped every integration point, captured a current-state snapshot, and staged a backup script so Buck can run a clean update when ready.

This file lives in the `loothtool-commissions` repo because that plugin is the highest-risk anchor for v5 (commission filter hooks, `wp_dokan_vendor_balance` query, and a built-in version sentinel that will fire an admin notice the moment Dokan flips to major v5).

---

## Decisions Made

1. **Backup before everything** — server-side `mysqldump` + tarball of Dokan + theme + all loothtool-* plugins, plus an in-WP-admin backup (UpdraftPlus / AIO-WP-Migration) as belt-and-suspenders.
2. **Update Dokan Lite first, then Dokan Pro** — confirmed by the email; Lite/Pro version skew causes vendor dashboard data corruption.
3. **Run the Dokan Upgrader after both updates** — non-skippable; it migrates vendor sales/orders/earnings reads to WooCommerce Analytics.
4. **Defer code patches until v5 is live** — most of the high-risk integration patches (transfer fallback meta keys, commission filter renames, balance table existence) can only be validated against the actual v5 API surface. Pre-update, only audit + version capture + backup script staging are useful.
5. **Retire `dokan/seller-registration-form.php` template override** — the legacy "I am a customer / I am a vendor" toggle is killed in v5, replaced by a dedicated shortcode-driven Onboarding page. The override either gets ignored or breaks; safest path is delete + migrate.
6. **No Delivery Time work needed** — codebase grep confirmed we don't use that Dokan module.
7. **Pre-existing `LT_COMM_DOKAN_TESTED_MAJOR` sentinel is left in place** — it's already set to 4 in `loothtool-commissions.php:21` and will auto-fire an admin warning on v5 install. No change needed; just be aware it'll trigger.

---

## What Was Built

### Documentation

- `C:\Users\vanla\.claude\plans\fwd-dokan-plugin-v5-0-snug-pnueli.md` — full plan file with context, scope split (Claude vs. Buck), integration map, pre-update checklist, update-day steps, post-update Claude work, verification, and rollback. Comprehensive reference for resuming.

### Backup script

- `E:\Documents\Loothtool\scripts\pre-dokan-v5-backup.sh` — one-command server-side backup. Run from local Bash. Prompts once for MySQL password, then SSHes in and writes:
  - `~/backups/loothtool_dev-pre-dokan-v5-<TS>.sql.gz` (gzipped DB dump)
  - `~/backups/loothtool_dev-files-pre-dokan-v5-<TS>.tar.gz` (Dokan + theme + all loothtool-* plugins)
  - `~/backups/version-snapshot-<TS>.txt` (version snapshot for rollback)

### Server-side state captured (2026-04-29)

| Component | Current | v5 / latest available |
|---|---|---|
| WP core | 6.9.4 | — |
| Dokan Lite | 4.0.8 | **5.0.0** (live in wp.org repo right now) |
| Dokan Pro | 4.0.9 | none via WP — manual zip from license portal |
| WooCommerce | 10.2.4 | 10.7.0 |
| PHP | 8.3.30 | — |
| WP-CLI | 2.12.0 | — |
| Disk free on `/var/www` | 8.5G of 29G | — |
| `~/backups/` | does not exist | created by backup script |
| `~/.my.cnf` | not present | DB password must be entered manually |

---

## What Still Needs to Be Built

### Phase 1 — Pre-update (when Buck is ready to update)

1. Run `bash E:/Documents/Loothtool/scripts/pre-dokan-v5-backup.sh` and verify the three backup files land in `~/backups/`. Pull the SQL dump down to the laptop with `scp` for off-server safety.
2. Take an in-WP-admin backup (UpdraftPlus or AIO-WP-Migration) as a second copy.
3. Confirm Dokan Pro v5.0 zip is downloaded from the license portal and ready to upload.

### Phase 2 — Update day (Buck does in WP admin)

1. Update Dokan Lite → 5.0 (Plugins screen, normal Update button).
2. Reload WP admin, confirm no fatal errors.
3. Upload Dokan Pro v5.0 zip via Plugins → Add New → Upload (overwrite existing).
4. Click "Run Dokan Upgrader" when prompted — **do not skip this**.
5. Wait for Action Scheduler to drain (Tools → Scheduled Actions, watch for `dokan_*` jobs to clear).
6. Smoke-test each custom vendor dashboard tab as a test vendor: Shipping Labels, Payouts, Earnings, Advertising, Shopify Sync.
7. Place a test order; verify commission split + Stripe transfer + label flow.
8. WP Admin → Analytics → Settings → review which order statuses are included so Dokan Reports show the right rows.

### Phase 3 — Post-update Claude work (resume from here when v5 is live)

1. Test that `dokan()->order->all()`, `dokan_get_dashboard_nav`, `dokan_load_custom_template` still resolve and behave the same.
2. Audit `_dokan_vendor_to_pay` order meta. If absent in v5, swap our fallback in `loothtool-stripe-connect/includes/class-transfer-processor.php:65-68` to read exclusively from `_lt_comm_vendor_payout` (which we set ourselves) or from a WC-Analytics-backed lookup.
3. Audit `wp_dokan_vendor_balance` query in `loothtool-commissions/loothtool-commissions.php:63`. If the table is gone in v5, switch to a WC-Analytics-backed lookup or remove the existence gate entirely.
4. Verify `dokan_prepare_for_calculation` and `dokan_get_vendor_percentage_commissions` filters still fire. If renamed or removed, patch `class-commission-calculator.php:21-22, 28-29`.
5. Decide fate of `loothtool-wordpress-theme/dokan/seller-registration-form.php`. Recommended path: delete the template override, since the new shortcode supersedes it. Alternative: port the custom fields to a `dokan_vendor_registration_form_fields`-style filter on the new flow.
6. Build the new Vendor Onboarding page:
   - Create a WP page titled "Become a Vendor".
   - Drop in the new Dokan vendor onboarding shortcode (exact name TBD when v5 is inspected).
   - Style to match Loothtool dark theme.
   - Update any link in `loothtool-wordpress-theme/header.php` and `front-page.php` that points to `/my-account/?vendor=1` (or similar legacy URL) to point to the new page.
7. Bump `LT_COMM_DOKAN_TESTED_MAJOR` from `4` to `5` in `loothtool-commissions/loothtool-commissions.php:21` ONLY after every test above passes. This clears the auto-fired admin warning.

### Phase 4 — Verification

- WP Admin → Plugins: Dokan Lite 5.0+ active, Dokan Pro 5.0+ active, no fatal-error notices.
- WP Admin → Tools → Scheduled Actions: no failed `dokan_*` jobs.
- WP Admin → Dokan → Reports: order rows visible (cross-check against WC Orders count).
- Frontend vendor dashboard: all 5 custom tabs render without 404 / blank pages.
- Test order with a vendor product: order completes, commission split row appears in `wp_lt_commissions`, Stripe transfer fires (if dev Stripe Connect is enabled), Shipping Labels tab shows the new order.
- New "Become a Vendor" page renders v5 shortcode and accepts a registration end-to-end.

---

## Architecture Notes

### Server access

- SSH: `ssh -i E:/Documents/Loothtool/buck_key buck@54.157.13.77` (origin IP, NOT `dev.loothtool.com` — Cloudflare blocks port 22)
- `buck` user has no sudo — file group is `tool-dev`
- WordPress root: `/var/www/dev.loothtool/`
- DB: MySQL local, `loothtool_dev` / `loothtool_dev_user`

### Loothtool's Dokan integration map (verified 2026-04-29)

| Plugin / file | Lines | Hooks/data | Risk |
|---|---|---|---|
| `loothtool-shipping/includes/class-vendor-dashboard.php` | 18-21 | `dokan_query_var_filter`, `dokan_get_dashboard_nav`, `dokan_load_custom_template` | LOW — public APIs |
| `loothtool-shipping/includes/class-vendor-dashboard.php` | 510-522 | direct `wp_dokan_shipping_tracking` SELECT | MEDIUM — internal table |
| `loothtool-stripe-connect/includes/class-vendor-dashboard.php` | 20-23 | same trio | LOW |
| `loothtool-stripe-connect/includes/class-transfer-processor.php` | 58, 65, 68 | reads `_dokan_vendor_id`, `_lt_comm_vendor_payout`, fallback `_dokan_vendor_to_pay` | MEDIUM — fallback meta key may shift |
| `loothtool-commissions/includes/class-commission-calculator.php` | 21-22, 28-29 | `dokan_prepare_for_calculation`, `dokan_get_vendor_percentage_commissions` | MEDIUM — internal calc hooks |
| `loothtool-commissions/includes/class-order-processor.php` | 29, 34, 38, 41, 45 | `dokan_checkout_update_order_meta` (×2), `dokan_new_order_processing_charge`, `woocommerce_order_status_changed` (×2) | LOW — well-spread fallbacks |
| `loothtool-commissions/loothtool-commissions.php` | 21 | `LT_COMM_DOKAN_TESTED_MAJOR = 4` sentinel | safety net — fires admin notice on v5 |
| `loothtool-commissions/loothtool-commissions.php` | 63 | `SHOW TABLES LIKE wp_dokan_vendor_balance` | MEDIUM — table may be deprecated |
| `loothtool-ads/includes/class-vendor-ads-dashboard.php` | 22-23 | `dokan_get_dashboard_nav`, `dokan_load_custom_template` | LOW |
| `loothtool-shopify-sync/includes/class-vendor-connect.php` | 26-27 | same | LOW |
| `loothtool-wordpress-theme/dokan/store.php` | — | template override for storefront | LOW |
| `loothtool-wordpress-theme/dokan/seller-registration-form.php` | 51, 81, 85, 90-95 | legacy registration hooks + deprecated "I am a customer / vendor" toggle | **HIGH** — flow is killed in v5 |

### Delivery Time module

Not used. Codebase grep confirmed zero matches for `delivery_time` or `delivery time`. No work needed.

---

## Key Code References

| File | Line | What |
|---|---|---|
| `loothtool-commissions/loothtool-commissions.php` | 21 | `LT_COMM_DOKAN_TESTED_MAJOR` sentinel — bump to 5 after verification |
| `loothtool-commissions/loothtool-commissions.php` | 63 | `wp_dokan_vendor_balance` existence check |
| `loothtool-commissions/includes/class-commission-calculator.php` | 21-22 | `dokan_prepare_for_calculation` filter |
| `loothtool-commissions/includes/class-commission-calculator.php` | 28-29 | `dokan_get_vendor_percentage_commissions` filter |
| `loothtool-commissions/includes/class-order-processor.php` | 29-45 | Order pipeline hooks (multi-stage) |
| `loothtool-stripe-connect/includes/class-transfer-processor.php` | 65-68 | Vendor payout meta — `_lt_comm_vendor_payout` then fallback `_dokan_vendor_to_pay` |
| `loothtool-shipping/includes/class-vendor-dashboard.php` | 510-522 | Direct `wp_dokan_shipping_tracking` query |
| `loothtool-wordpress-theme/dokan/seller-registration-form.php` | all | Retire — legacy flow killed in v5 |
| `E:/Documents/Loothtool/scripts/pre-dokan-v5-backup.sh` | all | One-command pre-update backup script |
| `C:/Users/vanla/.claude/plans/fwd-dokan-plugin-v5-0-snug-pnueli.md` | all | Full plan (context, scope split, checklists) |

---

## Open Questions

1. **What's the exact shortcode name** for the new Vendor Onboarding page in v5? Email mentions it but doesn't quote it. Resolved by inspecting Dokan v5 docs after install.
2. **Will `_dokan_vendor_to_pay` order meta still be written in v5**, or does it move to a different storage now that earnings come from WC Analytics? Need to test post-install.
3. **Will `wp_dokan_vendor_balance` table still exist** in v5, or is it deprecated in favor of WC Analytics? Either way, `loothtool-commissions/loothtool-commissions.php:63` tolerates absence (just shows a notice), so non-blocking.
4. **Does Buck have the Dokan Pro v5.0 zip** ready in his license portal? If not, that has to come first before update day.
5. **What date does Buck want to do the update?** No deadline currently set — site is dev/staging, no production pressure.

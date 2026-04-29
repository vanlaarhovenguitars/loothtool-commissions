# Stripe Connect Express Integration

**Date:** 2026-04-21
**Branch:** main (loothtool-commissions repo, but plugin code lives in separate `loothtool-stripe-connect/` folder at `E:/Documents/Loothtool/loothtool-stripe-connect/`)
**Status:** In Progress

---

## Context

Loothtool is a multi-vendor WooCommerce/Dokan marketplace for guitar gear. Until this project, vendor payouts were tracked manually — Dokan calculated balances but cutting checks/transfers was a human process. This integration automates payouts using Stripe Connect Express:

- Customer pays via WooCommerce Stripe Gateway (already installed) → money lands in the platform's Stripe account
- The existing `loothtool-commissions` plugin calculates each vendor's payout (per-product split rules, processing fee deductions, cross-vendor designer commissions)
- The new `loothtool-stripe-connect` plugin reads the finalized payout amount and issues Stripe Transfers to each vendor's connected Express account

The architectural decision was to use **"Separate Charges and Transfers"** (not Destination Charges) so the existing commission math stays completely untouched. The new plugin only handles the "last mile" — moving money that's already been calculated.

---

## Decisions Made

1. **Custom plugin, not Dokan's built-in stripe-express module.** Dokan's module would conflict with the heavy commission overrides in `loothtool-commissions` (per-product splits, processing fee deductions, cross-vendor secondary payouts). Going custom keeps the existing math intact.

2. **Separate Charges and Transfers, not Destination Charges.** Destination Charges require setting `application_fee_amount` at charge time, before the commission system has finished calculating. Separate-and-transfer lets the commission plugin run normally, then we transfer the exact `_lt_comm_vendor_payout` afterwards.

3. **Hook on `woocommerce_order_status_completed` (priority 10), not `processing`.** Gives a refund window before funds actually transfer. Configurable via plugin setting.

4. **Bundle the Stripe PHP SDK in `lib/`, no Composer on server.** Server is Bitnami-style; using `require_once lib/init.php` keeps deployment simple.

5. **AES-256-CBC encryption for API keys + webhook secret**, reusing the same pattern as `LT_Vendor_Credentials` in `loothtool-shipping`.

6. **Skip `account.application.deauthorized` event.** Not available under Stripe's "Connected and v2 accounts" webhook scope; would require a second webhook on "Your account" scope. Not critical for testing — added later if needed.

7. **Server migration discovered mid-project.** Original CLAUDE.md said `bitnami@dev.loothtool.com` (old Bitnami AWS Lightsail). Real current server is `buck@54.157.13.77` (Nginx + PHP 8.3 FPM at `/var/www/dev.loothtool/`), with Cloudflare proxying the hostname (so SSH must use the IP). Updated CLAUDE.md to reflect this.

---

## What Was Built

### New plugin: `loothtool-stripe-connect/`

Located at `E:/Documents/Loothtool/loothtool-stripe-connect/`. **Not yet on GitHub** — needs a new repo at `vanlaarhovenguitars/loothtool-stripe-connect`.

**Files created:**

| File | Purpose |
|---|---|
| `loothtool-stripe-connect.php` | Plugin bootstrap, loads SDK + classes, activation hook |
| `lib/` | Bundled Stripe PHP SDK v16.5.0 (downloaded as tarball from GitHub release) |
| `includes/class-stripe-api.php` | SDK wrapper, encryption helpers, webhook signature verification |
| `includes/class-payout-log.php` | Custom DB table `wp_lt_stripe_transfers` + query methods |
| `includes/class-admin-settings.php` | WP Admin settings page (keys, mode toggle, vendor list, transfer log) |
| `includes/class-onboarding.php` | Express account creation, Account Links flow, login link, disconnect |
| `includes/class-transfer-processor.php` | Hooks `woocommerce_order_status_completed`, issues primary + cross-vendor transfers |
| `includes/class-webhook-handler.php` | REST endpoint at `/wp-json/lt-stripe-connect/v1/webhook`, handles 7 event types |
| `includes/class-vendor-dashboard.php` | Dokan "Payouts" tab (3 states: not connected, incomplete, connected) |
| `assets/vendor-dashboard.css` | Dark theme matching site palette (`#021C1E` bg, `#2C7873` accent) |
| `assets/vendor-dashboard.js` | Connect / Manage / Disconnect button handlers |

### Modified

- **`E:/Documents/Loothtool/CLAUDE.md`** — overhauled the SERVER ACCESS, ARCHITECTURE, DEPLOY WORKFLOW, SERVER PATH MAPPING, USEFUL SERVER COMMANDS, and GOTCHAS sections to reflect the post-Bitnami migration. Added `loothtool-stripe-connect/` to the file structure.

### Created (handoff doc)

- **`E:/Documents/Loothtool/STRIPE-HANDOFF.md`** — print-ready document for the Looth Group owner walking through the entire Stripe-side setup. Includes a project-manager-style Claude prompt at the top so his Claude can drive the whole setup. Buck's contact info filled in (619-512-2193).

---

## Live On Server (already deployed and configured)

The plugin is fully deployed at `/var/www/dev.loothtool/wp-content/plugins/loothtool-stripe-connect/`, owned `buck:tool-dev` (775/664).

**Verified working:**
- ✅ All 8 PHP files pass `php -l` syntax check
- ✅ Plugin activated via WP-CLI
- ✅ Custom DB table `wp_lt_stripe_transfers` created with 4 indexes
- ✅ REST routes registered: `/lt-stripe-connect/v1/webhook` + `/onboarding-return`
- ✅ All 3 AJAX hooks registered (create_account, disconnect, login_link)
- ✅ Transfer hook on `woocommerce_order_status_completed` priority 10

**Stripe credentials saved (test mode):**
- ✅ `lt_sc_test_mode` = "1"
- ✅ `lt_sc_test_publishable_key` = `pk_test_51TKJkzE24AZkkSiD6...` (Looth Group dev Stripe account)
- ✅ `lt_sc_test_secret_key` = encrypted, decrypts cleanly (round-trip verified)
- ✅ `lt_sc_webhook_secret` = `whsec_Zbu8nCG41mZXmlCwb34lYWN1t75HxfGF` (encrypted, decrypts cleanly)
- ✅ Stripe API connection live, account `acct_1TKJkzE24AZkkSiD` confirmed (US, charges_enabled, payouts_enabled, Connect platform set up)

**Webhook configured in Stripe Dashboard:**
- Endpoint: `https://dev.loothtool.com/wp-json/lt-stripe-connect/v1/webhook`
- Scope: "Connected and v2 accounts"
- Events subscribed (6 — `account.application.deauthorized` skipped):
  - `account.updated`
  - `transfer.created`
  - `transfer.reversed`
  - `transfer.updated`
  - `payout.paid`
  - `payout.failed`

---

## What Still Needs to Be Built

### Phase 1 — Test end-to-end (NEXT, in progress)

1. **Connect a test vendor account.** Vendor logs into dev.loothtool.com → Dokan dashboard → "Payouts" tab → Connect with Stripe → completes Stripe-hosted onboarding using test bank details (routing `110000000`, account `000123456789`) and SSN `000-00-0000`.
2. **Run a test purchase.** Customer (incognito browser) buys a product belonging to that vendor, pays with test card `4242 4242 4242 4242`.
3. **Mark order Completed** in WooCommerce admin.
4. **Verify the transfer in 3 places:**
   - WP Admin → Settings → LT Stripe Connect → Recent Transfers (should show "created" status with correct amount)
   - Stripe Dashboard → Connect → Transfers (should show transfer to vendor's connected account)
   - WooCommerce order → order notes (should include "LT Stripe Connect: Transferred $X to vendor (Stripe tr_xxx)")

### Phase 2 — Push plugin to GitHub

Plugin folder at `E:/Documents/Loothtool/loothtool-stripe-connect/` is NOT yet a git repo. Create one, init, push to new repo `vanlaarhovenguitars/loothtool-stripe-connect`. Add to the GITHUB REPOS list in CLAUDE.md.

### Phase 3 — Add second webhook for `account.application.deauthorized`

Currently skipped because "Connected and v2 accounts" scope doesn't include it. Create a separate webhook with "Your account" scope subscribed to just that one event so we know when a vendor revokes our app.

### Phase 4 — Live mode rollout

After Phase 1 verifies everything works in test:
1. The Looth Group owner enables live mode in his Stripe Dashboard (he already has live keys ready)
2. Re-create the webhook in live mode (the `whsec_...` is different per mode)
3. Update WP options to live keys + new live webhook secret
4. Uncheck the "Test Mode" toggle
5. Tell existing vendors to reconnect their accounts (test-mode Express accounts don't carry to live)
6. Run one small real-card transaction to verify

### Phase 5 — UX polish (lower priority)

- Add a banner/badge in the Dokan vendor dashboard nudging vendors who haven't connected yet
- Email vendor when their first transfer succeeds
- Email vendor + admin when a transfer fails or is reversed
- Admin retry button currently exists in settings but the retry handler at `class-admin-settings.php` isn't wired to actually re-run `process_order_transfer` (it's a stub — search for `handle_retry`)

---

## Architecture Notes

### Money flow

```
Customer pays $100 (WooCommerce Stripe Gateway)
   ↓
$100 lands in platform Stripe account
   ↓
loothtool-commissions runs (hooks `dokan_new_order_processing_charge` priority 20)
   - Calculates per-product splits (designer + printer + platform)
   - Deducts payment processing fees from vendor side
   - Writes _lt_comm_vendor_payout (e.g. $72.40) to order meta
   - Writes _lt_comm_cross_vendor_payouts for any secondary payees
   ↓
Order status → "completed"
   ↓
loothtool-stripe-connect runs (hooks `woocommerce_order_status_completed` priority 10)
   - Reads _lt_comm_vendor_payout
   - Looks up vendor's _lt_stripe_account_id user meta
   - Idempotency check: skip if _lt_stripe_transfer_id already set
   - Stripe\Transfer::create() with source_transaction = original charge ID
   - Records to wp_lt_stripe_transfers + order meta + order note
   - Loops _lt_comm_cross_vendor_payouts and transfers to each connected designer
   ↓
Stripe sends transfer.created webhook
   ↓
LT_SC_Webhook_Handler updates payout log status
   ↓
2-3 business days later, Stripe pays vendor's bank account
   ↓
payout.paid webhook (informational only)
```

### Key data structures

**User meta (per vendor):**
- `_lt_stripe_account_id` — `acct_xxx`
- `_lt_stripe_onboarding_complete` — '1'/'0'
- `_lt_stripe_charges_enabled` — '1'/'0'
- `_lt_stripe_payouts_enabled` — '1'/'0'

**Order meta (set by transfer processor):**
- `_lt_stripe_transfer_id` — `tr_xxx` (idempotency lock)
- `_lt_stripe_transfer_status` — 'created' / 'failed' / 'reversed'

**Order meta consumed (set by loothtool-commissions):**
- `_lt_comm_vendor_payout` — float, exact amount to transfer
- `_lt_comm_cross_vendor_payouts` — array of `{ vendor_id, amount, paid, paid_at, stripe_transfer_id }`
- `_dokan_vendor_id` — primary vendor

**Order meta consumed (set by WC Stripe Gateway):**
- `_stripe_charge_id` — `ch_xxx` (used as source_transaction for fund tracing)
- `_stripe_intent_id` — fallback if only intent is available

### Custom DB table `wp_lt_stripe_transfers`

| Column | Type | Notes |
|---|---|---|
| id | bigint AI | PK |
| order_id | bigint | indexed |
| vendor_id | bigint | indexed |
| stripe_transfer_id | varchar(64) | indexed |
| stripe_account_id | varchar(64) | |
| amount | decimal(10,2) | |
| currency | varchar(3) | default 'usd' |
| status | varchar(20) | indexed: pending / created / paid / failed / reversed |
| transfer_type | varchar(20) | 'primary' or 'cross_vendor' |
| source_charge_id | varchar(64) | |
| error_message | text | |
| created_at / updated_at | datetime | |

---

## Key Code References

| File | Lines / Function | What it does |
|---|---|---|
| `loothtool-stripe-connect/loothtool-stripe-connect.php` | full file | Bootstrap, loads SDK + classes, activation hook for table creation |
| `loothtool-stripe-connect/includes/class-stripe-api.php` | `init()`, `create_express_account()`, `create_transfer()`, `construct_webhook_event()`, `encrypt()` / `decrypt()` | SDK wrapper, all Stripe API calls, AES-256-CBC key storage |
| `loothtool-stripe-connect/includes/class-transfer-processor.php` | `process_order_transfer()` (line ~32), `transfer_to_primary_vendor()` (line ~57), `transfer_to_cross_vendors()` (line ~145) | Reads `_lt_comm_vendor_payout`, creates Stripe Transfers, writes to log + order meta |
| `loothtool-stripe-connect/includes/class-onboarding.php` | `ajax_create_account()`, `handle_onboarding_return()`, `ajax_login_link()` | Express account creation, Account Links flow, post-onboarding status sync |
| `loothtool-stripe-connect/includes/class-webhook-handler.php` | `handle()` (line ~36), `handle_account_updated()`, `handle_transfer_status()` | Verifies signatures, dispatches events to handlers |
| `loothtool-stripe-connect/includes/class-vendor-dashboard.php` | `render_payouts_page()`, `render_not_connected()`, `render_onboarding_incomplete()`, `render_connected()` | Dokan dashboard tab with 3 states |
| `loothtool-stripe-connect/includes/class-admin-settings.php` | `render()`, `register_settings()`, `encrypt_on_save()` | WP Admin settings page; encrypts keys on save unless value is the dot placeholder |
| `loothtool-commissions/includes/class-order-processor.php` | line 226-241 | Authoritative source for `_lt_comm_vendor_payout` calculation — vendor_payout = item_commission + shipping + tax − processing_fees |

---

## Server / Deploy Cheat Sheet

```bash
# SSH (use IP, not hostname — Cloudflare blocks port 22)
ssh -i "E:/Documents/Loothtool/buck_key" -o StrictHostKeyChecking=no buck@54.157.13.77

# Deploy plugin file
scp -i "E:/Documents/Loothtool/buck_key" -o StrictHostKeyChecking=no \
  "E:/Documents/Loothtool/loothtool-stripe-connect/includes/<file>.php" \
  buck@54.157.13.77:/var/www/dev.loothtool/wp-content/plugins/loothtool-stripe-connect/includes/

# Set permissions after a fresh upload (buck creates files as buck:buck, web server needs tool-dev group)
ssh ... "cd /var/www/dev.loothtool/wp-content/plugins && chgrp -R tool-dev loothtool-stripe-connect && chmod -R g+rwX loothtool-stripe-connect"

# WP-CLI on server
wp --path=/var/www/dev.loothtool plugin list
wp --path=/var/www/dev.loothtool eval '...'
```

---

## Open Questions

1. **Cross-vendor payouts in test mode.** When a test vendor receives a cross-vendor commission (designer cut), does Stripe still let us transfer to a test-mode connected account from a different test connected account's perspective? Need to verify during Phase 1 testing.

2. **Refund flow.** If a customer disputes a charge AFTER we've already transferred to the vendor, what happens? Plugin doesn't currently auto-reverse the transfer on `charge.refunded` or `charge.dispute.created`. May need a webhook handler addition once we see how this plays out.

3. **Multi-vendor cart split.** Loothtool's cart can contain products from multiple vendors. Each vendor would get their own sub-order, each with its own `_lt_comm_vendor_payout`. The transfer processor handles each order independently — but if all sub-orders share one underlying Stripe charge, the total of all transfers can't exceed the charge amount. Should be fine in practice (commissions plugin already deducts processing fees correctly), but watch for `Stripe\Exception\InsufficientFundsException` on multi-vendor orders.

4. **Should the plugin live in its own repo or be a subdirectory of an existing repo?** Currently a standalone folder, no git history. The other Loothtool plugins are each their own repo — recommend creating `vanlaarhovenguitars/loothtool-stripe-connect`.

5. **Live mode timing.** Owner wants to test thoroughly before going live. Phase 1 testing should also include: failed test (use card `4000 0000 0000 0002` to force decline), refund test, multi-vendor order test.

# AGENTS.md

This file provides guidance to AI coding agents (OpenCode, Claude Code,
Codex, Cursor, …) working on this repository.

## Project Overview

**CHIP for AffiliateWP** adds CHIP Send as a payout method for
[AffiliateWP](https://affiliatewp.com/) (premium plugin, not on WordPress.org).
When an operator pays a referral (single or batch), the plugin issues a CHIP Send
send instruction to the affiliate's verified bank account and tracks the transfer
to completion.

- **Pure PHP** WordPress plugin — no JS build step, no Composer runtime deps.
- Minimum PHP 7.1, minimum WordPress 4.7.
- Text domain: `chip-for-affiliatewp`.
- AffiliateWP requirement is documented in `readme.txt` (Installation), not via
  the wp.org-only `Requires Plugins` header.

## Architecture

### Plugin bootstrap
`chip-for-affiliatewp.php` defines constants (`CHIP_AFFILIATEWP_*`), registers
the text domain, and includes the modules from `includes/`. There are no
classes — the plugin follows CHIP house style of namespaced procedural
functions, all prefixed `chip_affiliatewp_`.

### Modules (`includes/`)

| File | Responsibility |
|---|---|
| `chip-affiliatewp-functions.php` | Shared helpers (array access, safe substring). |
| `class-chip-affiliatewp-api.php` | CHIP Send API client. Every request carries an `epoch` header and an HMAC-SHA512 `checksum` of `"{epoch}{api_key}"` signed with the API secret. Base URL switches between `https://api.chip-in.asia/api` (live) and `https://staging-api.chip-in.asia/api` (test mode). |
| `class-chip-affiliatewp-bank-accounts.php` | Affiliate bank details (Edit Affiliate screen fields + save hook) and CHIP Send bank-account sync (idempotent via per-affiliate reference). |
| `class-chip-affiliatewp-payouts.php` | Payout state machine: submit, instruction adoption on duplicate-reference rejection, state application (`completed`→paid, `rejected/deleted`→failed + referrals unpaid), scheduled requery, hourly sweep, batch fan-out (one Action Scheduler action per payout, staggered 5s). |
| `class-chip-affiliatewp-webhooks.php` | Per-site random webhook URL (`/wp-json/chip-affiliatewp/v1/webhook/{32-hex-secret}`), reachability-gated auto-registration (reuse/repoint/PATCH before POST), public-key capture, inbound signature verification, mode-aware reconciliation. |
| `class-chip-affiliatewp-admin.php` | Settings (Commissions tab via `affwp_settings_commissions`), webhook URL hint field, notices, hook registrations. |
| `chip-affiliatewp-lifecycle.php` | Activation schedules the hourly sweep; deactivation clears it. |

### Money-flow invariants (do not break these)

1. **Fail-closed**: a payout only becomes `paid` when CHIP Send reports the
   instruction `completed` (webhook or requery). Everything else stays
   `processing`. `rejected`/`deleted` → payout `failed` and referrals return to
   `unpaid`.
2. **Idempotency**: every payout maps to exactly one CHIP Send instruction via a
   deterministic reference (`{prefix}-PO-{payout_id}` or `{prefix}-R-{referral_id}`).
   A retry hitting a duplicate-reference rejection adopts the existing
   instruction instead of creating a second one. A payout requerying an
   instruction it already submitted must never resubmit.
3. **Signature before data**: inbound payloads are processed only after
   `openssl_verify` (RSA-SHA512 over the raw body, base64 `X-Signature`)
   succeeds. Missing key → 503; bad signature → 401; both fail closed.
4. **Mode isolation**: payouts remember the mode they were submitted in
   (`mode` in payout meta) and requery against that mode even if the site-wide
   mode flipped afterwards.

### Webhook URL secret

The REST route path carries a per-site secret suffix
(`/webhook/{32-hex}`) generated once per install via `random_bytes(16)` —
same pattern as chip-for-givewp's callback passphrase. Requests to the bare
`/webhook` path are answered 404 so scanners cannot discover the endpoint.
The real endpoint still verifies the CHIP RSA signature on every delivery.

## Development

### Test harness

```bash
php -f tests/test-harness.php
```

Standalone stub harness (no WordPress needed): 83 checks covering checksum
signing, amount formatting, webhook signature verification (valid, tampered,
missing), payout state transitions, idempotency/replay, failed-payout healing,
batch fan-out scheduling, and mode flips. Keep it green on every change; add a
check for any bug fixed.

The harness stubs the minimal WP/AffiliateWP surface — when adding a call to a
new WP function inside plugin modules, stub it in `tests/test-harness.php`.

### Distribution build

```bash
bash scripts/build-dist.sh    # -> dist/chip-for-affiliatewp.<version>.zip
```

Excludes development files via `.distignore` (tests, README.md, scripts).
WordPress.org submissions must contain only runtime files plus `readme.txt`.

### Version

Defined in three places — bump together:
- `chip-for-affiliatewp.php` — header `Version:` + `CHIP_AFFILIATEWP_VERSION`
- `readme.txt` — `Stable tag`
- New changelog entry in `readme.txt`

## WordPress.org compliance notes

- readme.txt follows the full WordPress readme format (headers, description,
  installation, FAQ, changelog, upgrade notice); `Tested up to` tracks the
  current WordPress major release.
- Author: CHIP IN SDN BHD (https://chip-in.asia); contributors list the
  wordpress.org username.
- i18n: all user-facing strings use `__('…', 'chip-for-affiliatewp')`;
  `load_plugin_textdomain` runs on `init`; `languages/` holds offline `.mo`
  files for non-hosted distributions.
- Internal infrastructure hostnames, credentials, and test data must never be
  committed. `staging-api.chip-in.asia` in the API client is a public product
  endpoint (test-mode base URL), not an internal leak.
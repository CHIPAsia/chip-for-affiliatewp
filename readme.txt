=== CHIP for AffiliateWP ===
Contributors: chipin
Tags: affiliatewp, affiliates, payouts, chip send, malaysia
Requires at least: 4.7
Tested up to: 6.8
Requires PHP: 7.1
Stable tag: 2.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Pay affiliate commissions with CHIP Send — money goes straight to each affiliate's Malaysian bank account.

== Description ==

CHIP for AffiliateWP adds CHIP Send as a payout method for [AffiliateWP](https://affiliatewp.com/). When you pay a referral — a single referral from the Referrals screen, or a full payout batch — the plugin sends the money through CHIP Send to the affiliate's verified bank account.

How settlement works:

* Payouts move through statuses just like the money moves: a payout is created as *Processing*, stays *Processing* while CHIP Send handles the transfer, and flips to *Paid* only when CHIP confirms the instruction completed. Rejected transfers fail safely and their referrals are released back to unpaid so you can retry.
* The plugin registers and verifies its own CHIP Send webhook automatically — delivery signatures are checked with RSA before anything is acted on. If a webhook delivery is ever missed, an hourly requery keeps payout status in step with CHIP.
* Every payout carries a unique reference, so a retry can never send the same money twice.

Requires a CHIP Send account with API credentials. Contact your CHIP account manager to get set up.

= Affiliate bank details =

Set each affiliate's **Bank Code** and **Bank Account Number** on the Edit Affiliate screen. Affiliates without bank details are skipped safely — nothing is sent and nothing is marked paid.

= Supported banks =

All Malaysian banks and e-wallets supported by CHIP Send FPX payouts, including Maybank, CIMB, Public Bank, RHB, Hong Leong, Bank Islam, Touch 'n Go eWallet, GX Bank, and more.

== Installation ==

1. Install [AffiliateWP](https://affiliatewp.com/) and activate it (2.33 or newer recommended).
2. Upload the plugin zip via **Plugins → Add New → Upload Plugin** and activate.
3. Go to **AffiliateWP → Settings → Commissions → CHIP Send Payment Method**:
   * Tick **CHIP Send** to enable the payout method.
   * Tick **CHIP Send Test Mode** and fill the Test API Key / Test Secret Key while evaluating; switch to the Live keys when going live.
4. Ensure each affiliate has bank details set before paying them.
5. Pay unpaid referrals as usual (single *Pay* action on the Referrals screen, or a payout batch). The webhook registers itself automatically once credentials are saved.

== Frequently Asked Questions ==

= How do payouts get confirmed? =

Both a CHIP Send webhook (verified with RSA signatures) and a scheduled requery watch every in-flight payout. Whichever sees the final state first records it; the other becomes a harmless no-op. Money is only marked paid when CHIP reports the transfer completed.

= Is it safe to retry a failed payout? =

Yes. Each payout has a single unique reference at CHIP Send. If an instruction already exists for it, the plugin adopts the existing instruction instead of creating a second one, so retries cannot double-pay.

= What happens if the site is unreachable when the webhook registers? =

Nothing is registered — a webhook that cannot reach the site would only gather delivery failures. Setup is retried later automatically, and payouts still settle through hourly requery in the meantime.

= Where do I find the API keys? =

In the CHIP portal under Control → Settings → Applications. The secret key is used only for signing requests on your server; it is never sent to CHIP.

== Changelog ==

= 2.0.0 =
* Complete CHIP Send payout integration: signed API client (epoch + HMAC-SHA512 checksum) with test/live environment switching.
* Payout method ("chip") available to affiliates; participates in AffiliateWP payout batches and single-referral payments.
* Automatic CHIP Send webhook registration with public-key capture; inbound deliveries signature-verified; duplicate and out-of-order deliveries ignored.
* Missed-webhook healing via scheduled status checks and an hourly requery sweep.
* Idempotent payouts: deterministic unique references, existing-instruction reuse, and safe failure handling that releases referrals back to unpaid.
* Affiliate bank details (bank code + account number) on the Edit Affiliate screen with Malaysian bank codes.
* Optional recipient receipts on payouts.

= 1.0.0 =
* Initial proof-of-concept release.

== Upgrade Notice ==

= 2.0.0 =
Full CHIP Send payout support with webhooks and automatic retries. Test mode credentials are configured separately from live credentials — check your settings after upgrading.
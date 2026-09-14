=== CHIP for AffiliateWP ===
Contributors: wanzulnet
Tags: affiliatewp, affiliates, payouts, chip send, malaysia
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.1
Stable tag: 1.1.0
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

1. Install [AffiliateWP](https://affiliatewp.com/) and activate it (**2.36 or newer required**). This plugin uses AffiliateWP's payment-method registry, single-referral payout handlers, and payout metadata API, all of which arrived in 2.36. On an older release the payout method will not appear in the Payouts tab.
2. Upload the plugin zip via **Plugins → Add New → Upload Plugin** and activate.
3. Go to **AffiliateWP → Settings → Payouts → CHIP Send**:
   * Tick **CHIP Send** to enable the payout method.
   * Tick **CHIP Send Test Mode** and fill in the Test API Key and Test Secret Key while evaluating; switch to the Live keys when going live.
4. Ensure each affiliate has bank details set before paying them.
5. Pay unpaid referrals as usual (a single *Pay* action on the Referrals screen, or a payout batch). The webhook registers itself automatically once credentials are saved.

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

= 1.1.0 =
* Fixed: a failed payout left its referrals unpaid but still attached to it, so the single-pay action refused them and the commission could never be paid again. Failed payouts now detach their referrals.
* Fixed: the affiliate "action required" email showed a raw `{affiliate_payout_settings_url}` placeholder instead of a link on sites without the Stripe integration. The plugin now provides the tag itself.
* Fixed: the Malay translation never reached the site — the build wrote only the .po file, so the compiled .mo WordPress reads was stale, and plural strings were not extracted at all.
* Fixed: a referral reassigned to another affiliate before submission was still paid from the original payout.
* Fixed: the webhook recovery path could create a payout without checking that the store currency is MYR.
* Fixed: a referral whose references CHIP had all refused retried forever; it is now refused with a clear reason.
* Fixed: uninstall left the burnt-reference meta behind, which suppressed a fresh reference on a later reinstall.
* Fixed: the CHIP note on an instruction was stored unbounded and reached the payout list and notification email; it is now capped.
* Fixed: several smaller issues — an unescaped API error rendered on the settings screen, a superseded bank account left registered at CHIP, an account summary cache written and dropped under different key spellings, and an instruction CHIP no longer holds polling forever.
* Also: the coding-standards check in CI had failed since 1.0.0 (a Composer plugin was blocked). It now installs from composer.json and runs the same command as a local checkout.

== Upgrade Notice ==

= 1.1.0 =
Fixes a payout that could become impossible to pay again after a failure, a broken link in the affiliate failure email, and a Malay translation that never reached the site. Recommended for all installs.

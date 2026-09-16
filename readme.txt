=== CHIP for AffiliateWP ===
Contributors: wanzulnet
Tags: affiliatewp, affiliates, payouts, chip send, malaysia
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.1
Stable tag: 1.2.0
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

= 1.2.0 =
* Security: the settings screen no longer sends your stored CHIP API key and secret to the browser. They were rendered into the page as field values, so anything able to read the response could take them. The fields are blank now, with a note when a value is saved; leaving them blank keeps the current credentials.
* Security: the "webhook is not reachable" notice no longer includes the webhook URL, which carries this site's private webhook secret.
* Fixed: resetting the webhook could delete another site's. Ownership was partly decided by the webhook's name, and that name is identical on every install — so a merchant running two sites on one CHIP account had site A's reset remove site B's webhook, and B lost its deliveries with nothing to say why.
* Fixed: a delivery from the other environment could settle a payout. Instruction ids are unique per CHIP account rather than globally, so the same number exists in test and in live.
* Fixed: an instruction belonging to a different site on the same CHIP account could settle one of your payouts. Your reference prefix is now required before a reference is trusted.
* Fixed: a payout could become unresolvable if you changed the reference prefix, or moved the site, after submitting it — it now also recognises the reference it was actually sent under.
* Fixed: an adopted instruction (one whose submission reply was lost) did not record which environment it came from, so later checks could poll the wrong one.
* Fixed: cleaning up a replaced bank account could be blocked forever by a payout in the other environment that happened to hold the same account number, leaving the old account registered at CHIP.
* Fixed: bank accounts are now resolved in the payout's own environment instead of the site-wide setting, and a payout sent from one environment no longer reports the other's credentials.
* Fixed: deactivating the plugin left scheduled payout checks behind.
== Upgrade Notice ==

= 1.2.0 =
Fixes two ways stored credentials could be exposed, a webhook reset that could delete another site's webhook, and several cases where a payout in one environment could be settled — or stranded — by the other. Recommended for all installs.

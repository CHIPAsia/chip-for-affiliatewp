<img src="./assets/logo.svg" alt="drawing" width="50"/>

# CHIP for AffiliateWP

Pay your affiliates' commissions as [CHIP Send](https://docs.chip-in.asia/chip-send/api-reference/introduction) payouts — money goes straight from your CHIP Send balance to each affiliate's Malaysian bank account.

## How it works

1. Admin marks unpaid referrals for payment (single referral via the Referrals screen, or a batch via **AffiliateWP → Payouts**).
2. The plugin registers the affiliate's bank account with CHIP Send (idempotent — one CHIP bank account per affiliate bank details) and creates a send instruction.
3. The payout starts in **Processing**; referrals stay **Unpaid** until CHIP confirms.
4. CHIP delivers a webhook; the payout flip to **Paid** and its referrals to **Paid**. If a delivery is ever missed, an hourly sweep requeries CHIP for any payout still in processing — the two paths converge on the same state machine, so money is marked paid only when CHIP says `completed`.

Duplicate protection: every send instruction carries a deterministic, unique reference derived from the payout ID, CHIP rejects duplicate submissions, and payouts already carrying an instruction are never re-sent.

## Compatibility

- [AffiliateWP](https://affiliatewp.com/) 2.33+ (uses the payout-method and batch processor APIs)
- PHP 7.1+

## Installation

* [Download zip](https://github.com/CHIPAsia/chip-for-affiliatewp/archive/main.zip).
* Log in to your WordPress admin panel and go: **Plugins** → **Add New**
* Select **Upload Plugin**, choose the zip file you downloaded in step 1 and press **Install Now**
* Activate plugin (AffiliateWP must be active)

## Configuration

Go to **AffiliateWP → Settings → Commissions → CHIP Send Payment Method**:

| Setting | Description |
|---------|-------------|
| **CHIP Send** | Enable the payout method |
| **CHIP Send Test Mode** | Use the staging environment |
| **Live/Test API Key** | From CHIP Control → Settings → Applications |
| **Live/Test Secret Key** | Used only for signing requests; never transmitted |
| **Reference Prefix** | Two characters prefixed to CHIP references |
| **Send Recipient Receipt** | Email a receipt to the affiliate on each payout |
| **Webhook Public Key** | PEM public key of your CHIP Send webhook |

### Affiliate bank details

On **Affiliates → Edit Affiliate**, set the affiliate's **Bank Code** and **Bank Account Number**. Payouts to affiliates without bank details are failed safely and their referrals released back to unpaid.

### Webhook setup (automatic)

The plugin registers its own CHIP Send webhook the first time settings are saved with credentials configured:

1. It checks whether the site's webhook URL is **publicly reachable** — if not, no webhook is created (a registered-but-unreachable webhook would only collect delivery failures); setup retries later from the admin dashboard.
2. It reuses an existing webhook pointing at the site's webhook URL, or creates one (`AffiliateWP Payouts`, event hooks: `send_instruction_status`, `bank_account_status`).
3. It stores the webhook's `public_key` and uses it to verify every inbound delivery's `X-Signature` header. Manual key entry via the **Webhook Public Key** setting still works and takes precedence.

Missing a delivery is not fatal — payouts left in processing are requeried hourly from the CHIP Send API, and both paths converge on the same state machine.

## Testing the integration

Use your CHIP Send test credentials, enable Test Mode, and run a payout to a staging bank account. Instruction states progress `received → enquiring → executing → completed` (or `rejected`); payouts and referrals follow the same transitions.

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
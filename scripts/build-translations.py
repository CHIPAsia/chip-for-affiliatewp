#!/usr/bin/env python3
"""Regenerate languages/chip-for-affiliatewp.pot and languages/chip-for-affiliatewp-ms_MY.po."""
import re, os

BASE = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')) + '/'
FILES = [
    'chip-for-affiliatewp.php', 'uninstall.php',
    'includes/chip-affiliatewp-functions.php',
    'includes/class-chip-affiliatewp-api.php',
    'includes/class-chip-affiliatewp-bank-accounts.php',
    'includes/class-chip-affiliatewp-payouts.php',
    'includes/class-chip-affiliatewp-webhooks.php',
    'includes/class-chip-affiliatewp-admin.php',
    'includes/chip-affiliatewp-lifecycle.php',
]

# (msgid -> ['file:line', ...]) — single-quoted strings only, matching our style.
occurrences = {}

TRANSLATIONS = {
    '': {
        'Po-Revision-Date': '2026-09-01 08:30+0800',
        'Last-Translator': 'Wan Zulkarnain <wanzulkarnain69@gmail.com>',
        'Language-Team': 'Bahasa Melayu',
        'Language': 'ms_MY',
        'MIME-Version': '1.0',
        'Content-Type': 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding': '8bit',
        'Plural-Forms': 'nplurals=1; plural=0;',
        'X-Generator': 'build-translations.py',
    },
    # --- admin settings ---
    'CHIP Send': 'CHIP Send',
    'CHIP Send Payment Method': 'Kaedah Pembayaran CHIP Send',
    'Enable the CHIP Send payout method.': 'Aktifkan kaedah pembayaran CHIP Send.',
    'CHIP Send Test Mode': 'Mod Ujian CHIP Send',
    'Use the CHIP Send staging environment.': 'Guna persekitaran staging CHIP Send.',
    'Test API Key': 'Kunci API Ujian',
    'Test Secret Key': 'Kunci Rahsia Ujian',
    'Live API Key': 'Kunci API Live',
    'Live Secret Key': 'Kunci Rahsia Live',
    'Webhook Public Key': 'Kunci Awam Webhook',
    # --- bank / affiliate labels ---
    'Bank Code': 'Kod Bank',
    'Bank Account Number': 'Nombor Akaun Bank',
    'Send Receipt to Affiliate': 'Hantar Resit ke Afiliasi',
    '— Select bank —': '— Pilih bank —',
    'Touch \'n Go eWallet': 'Touch \'n Go eWallet',
    # --- payout / referral strings ---
    'Commission for referral #%d': 'Komisen untuk rujukan #%d',
    'Affiliate commission payout #%s': 'Pembayaran komisen afiliasi #%s',
    'A payment cannot be processed for this referral since it is not marked as Unpaid.': 'Pembayaran tidak dapat diproses untuk rujukan ini kerana ia tidak ditanda sebagai Belum Dibayar.',
    'Bank account is not verified yet (status: %s).': 'Akaun bank belum disahkan (status: %s).',
    'This affiliate has no bank account details on file.': 'Afiliasi ini tiada butiran akaun bank disimpan.',
    # --- credentials / API errors ---
    'CHIP Send API credentials are not configured.': 'Kredensial API CHIP Send tidak dikonfigurasi.',
    'CHIP Send API credentials are not set yet — payouts are disabled until they are configured.': 'Kredensial API CHIP Send belum ditetapkan — pembayaran dilumpuhkan sehingga ia dikonfigurasi.',
    'CHIP Send API error (HTTP %1$s): %2$s': 'Ralat API CHIP Send (HTTP %1$s): %2$s',
    'CHIP Send API returned an unexpected response.': 'API CHIP Send memulangkan respons yang tidak dijangka.',
    'Please enter your CHIP Send API credentials in AffiliateWP → Settings → Commissions → CHIP Send before attempting to process payments.': 'Sila masukkan kredensial API CHIP Send anda di AffiliateWP → Tetapan → Komisen → CHIP Send sebelum memproses pembayaran.',
    # --- webhook ---
    'Register this URL as a CHIP Send webhook (event hooks: send_instruction_status). Paste the webhook public key from the CHIP portal into the Webhook Public Key field above.': 'Daftarkan URL ini sebagai webhook CHIP Send (event hooks: send_instruction_status). tampal kunci awam webhook dari portal CHIP ke dalam medan Kunci Awam Webhook di atas.',
    'The webhook URL (%1$s) is not reachable: %2$s. The webhook was not registered — fix site reachability or configure payouts without webhooks (the hourly requery sweep still works).': 'URL webhook (%1$s) tidak dapat dicapai: %2$s. Webhook tidak didaftarkan — baiki kebolehcapaian laman atau konfigurasi pembayaran tanpa webhook (sapuan requery setiap jam tetap berfungsi).',
    'Webhook signature verification is not configured yet.': 'Pengesahan tandatangan webhook belum dikonfigurasi.',
    'Missing signature.': 'Tandatangan tiada.',
    'The configured webhook public key is not valid.': 'Kunci awam webhook yang dikonfigurasi tidak sah.',
    'Signature verification failed.': 'Pengesahan tandatangan gagal.',
    'Malformed payload.': 'Muatan tidak sah.',
    'The payload is missing a send instruction ID or state.': 'Muatan tiada ID atau keadaan arahan penghantaran (send instruction).',
    'CHIP Send did not return a webhook ID.': 'CHIP Send tidak memulangkan ID webhook.',
    'CHIP Send API credentials are missing and the payout could not be healed.': 'Kredensial API CHIP Send tiada dan pembayaran tidak dapat dipulihkan.',
    'Account number for the affiliate payout sent via CHIP Send.': 'Nombor akaun untuk pembayaran afiliasi yang dihantar melalui CHIP Send.',
    'Bank code for the affiliate payout sent via CHIP Send.': 'Kod bank untuk pembayaran afiliasi yang dihantar melalui CHIP Send.',
    'CHIP Send did not return a bank account ID.': 'CHIP Send tidak memulangkan ID akaun bank.',
    'CHIP Send did not return a send instruction ID.': 'CHIP Send tidak memulangkan ID arahan penghantaran.',
    'CHIP Send instruction %1$s. %2$s': 'Arahan penghantaran CHIP Send %1$s. %2$s',
    'CHIP Send payout method is not enabled.': 'Kaedah pembayaran CHIP Send tidak diaktifkan.',
    'Email a CHIP receipt to the affiliate on every payout.': 'E-mel resit CHIP kepada afiliasi bagi setiap pembayaran.',
    'Missing a webhook delivery is not fatal: payouts left in processing are requeried hourly from the CHIP Send API.': 'Kehilangan penghantaran webhook tidak fatal: pembayaran yang masih diproses akan disemak semula setiap jam daripada API CHIP Send.',
    'No route was found matching the URL and request method.': 'Tiada laluan sepadan dengan URL dan kaedah permintaan tersebut.',
    'PEM public key of the CHIP Send webhook used to verify inbound deliveries. Register the webhook URL shown below in the CHIP portal.': 'Kunci awam PEM bagi webhook CHIP Send untuk mengesahkan penghantaran masuk. Daftarkan URL webhook yang dipaparkan di bawah di portal CHIP.',
    'Payout amount must be greater than zero.': 'Jumlah pembayaran mesti melebihi sifar.',
    'Reference Prefix': 'Awalan Rujukan',
    'Send Recipient Receipt': 'Hantar Resit kepada Penerima',
    'The CHIP Send live API key.': 'Kunci API live CHIP Send.',
    'The CHIP Send live secret key. Used only for signing; it is never sent to CHIP.': 'Kunci rahsia live CHIP Send. Digunakan hanya untuk tandatangan; ia tidak pernah dihantar ke CHIP.',
    'The CHIP Send test API key.': 'Kunci API ujian CHIP Send.',
    'The CHIP Send test secret key. Used only for signing; it is never sent to CHIP.': 'Kunci rahsia ujian CHIP Send. Digunakan hanya untuk tandatangan; ia tidak pernah dihantar ke CHIP.',
    'The payout record could not be created. The referral may already have an active payout.': 'Rekod pembayaran tidak dapat dicipta. Rujukan ini mungkin sudah mempunyai pembayaran aktif.',
    'The specified payout does not exist.': 'Pembayaran yang dinyatakan tidak wujud.',
    'The specified referral does not exist.': 'Rujukan yang dinyatakan tidak wujud.',
    'The webhook URL is not publicly reachable from this site.': 'URL webhook tidak dapat dicapai secara awam dari laman ini.',
    'There is no affiliate connected to this referral.': 'Tiada afiliasi dikaitkan dengan rujukan ini.',
    'This affiliate account does not have a payment email.': 'Akaun afiliasi ini tiada e-mel pembayaran.',
    'This affiliate has no payment email on file.': 'Afiliasi ini tiada e-mel pembayaran disimpan.',
    'This payout is not a CHIP Send payout.': 'Pembayaran ini bukan pembayaran CHIP Send.',
    'This referral is already attached to a payout. Resolve that payout to pay it again.': 'Rujukan ini telah dikaitkan dengan pembayaran. Selesaikan pembayaran tersebut untuk membayarnya semula.',
    'Two characters used to prefix CHIP Send references.': 'Dua aksara yang digunakan sebagai awalan rujukan CHIP Send.',
    'Webhook URL': 'URL Webhook',
    'request failed': 'permintaan gagal',
    # --- notices ---
    'CHIP Send webhook is not set up yet (%1$s). Payouts still work — statuses are requeried hourly — but confirmations arrive faster with the webhook.': 'Webhook CHIP Send belum disediakan (%1$s). Pembayaran tetap berfungsi — status disemak setiap jam — tetapi pengesahan lebih pantas dengan webhook.',
}


def extract():
    for rel in FILES:
        path = BASE + rel
        src = open(path).read()
        lines = src.split('\n')
        for i, line in enumerate(lines, 1):
            # skip purely comment lines but still capture __() where present
            for m in re.finditer(r"__\(\s*'((?:[^'\\]|\\.)*)'", line):
                msgid = m.group(1)
                occurrences.setdefault(msgid, []).append(f'{rel}:{i}')
            for m in re.finditer(r"(?:esc_html_e|esc_html__|esc_html|_e)\(\s*'((?:[^'\\]|\\.)*)'", line):
                msgid = m.group(1)
                if msgid not in occurrences or 'esc' in line:
                    occurrences.setdefault(msgid, [])
                    if f'{rel}:{i}' not in occurrences[msgid]:
                        occurrences[msgid].append(f'{rel}:{i}')


def occurrence_tuples():
    """Yield (file, line) tuples from the occurrences dict."""
    for msgid, refs in occurrences.items():
        for ref in refs:
            fpath, lineno = ref.rsplit(':', 1)
            yield msgid, fpath, int(lineno)


def main():
    extract()

    # .po / .pot
    import polib
    pot = polib.POFile()
    pot.metadata = {
        'Project-Id-Version': 'CHIP for AffiliateWP 1.0.0',
        'Report-Msgid-Bugs-To': 'https://github.com/CHIPAsia/chip-for-affiliatewp/issues',
        'POT-Creation-Date': '2026-09-01 08:30+0800',
        'MIME-Version': '1.0',
        'Content-Type': 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding': '8bit',
        'X-Domain': 'chip-for-affiliatewp',
    }
    po = polib.POFile()
    po.metadata = dict(pot.metadata)
    po.metadata.update({
        'PO-Revision-Date': '2026-09-01 08:30+0800',
        'Last-Translator': 'Wan Zulkarnain <wanzulkarnain69@gmail.com>',
        'Language-Team': 'Bahasa Melayu',
        'Language': 'ms_MY',
        'Plural-Forms': 'nplurals=1; plural=0;',
    })

    # polib expects occurrences as (path, line) tuples
    occ_tuples = {}
    for msgid, fpath, lineno in occurrence_tuples():
        occ_tuples.setdefault(msgid, []).append((fpath, lineno))

    untranslated = []
    for msgid in sorted(occurrences):
        refs = occurrences[msgid]
        entry = polib.POEntry(msgid=msgid, occurrences=occ_tuples[msgid])
        pot.append(polib.POEntry(msgid=msgid, occurrences=occ_tuples[msgid]))

        if msgid in TRANSLATIONS:
            e = polib.POEntry(
                msgid=msgid,
                msgstr=TRANSLATIONS[msgid],
                occurrences=occ_tuples[msgid],
            )
            po.append(e)
        else:
            untranslated.append(msgid)

    pot.save(BASE + 'languages/chip-for-affiliatewp.pot')
    po.save(BASE + 'languages/chip-for-affiliatewp-ms_MY.po')

    print(f'extracted: {len(occurrences)} unique msgids')
    print(f'ms_MY translated: {len(po)} entries')
    print(f'untranslated: {len(untranslated)}')
    for m in untranslated:
        print('  MISS:', m[:90])


if __name__ == '__main__':
    main()
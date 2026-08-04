=== Smart MyFatoorah Gateway for WooCommerce ===
Contributors: aymentucker
Tags: woocommerce, myfatoorah, payment-gateway, qpay, knet, mada, apple-pay, google-pay, qatar, gcc
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart MyFatoorah payment routing for WooCommerce: regional local methods by country, Visa/Mastercard, optional wallets, signed webhooks, and reconciliation. Built on MyFatoorah APIs — https://www.myfatoorah.com/

== Description ==

Smart MyFatoorah Gateway is a production-oriented WooCommerce payment gateway by [Aymen Ali](https://github.com/aymentucker) on top of the [MyFatoorah](https://www.myfatoorah.com/) payment platform.

It recommends the best available **local method** for the customer’s country when that method is enabled on the merchant account, without collecting raw card data in WordPress.

MyFatoorah enables online payments across 8 MENA markets (Kuwait, Saudi Arabia, UAE, Qatar, Bahrain, Oman, Egypt & Jordan). Learn more: [myfatoorah.com](https://www.myfatoorah.com/) · Developer docs: [docs.myfatoorah.com](https://docs.myfatoorah.com/docs/get-started)

= Supported methods =

* **QPay** (Qatar)
* **KNET** (Kuwait)
* **Benefit** (Bahrain)
* **Mada** / **STC Pay** (Saudi Arabia)
* **Meeza** (Egypt)
* **Visa / Mastercard** (international & general card checkout)
* **Apple Pay** / **Google Pay** (optional, when enabled on the MyFatoorah account)

Logos for checkout live under `assets/images/` in the plugin package. On GitHub, see the project [README.md](https://github.com/aymentucker/smart-myfatoorah-gateway/blob/master/README.md) for a visual method table.

= Highlights =

* Country-aware routing for local methods when MyFatoorah enables them.
* Classic Checkout and WooCommerce Checkout Blocks.
* High-Performance Order Storage (HPOS) compatibility declared.
* Signed MyFatoorah Webhook V2 on `/?wc-api=myfatoorah_webhook` (compatible with the official plugin URL).
* Server-side payment verification on browser callback before marking an order paid.
* Automatic reconciliation of pending payments (every 15 minutes, up to 24 hours).
* Operational log under WooCommerce → MyFatoorah Transactions.
* Checkout appearance controls: methods per row, logo layouts, text layouts, optional brand colors.
* Tabbed gateway settings: Settings, About, How to use.
* Arabic translation included.

= Security posture =

* No full card number, CVV, or OTP is collected or stored by this plugin.
* Payment redirect URLs are restricted to trusted MyFatoorah hosts over HTTPS.
* Webhook signatures use HMAC-SHA256 + Base64 with `hash_equals()`.
* Amount, currency, and order reference checks run before `payment_complete()`.
* Live mode requires HTTPS on the site.

= Useful links =

* MyFatoorah: https://www.myfatoorah.com/
* API & developer docs: https://docs.myfatoorah.com/docs/get-started
* API reference: https://docs.myfatoorah.com/reference
* API-based services: https://www.myfatoorah.com/en/api-based-services/
* Author (GitHub): https://github.com/aymentucker

== Payment API design ==

* **Local methods (QPay, KNET, Benefit, Mada, STC Pay, Meeza):** MyFatoorah V2 `InitiatePayment` → discover `PaymentMethodId` → `ExecutePayment`.
* **Card / Apple Pay / Google Pay (hosted):** MyFatoorah V3 `POST /v3/payments` with `CARD`, `APPLE_PAY`, or `GOOGLE_PAY`.
* **Embedded card (optional):** V2 `InitiateSession` + CardView, with hosted V3 fallback.
* **Status confirmation:** prefers V3 payment inquiry; V2 `GetPaymentStatus` remains available for V2 invoices.
* **Apple Pay domain:** admin action calls `POST /v2/RegisterApplePayDomain` (association file still required from MyFatoorah support).

Deactivate the official MyFatoorah WooCommerce plugin when using Smart, so only one handler owns `/?wc-api=myfatoorah_webhook`.

== Installation ==

1. Back up WordPress and WooCommerce.
2. Upload and activate the plugin.
3. Open **WooCommerce → Settings → Payments → Smart MyFatoorah**.
4. Keep **Test mode** on; set **Merchant country** to your MyFatoorah account country.
5. Paste the API token (see MyFatoorah Integration Settings → API Key) and save.
6. Click **Test MyFatoorah connection** and review discovered methods.
7. In MyFatoorah Portal → Integration Settings → Webhook Settings (V2):
   * Endpoint: `https://YOUR-DOMAIN.com/?wc-api=myfatoorah_webhook`
   * Events: `PAYMENT_STATUS_CHANGED` (and `REFUND_STATUS_CHANGED` if refunds are used)
   * Copy the Secure Key into **Webhook Secret Key** in the plugin.
8. Optionally register the Apple Pay domain after placing the Apple association file under `/.well-known/`.
9. Complete sandbox tests before switching to Live.

Full setup guide: `MYFATOORAH-SETUP.md` in the plugin folder.  
GitHub overview with logos: `README.md`.

== Frequently Asked Questions ==

= Why don’t KNET / Mada / Benefit show on my checkout? =

They appear only if MyFatoorah returns them for your merchant account **and** the customer billing country matches. A Qatar-only account typically shows QPay + card, not KNET or Mada.

= Order stays Pending after a successful charge? =

Confirm Webhook V2 delivery to `/?wc-api=myfatoorah_webhook`, the webhook secret matches, and the official MyFatoorah plugin is deactivated. Check WooCommerce → MyFatoorah Transactions and order notes.

= Does Apple Pay work like the official plugin? =

Domain registration uses the same MyFatoorah API. Checkout uses **hosted** Apple Pay (V3 redirect), not the official embedded `applepay.js` session on the page.

= Where do I get an API token? =

From the MyFatoorah portal: Integration Settings → API Key. Official guide: https://docs.myfatoorah.com/docs/api-key

== Troubleshooting ==

* **Connection test shows no locals:** ask MyFatoorah to enable the regional method on the account, or confirm merchant country / token environment (Demo vs Live).
* **Qatar debit card fails on Visa/Mastercard:** use QPay when available; local debit and international card rails differ.
* **Apple Pay missing:** enable Apple Pay on the account, host the association file, register the domain (Demo and Live separately), and enable wallet overrides in settings.
* **401 on webhook:** wrong or empty Webhook Secret Key.

== Changelog ==

= 1.0.8 =
* Tabbed gateway settings (Settings / About / How to use).
* Documentation refresh for GCC locals, webhooks, and go-live.
* GitHub README with payment logos and MyFatoorah resource links.

= 1.0.7 =
* Automatic checkout description based on enabled methods.
* Methods per row (2/3/4) and logo/text layout designs.
* Arabic strings for local-method fallback and display settings.

= 1.0.6 =
* Dynamic GCC local methods (KNET, Benefit, Mada, STC Pay, Meeza) with country affinity.
* Shared V2 ExecutePayment path for local methods; logo assets and MF ImageUrl fallback.
* Security fixes: V3 ExternalIdentifier reference, Blocks `mfdata` session key, webhook idempotency.

= 1.0.5 =
* Primary webhook on `/?wc-api=myfatoorah_webhook`; conflict notice when official plugin is active.

= 1.0.0 =
* Initial release: smart routing, V3 hosted card/wallets, V2 QPay adapter, webhooks, reconciliation, Blocks, HPOS.

== Upgrade Notice ==

= 1.0.8 =
Recommended update: clearer admin UX and documentation aligned with regional methods and webhook setup.

== Screenshots ==

1. Gateway settings with connection test and webhook URL.
2. Checkout method picker with logos and smart recommendation.
3. MyFatoorah Transactions operational log.

== External services ==

This plugin connects to MyFatoorah APIs (Demo or Live regional endpoints) to create payments, inquire status, process refunds, and register Apple Pay domains.

* Website: https://www.myfatoorah.com/
* Documentation: https://docs.myfatoorah.com/docs/get-started
* Privacy / terms: see your MyFatoorah merchant agreement and https://www.myfatoorah.com/

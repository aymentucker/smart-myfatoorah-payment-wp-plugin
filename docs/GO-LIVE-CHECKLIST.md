# Go-Live Checklist — Smart MyFatoorah Gateway

Use this list in staging first, then repeat the Live switch on production.  
Companion docs: `MYFATOORAH-SETUP.md`, `docs/ARCHITECTURE.md`.

---

## 1) Before installation

- [ ] Checkout, callback, and webhook URLs are reachable over **HTTPS**.
- [ ] WordPress, WooCommerce, and PHP meet plugin requirements (WP 6.5+, WC 8.0+, PHP 7.4+).
- [ ] Full backup of files + database.
- [ ] Staging environment mirrors Live (domain/SSL or tunnel if testing webhooks).
- [ ] MyFatoorah **Demo** API token ready for the correct merchant country.
- [ ] Official MyFatoorah WooCommerce plugin will be **deactivated** (same webhook URL).

---

## 2) Plugin configuration (Demo)

- [ ] Activate **Smart MyFatoorah Gateway** only (do not activate nested reference copies).
- [ ] WooCommerce → Settings → Payments → Smart MyFatoorah → **Settings** tab.
- [ ] Enable gateway; keep **Test mode** ON.
- [ ] Set **Merchant country** to the MyFatoorah account country.
- [ ] Paste API token → Save → **Test MyFatoorah connection**.
- [ ] Confirm discovered methods (Locals / Card / Apple / Google) match the portal.
- [ ] Enable **Regional local methods** if you want GCC locals when the account supports them.
- [ ] Enable **Local method fallback** (recommended).
- [ ] Webhook enabled; paste **Webhook Secret Key** from MyFatoorah portal.
- [ ] Portal Webhook V2 URL:
  ```text
  https://YOUR-DOMAIN.com/?wc-api=myfatoorah_webhook
  ```
- [ ] Portal events: `PAYMENT_STATUS_CHANGED` (+ `REFUND_STATUS_CHANGED` if refunds are used).
- [ ] Optional: place Apple association file at  
  `/.well-known/apple-developer-merchantid-domain-association`  
  then click **Register this domain with MyFatoorah** (Demo token).
- [ ] Optional: tune checkout description mode, methods per row, logo/text layouts.

---

## 3) Required test matrix (Demo)

### Routing & methods

- [ ] Country with a local method enabled (e.g. QA + QPay) → local pre-selected; caption correct.
- [ ] Same session: switch billing country away → local hides; card caption becomes Debit/Credit (no “International” wording).
- [ ] Manual override to card succeeds when local is available.
- [ ] Local method cancel / decline leaves order unpaid.
- [ ] Non-matching country → card only (plus wallets if enabled).
- [ ] If account has KNET/Benefit/Mada/STC/Meeza: verify each for its country only.
- [ ] Apple Pay / Google Pay appear only when connection test reports them and wallet overrides are on.
- [ ] Local unavailable → card fallback (when setting enabled).

### Checkout surfaces

- [ ] Classic Checkout (logos + text layouts you plan to use).
- [ ] Checkout Blocks (including embedded CardView if enabled; hosted fallback if session fails).
- [ ] Guest checkout.
- [ ] Logged-in customer + remembered preference.

### Confirmation paths

- [ ] Success with browser callback completing the order.
- [ ] Success with browser closed after bank approval → **webhook** completes the order.
- [ ] Callback before webhook / webhook before callback (no double capture).
- [ ] Duplicate webhook does not re-fulfill or corrupt status.
- [ ] Amount or currency mismatch never marks the order paid.
- [ ] Temporary API failure leaves order pending (not paid).
- [ ] Reconciliation later resolves a stuck successful payment (within 24h window).

### Refunds (if enabled)

- [ ] Request refund from WooCommerce order.
- [ ] `REFUND_STATUS_CHANGED` webhook creates/updates WooCommerce refund after MyFatoorah confirms.

---

## 4) Live switch

- [ ] Confirm Live portal: required locals + Visa/Mastercard (+ wallets if used).
- [ ] Replace Demo token with **Live** token; turn **Test mode** OFF.
- [ ] Re-save settings; run connection test against Live.
- [ ] Re-register Apple Pay domain with Live token if using Apple Pay.
- [ ] Confirm webhook URL/secret still correct on Live portal.
- [ ] Place small real payments:
  - [ ] One local method (if applicable to your market).
  - [ ] One Visa/Mastercard.
  - [ ] One wallet (if enabled).
- [ ] Cross-check: WooCommerce order status, MyFatoorah invoice, webhook delivery, callback note, **MyFatoorah Transactions** log.

---

## 5) After launch

- [ ] Debug log OFF unless diagnosing.
- [ ] Monitor WooCommerce → MyFatoorah Transactions for repeated error codes.
- [ ] Never ask customers for full card numbers, CVV, or OTP.
- [ ] Keep WordPress / WooCommerce / this plugin updated.
- [ ] If both Smart and the official plugin appear active, deactivate the official one immediately.

---

## Sign-off

| Role | Name | Date | Notes |
|------|------|------|-------|
| Merchant / ops | | | |
| Developer | | | |
| Staging pass | | | |
| Live pass | | | |

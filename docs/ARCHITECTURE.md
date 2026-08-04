# Smart MyFatoorah Gateway — Architecture

**Plugin:** Smart MyFatoorah Gateway for WooCommerce  
**Author:** [Aymen Ali](https://github.com/aymentucker)  
**Version:** 1.0.8+

## Purpose

Provide a WooCommerce payment experience on MyFatoorah that:

1. Recommends the best **available** regional method for the customer country.
2. Always offers **card** (Visa/Mastercard) when the account supports it.
3. Optionally exposes **Apple Pay / Google Pay** when enabled on the merchant account.
4. Confirms payment **server-side** (callback + signed webhook + reconciliation).
5. Never collects or stores full card numbers, CVV, or OTP in WordPress.

## Routing policy

`SMF_Router` resolves a route in this order:

1. Manual checkout selection (when customer override is enabled).
2. Saved preference for logged-in customers (when remember preference is enabled).
3. Country-aware local preference from `SMF_Method_Catalog` when that method is enabled on the account:
   - `QA` → QPay  
   - `KW` → KNET  
   - `BH` → Benefit  
   - `SA` → Mada, then STC Pay  
   - `EG` → Meeza  
4. Otherwise → `card`.

Country source: billing → shipping → WooCommerce geolocation.

Local methods are shown in the UI only for matching countries (kept in the DOM for AJAX country changes). `process_payment` re-validates availability and country before charging.

## Payment engines

| Route family | Engine | MyFatoorah calls |
|--------------|--------|------------------|
| Local (qpay, knet, benefit, mada, stc_pay, meeza) | V2 | `InitiatePayment` → `ExecutePayment` by `PaymentMethodId` |
| Embedded card | V2 | `InitiateSession` + CardView session → `ExecutePayment` |
| Hosted card / Apple Pay / Google Pay | V3 | `POST /v3/payments` (`CARD` / `APPLE_PAY` / `GOOGLE_PAY`) |
| Status inquiry | V3 preferred, V2 fallback | `GET /v3/payments/{id}` or `GetPaymentStatus` |
| Refunds | V2 | `MakeRefund` → WooCommerce refund after `REFUND_STATUS_CHANGED` |
| Apple Pay domain | V2 | `RegisterApplePayDomain` |

Discovery of enabled methods is cached briefly (`get_enabled_route_flags`). Local logos prefer `assets/images/*`; otherwise MyFatoorah `ImageUrl` is used.

## Runtime sequence

```text
Checkout
  → SMF_Gateway payment_fields / Blocks UI
  → place order
  → SMF_Router.resolve
  → SMF_API_Client create_*_payment
  → redirect to MyFatoorah PaymentURL (allowlisted *.myfatoorah.com)

Return / notify
  → Callback  /?wc-api=smart_myfatoorah_callback   (order_id + key)
  → Webhook   /?wc-api=myfatoorah_webhook          (HMAC signature)
  → SMF_Payment_State.apply (reference, amount, currency)
  → WC payment_complete() when paid

Safety net
  → SMF_Cron every 15 minutes (pending attempts ≤ 24h)
```

Webhook event idempotency uses `{prefix}smf_events` (`INSERT IGNORE` on event reference).

## Component map

| Class | Responsibility |
|-------|----------------|
| `SMF_Plugin` | Bootstrap, textdomain, gateway registration, conflict notice |
| `SMF_Gateway` | Settings UI (tabs), checkout fields, `process_payment` / refunds |
| `SMF_Method_Catalog` | Local method codes, countries, captions, logos |
| `SMF_API_Client` | HTTP client, discovery, V2/V3 payments, refunds, Apple domain |
| `SMF_Router` | Smart route resolution |
| `SMF_Callback_Controller` | Browser return handler |
| `SMF_Webhook_Controller` | WC-API webhook + signature verification |
| `SMF_Payment_State` | Verification + order state transitions |
| `SMF_Transactions` | Attempt / event tables |
| `SMF_Cron` | Reconciliation |
| `SMF_Blocks` / `SMF_Blocks_Payment_Method` | Checkout Blocks |
| `SMF_Admin` | Connection test, Apple register AJAX, transactions page |
| `SMF_I18n` | Locale-aware defaults |

## Storage

**Custom tables**

- `{prefix}smf_transactions` — payment attempts (route, engine, status, invoice/payment ids, errors).
- `{prefix}smf_events` — webhook/callback audit / idempotency keys.

**Order meta (non-exhaustive):** `_smf_invoice_id`, `_smf_payment_id`, `_smf_route`, `_smf_engine`, refund tracking keys.

No PAN/CVV/OTP storage.

## Checkout presentation

Controlled from gateway settings:

- Description mode: automatic (based on enabled locals) or custom.
- Display: logos or text.
- Methods per row: 2 / 3 / 4.
- Logo layouts: cards / compact / minimal.
- Text layouts: list / pills / grid.

## Security boundaries

- Live checkout requires HTTPS.
- Secrets live in WooCommerce gateway options (not in the repo).
- Webhook rejects missing/invalid signatures; soft rate-limit on failures.
- Public CardView session creation is nonce + IP rate-limited.
- Admin AJAX requires `manage_woocommerce` + nonce.
- Official plugin conflict: both plugins can bind `myfatoorah_webhook` — Smart shows an admin notice; only one should be active.

## Coexistence with official MyFatoorah plugin

Smart intentionally reuses `/?wc-api=myfatoorah_webhook` so existing portal configuration can stay. The nested `myfatoorah-woocommerce/` directory inside this package is **reference only** and must not be activated alongside Smart in production.

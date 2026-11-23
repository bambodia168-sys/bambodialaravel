# Baray payment integration (Bambodia)

Status: **integration branch only / not deployed / not enabled**.

## Source and scope

Provider documentation: https://baray.io/developers/ and https://baray.io/llm.txt (verify current version before going live).
Baray is an API integration service for licensed Cambodian banks, not a custodian of funds.

- One-time hosted checkout, **USD orders only** (existing Bambodia constraint). Do not convert USD to KHR silently.
- AES-256-CBC encrypted JSON request to `POST https://api.baray.io/pay`, with `x-api-key`.
- Baray responds with `_id`; redirect only to `https://pay.baray.io/{intent_id}`.
- The encrypted `order_id` is the **payment-attempt UUID**, not the buyer-visible order number.
- The Baray webhook returns `encrypted_order_id` when the bank confirms payment. Bambodia decrypts it and settles only the matching open attempt, using the existing order fulfillment, receipts and notifications. Never settle from a checkout return URL or a buyer-triggered "verify" button.
- Baray bank/QR selection is hosted by Baray. Do not build a duplicate bank-selection or QR interface in Bambodia.

## Merchant onboarding / config

1. In https://dash.baray.io verify Bambodia's email, connect at least one supported bank for the desired currency, and verify the checkout preview actually offers it.
2. Open **Developers** for the Bambodia organization. Obtain a **DEVELOPMENT** API key, Base64 AES secret (`sk`, decoded 32 bytes) and Base64 IV (`iv`, decoded 16 bytes).
3. In Bambodia's **Dashboard → Payment Methods**, configure the **Baray** method (seeded hidden) with Development credentials and select **Development**. The method remains hidden until deliberately enabled.
4. Configure Baray's webhook URL as `https://bambodia.com/api/commerce/webhooks/baray` for live, or the equivalent **public HTTPS QA endpoint** for development. A sandbox website must use its own keys and database, not Bambodia production orders.
5. Run simulated success/failure tests. Verify valid, invalid and duplicate webhooks, return URLs, buyer entitlements, email receipts, and transaction records. Verify non-Baray methods still work.
6. **Do not activate live** until the bank connections are active, the signed-webhook specification (if issued) has been obtained and implemented/verified, and production deployment has backups, a restore test, and a rollback procedure. Baray documentation mentions optional `X-Baray-Signature`, `X-Baray-Timestamp` and `X-Baray-Event-Id` but does not specify a canonical signature algorithm. Do not invent one. If signed events are enabled by Baray, the callback implementation must be extended to validate those headers before going live.

The ABA HMAC secret in the **Banks → Connect ABA** dialog is for the *Baray-to-ABA* connection. It is **not** the Baray `api_key`, `sk` or `iv` required by Bambodia. Never paste any secrets in a GitHub issue, chat transcript, repository, log, or screenshot.

## Payment operational guarantees

- Only one active payment per order; retry uses the same attempt `order_id`.
- Webhook decrypts with the configured environment's key and requires a matching Baray attempt with a recorded intent.
- A duplicate webhook does not generate a second order receipt/entitlement.
- A browser redirect does **not** prove payment.
- Webhook delivery retry is handled by Baray. Consult the Health dashboard for failed deliveries; do not grant manual entitlement on a missing callback.
- Baray has no documented generic payment-status polling API in the current developer quickstart. The Bambodia verify action therefore leaves a Baray attempt pending and links the buyer back to the hosted checkout. Operations should reconcile missing webhooks with Baray directly.
- Never store API credentials in frontend HTML or version control. Bambodia's gateway-setting model encrypts them at rest.
- Existing Stripe, PayPal, ABA PayWay, Bakong, manual bank transfer and QR payment flows are unchanged.

## Local regression tests

Run on isolated QA/staging, not on the live production database:

```sh
php artisan migrate --force
php artisan test --filter='baray_' 
php artisan test --filter=CommercePaymentsTest
php artisan route:list --path=api/commerce/webhooks/baray
```

Inspect all CI results and carry out manual hosted-checkout and webhook smoke tests before a production rollout. No release/install commands are provided here because this branch has not yet passed acceptance.

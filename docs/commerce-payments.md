# Bambodia payment processing

## Configure and test

1. Apply the payment-processing migration after the order-workspace migration. Payment Methods lists Stripe, PayPal, Bank Transfer, KHQR, ABA, Bakong and Wing. Review any existing preferences with these names. The migration hides those entries until they are configured for processing.
2. On an isolated Bambodia environment, set `COMMERCE_WORKSPACES_ENABLED=true` and `COMMERCE_PAYMENTS_ENABLED=true`. Leave `COMMERCE_LIVE_GATEWAYS=false` for sandbox testing.
3. Configure Stripe with `COMMERCE_STRIPE_SECRET` (test secret for the sandbox) and `COMMERCE_STRIPE_WEBHOOK_SECRET`. Register the endpoint shown in Payment Methods for `checkout.session.completed`, `checkout.session.async_payment_succeeded`, and `checkout.session.expired`.
4. Configure PayPal with `COMMERCE_PAYPAL_CLIENT_ID`, `COMMERCE_PAYPAL_SECRET`, `COMMERCE_PAYPAL_MERCHANT_ID`, and `COMMERCE_PAYPAL_WEBHOOK_ID` from the same app and environment. Register the PayPal endpoint shown in Payment Methods for `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, and `PAYMENT.CAPTURE.DENIED`.
5. Set the canonical HTTPS `APP_URL` before testing hosted checkout. Refresh cached configuration as part of the deployment and enable the prepared methods in the dashboard. Complete a sandbox checkout for each gateway and verify the order number, total, currency, receipt, webhook delivery, and repeat delivery.
6. For a manual method, add merchant transfer instructions and optionally upload a merchant QR image. The buyer submits a transfer reference and a PDF, PNG, JPEG, or WebP receipt from the order page. In **Marketplace Orders**, a super administrator who is neither party to the order downloads the receipt and independently checks the merchant's records before entering the confirmed reference, exact USD amount, and a review note. Rejected receipts leave the order awaiting payment so the buyer can submit a correction.

Manual approval records payment and grants access together. Buyers find their license and approved file on the order page. Buyers and sellers use the service workspace on their order pages to coordinate service delivery. Paid product orders created before release and license terms were recorded must be replaced before a buyer transfers funds. For already-submitted receipts on older orders, reconcile the transfer before rejecting and arranging a correction.

## Operational scope

Settlement accepts USD orders. Hosted checkout sends the buyer to the provider, and paid receipts are issued after provider-side verification or an independently reconciled manual transfer. An approved redirect is only a return to the order page. The order page also supports retrying verification when webhook delivery is delayed.

PayPal and Stripe need merchant credentials and working webhook registration before going live. Keep sandbox orders on an isolated database. Switch `COMMERCE_LIVE_GATEWAYS` only when deploying with the matching live provider configuration. Existing in-flight checkouts must be reconciled before rotating credentials or changing the gateway environment.

The merchant account receives funds. Creator payouts, refunds, taxes, payment disputes, and digital fulfillment for gateway payments are separate workflows. For an open checkout whose state is unclear, reconcile it with the provider before retrying with another method. Maintain private backup and retention for transfer receipt files under `storage/app/private/commerce/payment-proofs`.

## Refunds and statement matching

Apply `2026_11_18_120000_create_commerce_operations_ledger` and enable `COMMERCE_OPERATIONS_ENABLED` in the isolated environment. Orders with a verified payment show **Refund details** to the buyer and **Manage settlement** to a super administrator. Refund handling stays available for settled orders when new payment collection is paused.

- Buyers with verified email can request a refund. A super administrator who is neither the buyer nor the seller can decline the request or record a full refund after the bank or payment provider confirms it. Use the exact order amount and unique external refund reference. The order displays its original receipt and refund status. The purchase license and private download are revoked when the full refund is recorded.
- To match a statement, enter the original verified payment reference, the exact collected amount, a unique statement line reference, and the seller amount payable. A late statement match can still record the original payment after the order has been refunded. Reports show zero payable for a refunded order and retain the private ledger history.

Keep both ledger tables during an application rollback. Turning off `COMMERCE_OPERATIONS_ENABLED` disables finance actions while keeping existing statement reports and order history available.

## Gateway configuration and order QR

A super-admin opens **Payment Methods → Configure** to enter the credentials for the environment selected by `COMMERCE_LIVE_GATEWAYS`. The app stores replacements encrypted per provider and environment and uses existing Stripe and PayPal environment variables as fallback. Empty fields preserve saved values. Finish open checkouts before replacing merchant credentials. Bakong access tokens can be rotated for the same recipient while bills are open.

- **Stripe** requires the secret API key and webhook signing secret. **PayPal** requires the client ID, client secret, merchant ID, and webhook ID.
- **ABA** requires the PayWay merchant ID and API key. Arrange gateway allowlisting with ABA. Each order calls PayWay Generate QR with its own transaction reference and fixed USD total.
- **Bakong** requires the recipient's Bakong account ID, recipient name and city, and an NBC Open API access token. Its dynamic KHQR includes the exact USD total, a unique bill number and a six-minute expiry.
- **Bank Transfer, KHQR, Wing, and custom manual methods** accept transfer instructions and an optional reusable QR image. A super-admin verifies bank records before accepting submitted proof.

The buyer's open order checks QR payment status about every seven seconds. Run Laravel's scheduler each minute to reconcile recent bills when the buyer has closed the page. The order completes and a receipt is issued after a matching provider settlement. If the provider cannot establish the final result, an open attempt can continue to block a new payment. Check the provider dashboard before any manual resolution. Merchant sandbox testing must cover scanned QR compatibility and provider API verification before enabling production payments.

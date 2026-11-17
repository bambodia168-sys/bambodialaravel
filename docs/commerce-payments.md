# Bambodia payment processing

## Configure and test

1. Apply the payment-processing migration after the order-workspace migration. Payment Methods lists Stripe, PayPal, Bank Transfer, KHQR, ABA, Bakong and Wing. Review any existing preferences with these names. The migration hides those entries until they are configured for processing.
2. On an isolated Bambodia environment, set `COMMERCE_WORKSPACES_ENABLED=true` and `COMMERCE_PAYMENTS_ENABLED=true`. Leave `COMMERCE_LIVE_GATEWAYS=false` for sandbox testing.
3. Configure Stripe with `COMMERCE_STRIPE_SECRET` (test secret for the sandbox) and `COMMERCE_STRIPE_WEBHOOK_SECRET`. Register the endpoint shown in Payment Methods for `checkout.session.completed`, `checkout.session.async_payment_succeeded`, and `checkout.session.expired`.
4. Configure PayPal with `COMMERCE_PAYPAL_CLIENT_ID`, `COMMERCE_PAYPAL_SECRET`, `COMMERCE_PAYPAL_MERCHANT_ID`, and `COMMERCE_PAYPAL_WEBHOOK_ID` from the same app and environment. Register the PayPal endpoint shown in Payment Methods for `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, and `PAYMENT.CAPTURE.DENIED`.
5. Set the canonical HTTPS `APP_URL` before testing hosted checkout. Refresh cached configuration as part of the deployment and enable the prepared methods in the dashboard. Complete a sandbox checkout for each gateway and verify the order number, total, currency, receipt, webhook delivery, and repeat delivery.
6. For a manual method, add merchant transfer instructions and optionally upload a merchant QR image. The buyer submits a transfer reference and image proof. A super administrator who is neither party to the order verifies the transaction independently against the merchant's account before recording the reference, exact USD amount, and a review note. Use rejection to return an incorrectly submitted proof to the buyer.

## Operational scope

Settlement accepts USD orders. Hosted checkout sends the buyer to the provider, and paid receipts are issued after provider-side verification or an independently reconciled manual transfer. An approved redirect is only a return to the order page. The order page also supports retrying verification when webhook delivery is delayed.

PayPal and Stripe need merchant credentials and working webhook registration before going live. Keep sandbox orders on an isolated database. Switch `COMMERCE_LIVE_GATEWAYS` only when deploying with the matching live provider configuration. Existing in-flight checkouts must be reconciled before rotating credentials or changing the gateway environment.

The merchant account receives funds. Creator payouts, refunds, taxes, payment disputes, and automatic digital fulfillment are separate workflows. For an open checkout whose state is unclear, reconcile it with the provider before retrying with another method. Maintain private backup and retention for transfer proof files under `storage/app/private/commerce/payment-proofs`.

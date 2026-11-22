# Bambodia payment processing

## Configure and test

1. Apply the payment-processing migration after the order-workspace migration. Payment Methods lists Stripe, PayPal, Bank Transfer, KHQR, ABA, Bakong and Wing. Review any existing preferences with these names. The migration hides those entries until they are configured for processing.
2. On an isolated Bambodia environment, set `COMMERCE_WORKSPACES_ENABLED=true` and `COMMERCE_PAYMENTS_ENABLED=true`. Leave `COMMERCE_LIVE_GATEWAYS=false` for sandbox testing.
3. In **Payment Methods → Stripe → Configure**, select **Sandbox** and save the test API secret key and the sandbox endpoint signing secret. Register the endpoint shown in Payment Methods for `checkout.session.completed`, `checkout.session.async_payment_succeeded`, and `checkout.session.expired`.
4. Configure PayPal with `COMMERCE_PAYPAL_CLIENT_ID`, `COMMERCE_PAYPAL_SECRET`, `COMMERCE_PAYPAL_MERCHANT_ID`, and `COMMERCE_PAYPAL_WEBHOOK_ID` from the same app and environment. Register the PayPal endpoint shown in Payment Methods for `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, and `PAYMENT.CAPTURE.DENIED`.
5. Set the canonical HTTPS `APP_URL` before testing hosted checkout. Refresh cached configuration as part of the deployment and enable the prepared methods in the dashboard. Complete a sandbox checkout for each gateway and verify the order number, total, currency, receipt, webhook delivery, and repeat delivery.
6. For a manual method, add merchant transfer instructions and optionally upload a merchant QR image. The buyer submits a transfer reference and a PDF, PNG, JPEG, or WebP receipt from the order page. In **Marketplace Orders**, a super administrator who is neither party to the order downloads the receipt and independently checks the merchant's records before entering the confirmed reference, exact USD amount, and a review note. Rejected receipts leave the order awaiting payment so the buyer can submit a correction.

A super administrator can enable or pause new payments in **Payment Methods → Site-wide payment processing**. The site uses `COMMERCE_PAYMENTS_ENABLED` until an administrator saves a choice. Enabling requires an available payment method with the required configuration. Pausing blocks new paid checkout and hides transfer details and payment QR images from buyers. Existing receipt review, payment status checks, and provider reconciliation remain available. A buyer who already transferred outside the site should contact support with their order number while new receipt uploads are paused.

Manual approval records payment and grants access together. Buyers find their license and approved file on the order page. Buyers and sellers use the service workspace on their order pages to coordinate service delivery. Paid product orders created before release and license terms were recorded must be replaced before a buyer transfers funds. For already-submitted receipts on older orders, reconcile the transfer before rejecting and arranging a correction.

Verified Stripe, PayPal, ABA and Bakong payments also grant the ordered download and issue a license for a licensed product, or open the service workspace for a service order. Stripe checks the provider's Checkout Session on signed webhook delivery and on the buyer's return, then checks the order, amount, currency and payment status before granting access. Duplicate events retain the original license. If the approved file or terms are unavailable when payment clears, the receipt remains on record and the buyer can choose **Check delivery** from the order once the same approved release becomes available.

## Operational scope

Settlement accepts USD orders. Hosted checkout sends the buyer to the provider, and paid receipts are issued after provider-side verification or an independently reconciled manual transfer. An approved redirect is only a return to the order page. The order page also supports retrying verification when webhook delivery is delayed.

PayPal and Stripe need merchant credentials and working webhook registration before going live. Keep sandbox orders on an isolated database. Switch `COMMERCE_LIVE_GATEWAYS` only when deploying with the matching live provider configuration. Stripe and Bakong use their own dashboard selections once saved. Resolve open checkouts before switching environments.

The merchant account receives funds. Creator payouts, taxes, payment disputes, and recurring subscriptions are separate workflows. For an open checkout whose state is unclear, reconcile it with the provider before retrying with another method. Maintain private backup and retention for transfer receipt files under `storage/app/private/commerce/payment-proofs`.

## Delivery and refunds

- The buyer receives an account notification when delivery succeeds. Licensed archive orders group the download, **My licenses**, and installation instructions on the order page.
- The scheduler checks for paid orders awaiting delivery every 15 minutes. It sends an in-app alert to each active Super Admin once per order after 30 minutes of delay. The alert opens **Commerce Operations** filtered to the affected order.
- For a licensed product paid through Stripe, a Super Admin can record a single full refund that already succeeded with Stripe. Open the admin order, enter the refund ID, and record whether existing access is retained or revoked under the published refund policy. Verification uses the original payment's Stripe environment and matches the refund to the payment intent, amount, and currency. The refund record preserves the original verified payment and receipt. Partial refunds, split refunds, and refunds through other methods require separate handling.

## Gateway configuration and order QR

A super-admin opens **Payment Methods → Configure** to enter gateway credentials. Stripe and Bakong have their own environment selectors. Other gateways use the environment selected by `COMMERCE_LIVE_GATEWAYS`. The app stores replacements encrypted per provider and environment and uses existing Stripe and PayPal environment variables as fallback for the server-configured mode. Empty fields preserve saved values. Finish open checkouts before replacing merchant credentials. Bakong access tokens can be rotated for the same recipient while bills are open.

- **Stripe** requires the API secret key and the webhook endpoint signing secret from the selected environment. Select **Live Production** to prepare live credentials. Check **Use this environment for new Stripe checkouts** and save when ready to switch. The dashboard displays the active environment and the saved credential status for each environment. The production site offers Stripe checkout only with Live Production selected.
- **PayPal** requires the client ID, client secret, merchant ID, and webhook ID.
- **ABA** requires the PayWay merchant ID and API key. Arrange gateway allowlisting with ABA. Each order calls PayWay Generate QR with its own transaction reference and fixed USD total.
- **Bakong** stores the recipient's Bakong account ID, recipient name and city, and NBC Open API token separately under **Sandbox** and **Production**. Save credentials for either or both environments, then select **Use for new Bakong checkouts** to activate the desired environment. Run Sandbox checkout on an isolated installation. Public checkout uses Production credentials. Its dynamic KHQR includes the exact USD total, a unique bill number and a six-minute expiry.
- **Bank Transfer, KHQR, Wing, and custom manual methods** accept transfer instructions and an optional reusable QR image. A super-admin verifies bank records before accepting submitted proof.

The buyer's open order checks QR payment status about every seven seconds. Run Laravel's scheduler each minute to reconcile recent bills when the buyer has closed the page. The order completes and a receipt is issued after a matching provider settlement. If the provider cannot establish the final result, an open attempt can continue to block a new payment. Check the provider dashboard before any manual resolution. Merchant sandbox testing must cover scanned QR compatibility and provider API verification before enabling production payments.

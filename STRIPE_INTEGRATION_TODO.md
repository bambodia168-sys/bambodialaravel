# Bambodia Stripe Checkout setup

## Values to replace

The [existing gateway](app/Support/CommerceGateway.php) uses `mode=payment` and builds its line item from the saved Bambodia order total, currency, and listing title. There are no sample Price IDs to replace.

Set these values in the target environment using the names in [the environment template](.env.example). Keep secret values in the server environment or the existing encrypted Stripe settings.

| Field | What to set |
| --- | --- |
| `VITE_STRIPE_PUBLISHABLE_KEY` | The publishable key from the same Stripe account and mode as the secret key. This is the only key exposed to buyers. An empty value keeps the hosted checkout flow. |
| `COMMERCE_STRIPE_SECRET` | The corresponding server-only Stripe secret key, or set it in Payment Methods → Stripe → Configure. |
| `COMMERCE_STRIPE_WEBHOOK_SECRET` | Signing secret for the corresponding Stripe endpoint, or set it in Payment Methods → Stripe → Configure. |
| `APP_URL` | Canonical HTTPS URL of the test or live application. |

## Configured parameters

The [Checkout Session request](app/Support/CommerceGateway.php) applies these settings when a matching publishable key is set.

| Parameter | Value |
| --- | --- |
| Stripe API version | `2026-03-25.dahlia` |
| `ui_mode` | `form` |
| `mode` | `payment` |
| `billing_address_collection` | `required` |
| `phone_number_collection.enabled` | `false` |
| `automatic_tax.enabled` | `false` |
| `submit_type` | `auto` |
| `name_collection.individual` | Enabled and optional |
| `integration_identifier` | `custom_embedded_web_0001` |
| `client_reference_id` | Bambodia payment attempt UUID for verification |
| `return_url` | Existing Bambodia payment return route for this order and attempt |

Stripe limits `payment_method_collection` to subscriptions and defaults it to `always`. Bambodia uses one-time payments, so the request omits the field.

## Setup and testing

1. Use an isolated Bambodia environment and a [Stripe sandbox](https://dashboard.stripe.com/sandboxes). Configure matching test keys. Set `COMMERCE_WORKSPACES_ENABLED=true` and `COMMERCE_LIVE_GATEWAYS=false`. Refresh Laravel's configuration cache after editing the environment.
2. Register the Stripe webhook endpoint shown in Payment Methods for `checkout.session.completed`, `checkout.session.async_payment_succeeded`, and `checkout.session.expired`. Configure its signing secret. Make the configured Stripe method available and enable test payment collection in Payment Methods.
3. Place a test order using a real staged listing and click **Pay with Stripe**. The [order view](resources/views/commerce/payments.blade.php) loads Stripe.js directly from Stripe. The [browser script](public/js/commerce-stripe-checkout.js) posts to the [existing payment start route](app/Http/Controllers/CommercePaymentController.php) and mounts the form with its buyer-only client secret. There is no new application route or server dependency.
4. In the sandbox, test successful payment with `4242 4242 4242 4242`, decline with `4000 0000 0000 0002`, and [3D Secure authentication](https://docs.stripe.com/testing#regulatory-cards). Confirm that reloading the order reuses its open session. Verify the signed event, order amount, currency, settlement reference, and receipt in Bambodia. Replaying the same event must leave one receipt.
5. Verify the entitlement and license for a paid product and service workspace access for a paid service through the Stripe flow before launch. Keep live Stripe hidden until those fulfillment paths and the merchant checkout are verified. Resolve any open hosted Stripe attempts before switching modes, then configure matching live keys and webhook settings through the existing deployment process.

Resources: [Stripe Checkout](https://docs.stripe.com/payments/checkout), [versioned Checkout Session API](https://docs.stripe.com/api/checkout/sessions/create?api-version=2026-03-25.dahlia), [Stripe testing](https://docs.stripe.com/testing), [Stripe Support](https://support.stripe.com), [Stripe MCP](https://docs.stripe.com/mcp).

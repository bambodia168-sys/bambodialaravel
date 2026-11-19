<?php

namespace App\Support;

use App\Models\CommercePayment;
use App\Models\CommerceGatewaySetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class CommerceGateway
{
    public static function ready(string $provider, bool $live): bool
    {
        return CommerceGatewaySetting::configured($provider, $live, CommerceGatewaySetting::effective($provider, $live));
    }

    public function start(CommercePayment $payment): array
    {
        $order = $payment->order;
        $returnUrl = $this->url('account.orders.payment.return', [$order->number, $payment->number]);
        $cancelUrl = $this->url('account.orders.show', [$order->number]);
        if ($payment->provider === 'stripe') {
            $data = $this->send($this->stripe($payment->live_mode)->asForm()->withHeaders(['Idempotency-Key'=>$payment->number]), 'POST', '/checkout/sessions', [
                'mode'=>'payment', 'success_url'=>$returnUrl, 'cancel_url'=>$cancelUrl,
                'client_reference_id'=>$payment->number, 'metadata'=>['payment'=>$payment->number],
                'payment_method_types'=>['card'],
                'line_items'=>[['quantity'=>1, 'price_data'=>[
                    'currency'=>'usd', 'unit_amount'=>self::cents($order->total),
                    'product_data'=>['name'=>$order->listing_title],
                ]]],
            ]);
            $redirect = $data['url'] ?? null;
        } else {
            $data = $this->send($this->paypal($payment->live_mode)->withHeaders(['PayPal-Request-Id'=>$payment->number.'-c']), 'POST', '/v2/checkout/orders', [
                'intent'=>'CAPTURE',
                'purchase_units'=>[['custom_id'=>$payment->number, 'description'=>$order->listing_title,
                    'amount'=>['currency_code'=>'USD', 'value'=>$order->total]]],
                'payment_source'=>['paypal'=>['experience_context'=>[
                    'return_url'=>$returnUrl, 'cancel_url'=>$cancelUrl,
                    'shipping_preference'=>'NO_SHIPPING', 'user_action'=>'PAY_NOW',
                ]]],
            ]);
            $link = collect($data['links'] ?? [])->first(fn (array $link) => in_array($link['rel'] ?? '', ['payer-action', 'approve'], true));
            $redirect = $link['href'] ?? null;
        }

        abort_unless($this->trustedCheckout($payment->provider, $payment->live_mode, $redirect)
            && is_string($data['id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $data['id']), 502, 'The payment provider did not return a valid checkout.');

        return [$data['id'], $redirect];
    }

    public function refresh(CommercePayment $payment, bool $capture = false): array
    {
        abort_unless(self::ready($payment->provider, $payment->live_mode), 503, 'Payment verification is temporarily unavailable.');
        $order = $payment->order;
        if ($payment->provider === 'stripe') {
            $data = $this->send($this->stripe($payment->live_mode), 'GET', '/checkout/sessions/'.$payment->remote_id);
            abort_unless(($data['id'] ?? null) === $payment->remote_id
                && ($data['livemode'] ?? null) === $payment->live_mode
                && ($data['client_reference_id'] ?? null) === $payment->number
                && ($data['mode'] ?? null) === 'payment'
                && ($data['currency'] ?? null) === 'usd'
                && ($data['amount_total'] ?? null) === self::cents($order->total), 409, 'Stripe payment does not match the order.');
            if (($data['payment_status'] ?? null) === 'paid' && ($data['status'] ?? null) === 'complete') {
                return ['paid', $data['payment_intent'] ?? null];
            }

            $expired = ($data['status'] ?? null) === 'expired';

            return [$expired ? 'expired' : 'checkout_open', null, $expired ? null : ($data['url'] ?? null)];
        }

        $client = $this->paypal($payment->live_mode);
        $path = '/v2/checkout/orders/'.$payment->remote_id;
        $data = $this->send($client, 'GET', $path);
        $this->assertPaypalOrder($payment, $data);
        if ($capture && ($data['status'] ?? null) === 'APPROVED') {
            // GET first makes retries safe even after the provider's capture idempotency period.
            $response = $this->request($client->withHeaders(['PayPal-Request-Id'=>$payment->number.'-p']), 'POST', $path.'/capture');
            if (! $response->successful() && $response->status() !== 422) {
                abort(502, 'PayPal capture could not be verified. Retry verification.');
            }
            $data = $this->send($client, 'GET', $path);
            $this->assertPaypalOrder($payment, $data);
        }
        $unit = $data['purchase_units'][0];
        $captures = $unit['payments']['captures'] ?? [];
        $settlement = $captures[0] ?? null;
        if (($data['status'] ?? null) === 'COMPLETED' && count($captures) === 1 && ($settlement['status'] ?? null) === 'COMPLETED') {
            abort_unless(data_get($settlement, 'amount.currency_code') === 'USD'
                && data_get($settlement, 'amount.value') === $order->total
                && ($settlement['final_capture'] ?? false) === true, 409, 'PayPal capture does not match the order.');

            return ['paid', $settlement['id'] ?? null];
        }

        $link = collect($data['links'] ?? [])->first(fn (array $link) => in_array($link['rel'] ?? '', ['payer-action', 'approve'], true));

        $closed = ($data['status'] ?? null) === 'VOIDED'
            || (($data['status'] ?? null) === 'COMPLETED' && count($captures) === 1
                && in_array($settlement['status'] ?? null, ['DECLINED', 'FAILED'], true));

        return [$closed ? 'expired' : 'checkout_open', null, $closed ? null : ($link['href'] ?? null)];
    }

    public function webhook(Request $request, string $provider): ?array
    {
        $event = json_decode($request->getContent(), true);
        abort_unless(is_array($event), 400);
        if ($provider === 'stripe') {
            abort_unless(is_bool($event['livemode'] ?? null), 400);
        }
        $live = $provider === 'stripe' ? $event['livemode'] : CommerceGatewaySetting::checkoutIsLive($provider);
        abort_unless(self::ready($provider, $live), 404);
        if ($provider === 'stripe') {
            // The event's mode selects the signing secret. The raw payload must still verify.
            $header = (string) $request->header('Stripe-Signature');
            preg_match('/(?:^|,)t=(\d+)(?:,|$)/', $header, $timestamp);
            preg_match_all('/(?:^|,)v1=([a-f0-9]{64})(?=,|$)/', $header, $signatures);
            $time = (int) ($timestamp[1] ?? 0);
            abort_unless(abs(time() - $time) <= 300, 400);
            $expected = hash_hmac('sha256', $time.'.'.$request->getContent(), CommerceGatewaySetting::effective('stripe', $live)['webhook_secret']);
            abort_unless(collect($signatures[1])->contains(fn (string $value) => hash_equals($expected, $value)), 400);
            $remote = in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired'], true)
                ? data_get($event, 'data.object.id') : null;

            return $remote === null ? null : [$remote, $live];
        }

        $payload = ['webhook_id'=>CommerceGatewaySetting::effective('paypal', $live)['webhook_id'], 'webhook_event'=>$event];
        foreach (['auth_algo', 'cert_url', 'transmission_id', 'transmission_sig', 'transmission_time'] as $field) {
            $payload[$field] = $request->header('PayPal-'.str_replace('_', '-', $field));
            abort_unless(filled($payload[$field]), 400);
        }
        $verified = $this->send($this->paypal($live), 'POST', '/v1/notifications/verify-webhook-signature', $payload);
        abort_unless(($verified['verification_status'] ?? null) === 'SUCCESS', 400);

        $remote = match ($event['event_type'] ?? '') {
            'CHECKOUT.ORDER.APPROVED' => data_get($event, 'resource.id'),
            'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED' => data_get($event, 'resource.supplementary_data.related_ids.order_id'),
            default => null,
        };

        return $remote === null ? null : [$remote, $live];
    }

    public function trustedCheckout(string $provider, bool $live, ?string $url): bool
    {
        if (! $url || filter_var($url, FILTER_VALIDATE_URL) === false || preg_match('/[\\\\\x00-\x20]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        $host = $provider === 'stripe' ? 'checkout.stripe.com' : ($live ? 'www.paypal.com' : 'www.sandbox.paypal.com');

        return ($parts['scheme'] ?? null) === 'https' && strtolower($parts['host'] ?? '') === $host
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port']);
    }

    private function assertPaypalOrder(CommercePayment $payment, array $data): void
    {
        abort_unless(($data['id'] ?? null) === $payment->remote_id
            && ($data['intent'] ?? null) === 'CAPTURE'
            && count($data['purchase_units'] ?? []) === 1
            && data_get($data, 'purchase_units.0.custom_id') === $payment->number
            && data_get($data, 'purchase_units.0.payee.merchant_id') === CommerceGatewaySetting::effective('paypal', $payment->live_mode)['merchant_id']
            && data_get($data, 'purchase_units.0.amount.currency_code') === 'USD'
            && data_get($data, 'purchase_units.0.amount.value') === $payment->order->total, 409, 'PayPal order does not match the order or merchant.');
    }

    private static function cents(string $amount): int
    {
        return (int) str_replace('.', '', $amount);
    }

    private function url(string $name, array $parameters): string
    {
        $base = rtrim(config('app.url'), '/');
        abort_if(app()->environment('production') && ! str_starts_with($base, 'https://'), 503);

        return $base.route($name, $parameters, false);
    }

    private function stripe(bool $live): PendingRequest
    {
        return $this->http('https://api.stripe.com/v1')->withToken(CommerceGatewaySetting::effective('stripe', $live)['secret'])
            ->withHeaders(['Stripe-Version'=>'2024-06-20']);
    }

    private function paypal(bool $live): PendingRequest
    {
        $client = $this->http($live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com');
        $authClient = clone $client;
        $credentials = CommerceGatewaySetting::effective('paypal', $live);
        $auth = $this->send($authClient->asForm()->withBasicAuth($credentials['client_id'], $credentials['secret']),
            'POST', '/v1/oauth2/token', ['grant_type'=>'client_credentials']);
        abort_unless(is_string($auth['access_token'] ?? null), 502);

        return $client->asJson()->withToken($auth['access_token']);
    }

    private function http(string $base): PendingRequest
    {
        return Http::baseUrl($base)->acceptJson()->connectTimeout(5)->timeout(12)->withoutRedirecting();
    }

    private function request(PendingRequest $client, string $method, string $path, array $body = []): Response
    {
        try {
            return $method === 'GET' ? $client->get($path) : $client->post($path, $body ?: (object) []);
        } catch (ConnectionException) {
            abort(502, 'The payment provider is temporarily unreachable. Retry from your order.');
        }
    }

    private function send(PendingRequest $client, string $method, string $path, array $body = []): array
    {
        $response = $this->request($client, $method, $path, $body);
        abort_unless($response->successful() && is_array($response->json()), 502, 'The payment provider could not confirm the request.');

        return $response->json();
    }
}

<?php

namespace App\Support;

use App\Models\CommerceGatewaySetting;
use App\Models\CommercePayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class BarayGateway
{
    /**
     * Baray creates one payment intent per order_id. Our payment UUID is stable on retries.
     * The hosted payment page, not Bambodia, presents bank and KHQR choices.
     */
    public function start(CommercePayment $payment): array
    {
        abort_unless($payment->provider === 'baray', 422);
        $credentials = CommerceGatewaySetting::effective('baray', $payment->live_mode);
        abort_unless(CommerceGatewaySetting::configured('baray', $payment->live_mode, $credentials), 503);

        $base = rtrim((string) config('app.url'), '/');
        abort_unless(str_starts_with($base, 'https://') || ! app()->isProduction(), 503);

        $payload = [
            'amount' => (string) $payment->order->total,
            'currency' => $payment->order->currency,
            'order_id' => $payment->number,
            'tracking' => ['bambodia_order' => $payment->order->number],
            'custom_success_url' => $base.route('account.orders.payment.return',
                [$payment->order->number, $payment->number], false),
        ];
        abort_unless($payload['currency'] === 'USD' && preg_match('/^\d+\.\d{2}$/D', $payload['amount']), 422);

        $cipher = openssl_encrypt(
            json_encode($payload, JSON_THROW_ON_ERROR),
            'aes-256-cbc',
            base64_decode($credentials['sk'], true),
            OPENSSL_RAW_DATA,
            base64_decode($credentials['iv'], true),
        );
        abort_unless(is_string($cipher), 503, 'Baray encryption failed.');

        try {
            $response = Http::baseUrl('https://api.baray.io')
                ->acceptJson()->asJson()->connectTimeout(5)->timeout(12)->withoutRedirecting()
                ->withHeaders(['x-api-key' => $credentials['api_key']])
                ->post('/pay', ['data' => base64_encode($cipher)]);
        } catch (ConnectionException) {
            abort(502, 'Baray is temporarily unreachable. Retry from your order.');
        }
        $data = $response->json();
        abort_unless($response->successful() && is_array($data), 502,
            'Baray could not create the payment.');
        $id = $data['_id'] ?? null;
        abort_unless(is_string($id) && preg_match('/\Aitn-[A-Za-z0-9_-]{1,196}\z/D', $id),
            502, 'Baray did not return a valid payment intent.');
        // Check fields returned by Baray when present; never trust a mismatched checkout.
        foreach (['order_id' => $payment->number, 'amount' => $payload['amount'], 'currency' => 'USD'] as $field => $expected) {
            abort_if(array_key_exists($field, $data) && (string) $data[$field] !== $expected,
                409, 'Baray payment does not match the order.');
        }
        if (isset($data['target'])) {
            abort_unless($data['target'] === ($payment->live_mode ? 'PRODUCTION' : 'DEVELOPMENT'),
                409, 'Baray payment environment does not match.');
        }

        return [$id, $this->checkoutUrl($id)];
    }

    public function checkoutUrl(?string $intentId): string
    {
        abort_unless(is_string($intentId)
            && preg_match('/\Aitn-[A-Za-z0-9_-]{1,196}\z/D', $intentId),
            409, 'Invalid Baray checkout reference.');

        return 'https://pay.baray.io/'.$intentId;
    }

    /**
     * Baray's documented bank-confirmed callback carries encrypted_order_id,
     * not a payment status. The plaintext is the attempt UUID, never the order total.
     * Replays must be harmless: the caller settles only a checkout_open attempt.
     */
    public function confirmedPayment(Request $request): CommercePayment
    {
        $encoded = $request->input('encrypted_order_id');
        abort_unless(is_string($encoded) && strlen($encoded) <= 4096
            && preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $encoded), 400);
        $cipher = base64_decode($encoded, true);
        abort_unless(is_string($cipher) && strlen($cipher) > 0 && strlen($cipher) % 16 === 0, 400);

        foreach ([false, true] as $live) {
            if (! $live && app()->isProduction()) {
                continue;
            }
            $credentials = CommerceGatewaySetting::effective('baray', $live);
            if (! CommerceGatewaySetting::configured('baray', $live, $credentials)) {
                continue;
            }
            $number = openssl_decrypt($cipher, 'aes-256-cbc',
                base64_decode($credentials['sk'], true), OPENSSL_RAW_DATA,
                base64_decode($credentials['iv'], true));
            if (! is_string($number)
                || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', $number)) {
                continue;
            }
            $payment = CommercePayment::query()->where('provider', 'baray')
                ->where('live_mode', $live)->where('number', $number)
                ->whereNotNull('remote_id')->first();
            if ($payment) {
                return $payment;
            }
        }

        abort(400, 'Unrecognized Baray payment confirmation.');
    }
}

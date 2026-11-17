<?php

namespace App\Support;

use App\Models\CommerceGatewaySetting;
use App\Models\CommercePayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class CommerceQrGateway
{
    private const LIFETIME_MINUTES = 6;

    public function issue(CommercePayment $payment): array
    {
        $credentials = $this->credentials($payment);
        $expires = now()->addMinutes(self::LIFETIME_MINUTES);
        $bill = substr(str_replace('-', '', $payment->number), 0, 20);
        $amount = $payment->order->total;
        if ($payment->provider === 'bakong') {
            $payload = $this->bakongPayload($credentials, $bill, $amount, now()->getTimestampMs(), $expires->getTimestampMs());

            return ['remote_id' => md5($payload), 'qr_payload' => $payload, 'qr_expires_at' => $expires];
        }

        // PayWay signs the concatenation in field order, counting omitted fields as empty.
        $body = [
            'req_time' => now('UTC')->format('YmdHis'),
            'merchant_id' => $credentials['merchant_id'],
            'tran_id' => $bill,
            'amount' => $amount,
            'purchase_type' => 'purchase',
            'payment_option' => 'abapay_khqr',
            'currency' => 'USD',
            'lifetime' => self::LIFETIME_MINUTES,
            'qr_image_template' => 'template3_color',
        ];
        $result = $this->payway($payment, $credentials['secret'], 'generate-qr', $body);
        $payload = $result['qrString'] ?? null;
        abort_unless(in_array(data_get($result, 'status.code'), ['0', 0], true)
            && ($result['currency'] ?? null) === 'USD'
            && $this->amountMatches($result['amount'] ?? null, $amount)
            && is_string($payload) && strlen($payload) <= 1500
            && preg_match('/^000201010212[ -~]+6304[0-9A-F]{4}$/D', $payload)
            && substr($payload, -4) === $this->crc(substr($payload, 0, -4)), 502, 'PayWay did not return a valid order QR code.');
        $tags = $this->parseFields($payload);
        abort_unless(($tags['53'] ?? null) === '840' && $this->amountMatches($tags['54'] ?? null, $amount),
            502, 'The payment QR does not match the order amount.');

        return ['remote_id' => $bill, 'qr_payload' => $payload, 'qr_expires_at' => $expires];
    }

    public function refresh(CommercePayment $payment): array
    {
        $credentials = $this->credentials($payment);
        if ($payment->provider === 'aba') {
            $body = [
                'req_time' => now('UTC')->format('YmdHis'),
                'merchant_id' => $credentials['merchant_id'],
                'tran_id' => $payment->remote_id,
            ];
            $result = $this->payway($payment, $credentials['secret'], 'check-transaction-2', $body);
            if ((string) data_get($result, 'status.code') !== '00') {
                return ['checkout_open', null];
            }
            abort_unless(data_get($result, 'status.tran_id') === $payment->remote_id, 409, 'PayWay returned a different transaction.');
            $status = data_get($result, 'data.payment_status');
            if ($status !== 'APPROVED') {
                if (in_array($status, ['EXPIRED', 'CANCELLED'], true)) {
                    return ['expired', null];
                }
                if ($payment->qr_expires_at->isPast()) {
                    $closed = $this->payway($payment, $credentials['secret'], 'close-transaction', $body);
                    if (data_get($closed, 'status.code') === '00' && data_get($closed, 'status.tran_id') === $payment->remote_id) {
                        return ['expired', null];
                    }
                }

                return ['checkout_open', null];
            }
            abort_unless(in_array(data_get($result, 'data.payment_status_code'), [0, '0'], true)
                && data_get($result, 'data.payment_currency') === 'USD'
                && $this->amountMatches(data_get($result, 'data.payment_amount'), $payment->order->total)
                && $this->amountMatches(data_get($result, 'data.refund_amount'), '0.00'), 409, 'PayWay payment does not match this order.');

            return ['paid', $payment->remote_id];
        }

        $base = $payment->live_mode ? 'https://api-bakong.nbc.gov.kh' : 'https://sit-api-bakong.nbc.gov.kh';
        $result = $this->post($this->client($base)->withToken($credentials['token']), '/v1/check_transaction_by_md5', ['md5' => $payment->remote_id]);
        if (($result['responseCode'] ?? null) === 1 && in_array($result['errorCode'] ?? null, [1, 3], true)) {
            // Keep checking briefly after QR expiry for a payment made just before the deadline.
            return [$payment->qr_expires_at->copy()->addMinutes(2)->isPast() ? 'expired' : 'checkout_open', null];
        }
        abort_unless(($result['responseCode'] ?? null) === 0, 502, 'Bakong could not verify this payment.');
        $hash = data_get($result, 'data.hash');
        abort_unless(is_string($hash) && preg_match('/^[a-f0-9]{64}$/iD', $hash)
            && strcasecmp((string) data_get($result, 'data.toAccountId'), $credentials['account_id']) === 0
            && data_get($result, 'data.currency') === 'USD'
            && $this->amountMatches(data_get($result, 'data.amount'), $payment->order->total), 409, 'Bakong payment does not match this order.');

        return ['paid', strtolower($hash)];
    }

    private function payway(CommercePayment $payment, string $secret, string $operation, array $body): array
    {
        $body['hash'] = base64_encode(hash_hmac('sha512', implode('', $body), $secret, true));
        $base = $payment->live_mode ? 'https://checkout.payway.com.kh' : 'https://checkout-sandbox.payway.com.kh';

        return $this->post($this->client($base), '/api/payment-gateway/v1/payments/'.$operation, $body);
    }

    private function credentials(CommercePayment $payment): array
    {
        $credentials = CommerceGatewaySetting::effective($payment->provider, $payment->live_mode);
        abort_unless(CommerceGatewaySetting::configured($payment->provider, $payment->live_mode, $credentials), 503,
            'Payment verification is temporarily unavailable.');

        return $credentials;
    }

    private function client(string $base): PendingRequest
    {
        return Http::baseUrl($base)->acceptJson()->asJson()->connectTimeout(4)->timeout(8)->withoutRedirecting();
    }

    private function post(PendingRequest $client, string $path, array $body): array
    {
        try {
            $response = $client->post($path, $body);
        } catch (ConnectionException) {
            abort(502, 'The payment provider is temporarily unreachable.');
        }
        $data = $response->json();
        abort_unless($response->successful() && is_array($data), 502, 'The payment provider could not confirm the request.');

        return $data;
    }

    private function bakongPayload(array $credentials, string $bill, string $amount, int $created, int $expires): string
    {
        $payload = '000201010212'
            .$this->field('29', $this->field('00', $credentials['account_id']))
            .'520459995303840'
            .$this->field('54', $amount)
            .'5802KH'
            .$this->field('59', $credentials['merchant_name'])
            .$this->field('60', $credentials['merchant_city'])
            .$this->field('62', $this->field('01', $bill))
            .$this->field('99', $this->field('00', (string) $created).$this->field('01', (string) $expires))
            .'6304';

        return $payload.$this->crc($payload);
    }

    private function parseFields(string $payload): array
    {
        $fields = [];
        for ($offset = 0, $size = strlen($payload); $offset < $size;) {
            $tag = substr($payload, $offset, 2);
            $length = substr($payload, $offset + 2, 2);
            abort_unless(strlen($length) === 2 && ctype_digit($tag) && ctype_digit($length) && ! isset($fields[$tag]), 502);
            $offset += 4;
            abort_if($offset + (int) $length > $size, 502);
            $fields[$tag] = substr($payload, $offset, (int) $length);
            $offset += (int) $length;
        }

        return $fields;
    }

    private function field(string $tag, string $value): string
    {
        return $tag.sprintf('%02d', strlen($value)).$value;
    }

    private function crc(string $payload): string
    {
        $crc = 0xffff;
        foreach (str_split($payload) as $byte) {
            $crc ^= ord($byte) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc << 1) ^ (($crc & 0x8000) ? 0x1021 : 0)) & 0xffff;
            }
        }

        return sprintf('%04X', $crc);
    }

    private function amountMatches(mixed $received, string $expected): bool
    {
        if (! (is_int($received) || is_float($received) || is_string($received))
            || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) $received)) {
            return false;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $received, 2), 2, '');

        return (int) ($whole.str_pad($fraction, 2, '0')) === (int) str_replace('.', '', $expected);
    }
}

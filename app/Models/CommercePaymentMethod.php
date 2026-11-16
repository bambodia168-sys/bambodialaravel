<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercePaymentMethod extends Model
{
    public const PROVIDERS = [
        'stripe'=>'Stripe', 'paypal'=>'PayPal', 'bank_transfer'=>'Bank Transfer', 'khqr'=>'KHQR',
        'aba'=>'ABA', 'bakong'=>'Bakong', 'wing'=>'Wing', 'other'=>'Other manual method',
    ];

    protected $fillable = ['name', 'available', 'provider', 'instructions', 'qr_path'];

    public function automatic(): bool
    {
        return in_array($this->provider, ['stripe', 'paypal'], true);
    }

    public function dynamicQr(): bool
    {
        return in_array($this->provider, CommercePayment::QR_PROVIDERS, true);
    }

    public function usesGateway(): bool
    {
        return $this->automatic() || $this->dynamicQr();
    }

    public function availableForCheckout(bool $live): bool
    {
        return $this->usesGateway()
            ? ($live || ! app()->environment('production')) && \App\Support\CommerceGateway::ready($this->provider, $live)
            : filled($this->instructions);
    }

    protected function casts(): array
    {
        return ['available' => 'boolean'];
    }
}

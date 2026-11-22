<?php

namespace App\Support;

use App\Models\MarketplaceOrder;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

final class OrderReceipt
{
    public static function status(MarketplaceOrder $order): ?string
    {
        if (! $order->public_reference || ! $order->receipt_number || ! $order->completed_at) {
            return null;
        }

        return match (true) {
            $order->status === 'completed' && $order->total === '0.00' => 'No payment required',
            $order->status === 'refunded' && $order->paid_attempt_exists => 'Refunded',
            $order->status === 'completed' && $order->paid_attempt_exists => 'Paid',
            default => null,
        };
    }

    public static function token(MarketplaceOrder $order): string
    {
        // The cryptographically random, immutable receipt UUID seeds the token.
        // Keeping it independent of APP_KEY preserves previously printed QR links.
        return substr(hash('sha256', 'bambodia:receipt:'.$order->receipt_number), 0, 32);
    }

    public static function verificationUrl(MarketplaceOrder $order): string
    {
        return rtrim(config('app.url'), '/').route('commerce.receipts.verify', [
            'reference' => $order->public_reference, 'token' => self::token($order),
        ], absolute: false);
    }

    public static function qrSource(string $url): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 4), new SvgImageBackEnd));
        $svg = $writer->writeString($url, ecLevel: ErrorCorrectionLevel::M());

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

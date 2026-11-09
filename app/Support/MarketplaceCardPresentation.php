<?php

namespace App\Support;

/**
 * Public marketplace card presentation.
 *
 * Ratings and discounts are intentionally not fabricated: these labels use
 * real persisted data only. No unverified rating source exists yet.
 */
final class MarketplaceCardPresentation
{
    public static function price(mixed $amount, ?string $currency = 'USD'): string
    {
        if ($amount === null) {
            return 'Price on request';
        }
        $code = strtoupper(trim((string) ($currency ?: 'USD')));
        $symbol = match ($code) {
            'USD' => '$',
            'KHR' => '៛',
            'EUR' => '€',
            'GBP' => '£',
            'THB' => '฿',
            'JPY', 'CNY' => '¥',
            'AUD' => 'A$',
            'CAD' => 'C$',
            'SGD' => 'S$',
            'INR' => '₹',
            'KRW' => '₩',
            default => null,
        };
        $decimals = in_array($code,['KHR','JPY','KRW'],true) ? 0 : 2;
        return ($symbol ?? $code.' ').number_format((float)$amount,$decimals);
    }

    /**
     * Accept a creator-provided HTTPS demonstration link, never an executable
     * scheme, local address, credential-bearing or nonstandard-port URL.
     * It is not fetched on the server and does not imply platform endorsement.
     */
    public static function safePreviewUrl(?string $input): ?string
    {
        $url=trim((string)$input);
        if($url==='') return null;
        if(strlen($url)>500 || str_contains($url,'\\')
            || preg_match('/[\x00-\x20\x7f]/',$url)) return null;
        if(filter_var($url,FILTER_VALIDATE_URL)===false) return null;
        $parts=parse_url($url);
        if(!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) return null;

        $host=strtolower((string)($parts['host']??''));
        if($host==='' || strlen($host)>253 || filter_var($host,FILTER_VALIDATE_IP) !== false
            || !str_contains($host,'.') || str_ends_with($host,'.local')
            || str_ends_with($host,'.internal') || str_ends_with($host,'.test')
            || $host==='localhost'
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host))
            return null;
        return $url;
    }
}

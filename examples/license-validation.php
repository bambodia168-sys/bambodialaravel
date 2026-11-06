<?php
/**
 * Minimal, fail-closed client for independently installed PHP/Laravel scripts.
 * Configure key and expected product ID in server-private environment variables.
 * Never place the key in URLs, browser Javascript or public Git repositories.
 */
declare(strict_types=1);

function bambodiaVerifyInstalledProduct(
    string $key,
    string $appUrl,
    string $expectedProductId,
    bool $activate = false
): bool {
    if (!extension_loaded('curl')
        || !preg_match('/^BAM-[A-F0-9]{48}$/D', $key)
        || !preg_match('/^[0-9]{9}$/D', $expectedProductId)) {
        return false;
    }

    $domain = parse_url($appUrl, PHP_URL_HOST);
    if (!is_string($domain) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $domain)) {
        return false;
    }
    $domain = strtolower($domain);
    $endpoint = 'https://bambodia.com/api/v1/licenses/'.($activate ? 'activate' : 'validate');
    $curl = curl_init($endpoint);
    if ($curl === false) return false;
    try {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['license_key'=>$key,'product_id'=>$expectedProductId,'domain'=>$domain], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json','Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        if (!is_string($response) || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) return false;
        $data = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        return is_array($data)
            && ($data['valid'] ?? null) === true
            && ($data['product_id'] ?? null) === $expectedProductId
            && ($data['domain'] ?? null) === $domain
            && in_array($data['tier'] ?? null,['regular','extended'],true)
            && in_array($data['status'] ?? null,['valid','activated'],true);
    } catch (\Throwable) {
        return false;
    } finally {
        curl_close($curl);
    }
}

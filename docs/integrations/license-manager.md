# Bambodia License Manager Integration Guide

License authentication for new PHP and Laravel scripts using API v1.

A Bambodia product key authorizes an installation of a specific product on a registered domain. Your installer sends the buyer’s key to Bambodia over HTTPS before completing setup. After activation, your application checks the same key and domain through the validation endpoint.

## Implementation sequence

- **Prepare the listing.** Assign an approved downloadable release and an active license definition. Record the listing’s nine-digit public product ID.

- **Bind the package.** Set the expected public product ID in the release build. Read the installation hostname from the trusted application URL.

- **Activate during setup.** Ask for the buyer’s key in a protected installer form and require a confirmed activation before writing installation state.

- **Validate on a schedule.** Recheck the registered installation from the server, handle failed checks explicitly, and provide an authenticated recovery path.

## License information

| **Value**           | **Where it comes from**               | **How the script uses it**                                                       |
|---------------------|---------------------------------------|----------------------------------------------------------------------------------|
| Public product ID   | The Bambodia product listing          | Public build setting. It identifies the exact product this package can install.  |
| Installation domain | The trusted application URL           | Canonical hostname. Bambodia binds activation to this hostname.                  |
| License key         | The buyer’s My Licenses account       | Secret entered during setup. Used in the JSON body of server-to-server requests. |
| License ID          | The activation or validation response | Public reference for troubleshooting and support.                                |

Bambodia issues a key after it verifies a supported one-time purchase. A Super Admin can also issue a complimentary key against an approved entitlement. Buyers find the download and license through their order or account.

## Prepare the listing and installer

### Marketplace preparation

- **Super Admin or authorized marketplace staff** review the published product and approved downloadable release. The product needs an active Regular or Extended license definition and a current clean security scan for its exact file.

- **The package maintainer** builds the ZIP for the listing’s nine-digit public ID. Use the public ID rather than an internal database ID or the public license ID.

- **The buyer** obtains the key from My Licenses using the account’s password-confirmed reveal action. Give the buyer a link to the account page and identify which product they are installing.

[bambodia.com/account/licenses](https://bambodia.com/account/licenses)

### Secure installation flow

1.  **Check the host and prerequisites.** Confirm HTTPS, PHP cURL, access to Bambodia on TCP 443, and a safe setup destination. Derive the domain from a trusted, normalized setup origin or the configured application URL. Treat forwarded host headers as untrusted unless your proxy is configured and trusted.

2.  **Collect the key in the final installer step.** Use a password input, CSRF protection, an authenticated setup session or server owner proof, and sensible attempt limits. Send the key directly from the server to Bambodia.

3.  **Activate before committing setup.** Check that the response identifies the expected product and exact normalized domain. Write installation configuration and run migrations only after activation succeeds.

4.  **Recover a partial install for the same domain.** Repeating activation on the same active domain is idempotent. Use the application’s normal setup recovery when a local step fails after Bambodia accepts activation.

### GoMovie reference

GoMovie’s marketplace packager reads `BAMBODIA_PRODUCT_ID` and includes that ID in the ZIP. Its standalone installer requests the buyer’s key and activates the domain before writing configuration or migrating the database. Build your own runtime validation separately if your script needs periodic checks.

[GoMovie marketplace installer](https://github.com/bambodia168-sys/Gomovie/pull/13)

## API requests and responses

| **Operation**            | **POST endpoint**                             | **Success status** |
|--------------------------|-----------------------------------------------|--------------------|
| Activate a key           | https://bambodia.com/api/v1/licenses/activate | activated          |
| Validate an installation | https://bambodia.com/api/v1/licenses/validate | valid              |

Send JSON with `Content-Type: application/json` and `Accept: application/json`. The license key travels only in the HTTPS request body. The endpoints do not require a Bambodia developer token.

### Request body

```json
{
  "license_key": "BAM-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
  "product_id": "123456789",
  "domain": "store.example.com"
}
```

The sample key and IDs are placeholders. The key format is `BAM-` followed by 48 uppercase hexadecimal characters. Send a nine-digit public product ID and a bare DNS hostname. Bambodia lowercases the hostname and removes its trailing dot. Use the chosen host exactly and omit its scheme, port, path, and query.

### Successful activation response

```json
{
  "valid": true,
  "status": "activated",
  "license_id": "f9b45c12-71da-40fd-90da-c8412560ecb6",
  "product_id": "123456789",
  "tier": "regular",
  "license_version": "1.0.0",
  "release_version": "2.1.0",
  "domain": "store.example.com",
  "expires_at": null,
  "checked_at": "2026-11-19T01:30:00+00:00",
  "check_again_after_seconds": 21600
}
```

Accept a successful check only when HTTP is 200, `valid` is the Boolean `true`, `status` matches your operation, and the returned product ID and domain match your configuration. Compare product IDs as strings. Read `tier` as `regular` or `extended`. License and release versions are metadata. `expires_at` is `null` for lifetime keys. The validator returns status `valid` for an existing active binding.

Activation is limited to 12 requests per minute and validation to 30 per minute, subject to the enclosing API limit. Spread scheduled checks across installations and back off after throttling.

## Handle validation results

| **Result**                       | **Meaning**                                                                                               | **Application behavior**                                                                                        |
|----------------------------------|-----------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------|
| 200 with expected fields         | Bambodia confirmed the requested operation.                                                               | Complete activation or refresh the server-side validation result.                                               |
| 200 with wrong or missing fields | The response cannot be matched to this installation.                                                      | Reject the result and log a sanitized integration error.                                                        |
| 403                              | Key, product or domain was rejected, or the product or entitlement is ineligible. HTTPS is also required. | Keep setup blocked. During runtime, clear any cached positive result and send the owner to the recovery screen. |
| 409 on activation                | An active domain already occupies the license or a different replacement is pending.                      | Ask the owner to review the domain in My Licenses, then activate the intended domain.                           |
| 422                              | A field is missing or violates the request contract.                                                      | Correct the input. Record affected field names only.                                                            |
| 429                              | The request was throttled.                                                                                | Honor `Retry-After` when present and retry with backoff.                                                          |
| Timeout or 5xx                   | The remote check could not be completed.                                                                  | Treat the result as unavailable. Retry with backoff and jitter.                                                 |
| Redirect or other status         | Unexpected response for this fixed API.                                                                   | Reject the result. Check endpoint, TLS or host configuration.                                                   |

### Runtime schedule

After the initial activation, call `validate` on a server-side schedule. The server currently suggests 21,600 seconds, or six hours. Store the most recent verified result and its check time in private application state, keyed to the product, domain and current key version. Avoid a remote request on every page load. Perform a fresh check after an owner changes the key or domain.

A timeout is an unavailable check. Use only a still-current verified result within your normal validation window. At its end, restrict protected operations and keep an authenticated recovery screen reachable. An explicit denial invalidates the cached success immediately. Preserve installation data and present a retry option for transient outages.

## Reference PHP client

Requires PHP 8 and the cURL extension. Copy this server-side function into the installer or a private application service.

```php
<?php
declare(strict_types=1);

function checkBambodiaLicense(
    string $key, string $productId, string $appUrl, bool $activate
): array {
    $key = trim($key);
    $host = parse_url($appUrl, PHP_URL_HOST);
    $domain = is_string($host) ? rtrim(strtolower($host), '.') : '';
    $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
    if (!preg_match('/\ABAM-[A-F0-9]{48}\z/', $key)
        || !preg_match('/\A[0-9]{9}\z/', $productId)
        || parse_url($appUrl, PHP_URL_SCHEME) !== 'https'
        || strlen($domain) > 253
        || !preg_match("/\A(?:$label\.)+[a-z]{2,63}\z/", $domain)) {
        return ['outcome' => 'invalid_input', 'http_status' => 0];
    }
    $path = $activate ? 'activate' : 'validate';
    $ch = curl_init("https://bambodia.com/api/v1/licenses/$path");
    if ($ch === false) {
        return ['outcome' => 'retry', 'http_status' => 0];
    }
    try {
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'license_key' => $key, 'product_id' => $productId,
                'domain' => $domain,
            ], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json', 'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    } finally {
        curl_close($ch);
    }
    $data = is_string($body) ? json_decode($body, true, 16) : null;
    $returned = $data['product_id'] ?? null;
    $ok = $http === 200 && ($data['valid'] ?? null) === true
        && ($data['status'] ?? null) === ($activate ? 'activated' : 'valid')
        && ($data['domain'] ?? null) === $domain
        && (is_string($returned) || is_int($returned))
        && (string) $returned === $productId
        && in_array($data['tier'] ?? null, ['regular', 'extended'], true);
    $outcome = match (true) {
        $ok => 'valid',
        $http === 403 => 'denied',
        $http === 409 => 'conflict',
        $http === 422 => 'invalid_input',
        $body === false || $http === 0 || $http === 429 || $http >= 500 => 'retry',
        default => 'unexpected',
    };
    return ['outcome' => $outcome, 'http_status' => $http];
}
```

## Use the client and manage support

### Call it from the server

```php
$result = checkBambodiaLicense(
    $buyerKey, $expectedProductId, $trustedAppUrl, true
);
if ($result['outcome'] !== 'valid') {
    // Stop setup and show the matching sanitized recovery message.
    return;
}
// Continue the installer only after confirmed activation.
```

Use `false` as the final argument for scheduled validation. Pass the built-in public product ID and the trusted application URL. In Laravel, load the private key through application configuration or a secret manager before invoking the client. Keep the installer and the runtime service out of browser JavaScript. The reference function returns the outcome and HTTP status. Capture response headers in your HTTP client if you need to honor `Retry-After`.

### Protect credentials

- If periodic validation requires the key, store it in an encrypted configuration store, hosting secret manager, or a file outside the web root readable only by the application account. A hash by itself cannot supply the original key on a later check.

- Redact the key from logs, request captures, errors, screenshots and crash reports. Keep TLS verification enabled. Leave redirects disabled so the client only sends credentials to the intended Bambodia endpoint.

- Provide an authenticated owner screen to replace a rotated key or retry validation. Minimize logged details to the result, time, public product ID and public license ID where available.

### Lifetime license and support

One-time product licenses are lifetime licenses. The API’s `expires_at` field refers to the license, so it is `null` for these keys. Verified purchases include six months of support by default. When issuing a complimentary key, a Super Admin can select six months, 12 months, five years, or lifetime support. A Super Admin reviews renewal requests from My Licenses.

Support expiry leaves the license valid for its registered domain while the key, entitlement and product remain eligible. A separate file-download entitlement may limit new downloads. A stale security scan or an unavailable or altered approved product file may block validation until marketplace staff restore eligibility.

## Deployment and acceptance

### Recover an installation

For a domain move, the customer releases the old domain or selects the replacement in My Licenses. On the new host, call `activate` with the same key and the new hostname. Then call `validate` on that hostname. If a Super Admin rotates the key, replace the stored secret on the installation and run a fresh check. Suspension, revocation and product or entitlement ineligibility take precedence over the lifetime term.

### Pre-release acceptance matrix

| **Test**                              | **Expected result**                                                                                                                     |
|---------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------|
| Verified one-time purchase            | The buyer can access the download and one key. Repeated payment events reuse the issued key.                                            |
| Initial install and same-domain retry | Activation succeeds for the packaged product. A retry on the same domain does not consume another slot.                                 |
| Wrong product or second domain        | A mismatched product is rejected. An occupied domain slot blocks activation until the domain is released or transferred.                |
| Support expiry                        | The same key still activates and validates on the correct domain while the release and entitlement are eligible. `expires_at` stays `null`. |
| Rotation, suspension and revocation   | The old key or inactive license fails. The recovery screen remains available to the owner.                                              |
| Timeout, 429, invalid response        | Setup stops safely and scheduled validation follows the error handling and retry rules.                                                 |
| Host security                         | The ZIP contains the public product ID and excludes buyer keys. Test HTTPS, outbound TLS and log redaction on a representative host.    |

Use a dedicated QA product and license on a test hostname. Record the public product or license ID and the observed result. If your script also includes an Auto Updater, protect its update feed, authorize each download on the server, and verify package integrity independently. The v1 license endpoints return validation results rather than update packages.

## Implementation references

- [API routes and throttling](../../routes/api.php)
- [Activation and validation controller](../../app/Http/Controllers/Api/ProductLicenseApiController.php)
- [Product license lifecycle](../product-license-lifecycle.md) and [domain controls](../../app/Support/ProductLicenseLifecycle.php)
- [Payment processing and verified delivery](../commerce-payments.md) and [PR #107](https://github.com/bambodia168-sys/bambodialaravel/pull/107)

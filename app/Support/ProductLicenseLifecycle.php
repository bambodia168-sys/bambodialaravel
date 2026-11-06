<?php

namespace App\Support;

use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\ProductLicense;
use App\Models\ProductLicenseActivation;
use App\Models\ProductLicenseEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductLicenseLifecycle
{
    /**
     * This is the ONLY issuance path currently enabled. It is explicitly a
     * complimentary manual grant, not proof of a purchase or processed payment.
     * Do not wire order creation or browser-submitted "paid" flags here.
     */
    public static function issueManual(ProductEntitlement $entitlement, string $tier, User $administrator, string $reason, string $supportTerm = 'six_months'): ProductLicense
    {
        abort_unless($administrator->role === 'super_admin', 403);

        ProductLicenseSupport::assertTerm($supportTerm);
        return DB::transaction(function () use ($entitlement, $tier, $administrator, $reason, $supportTerm): ProductLicense {
            $grant = ProductEntitlement::query()->lockForUpdate()->findOrFail($entitlement->id);
            abort_unless($grant->isActive(), 409, 'A current download entitlement is required.');
            abort_if(ProductLicense::query()->where('product_entitlement_id',$grant->id)->exists(), 409,
                'This entitlement already has an issued license. Manage that license instead.');

            $version = ProductFileVersion::query()->whereKey($grant->product_file_version_id)->lockForUpdate()->firstOrFail();
            abort_unless($version->isApproved(), 409, 'An independently approved downloadable original is required.');
            $product = MarketplaceListing::query()->whereKey($version->marketplace_listing_id)->lockForUpdate()->firstOrFail();
            abort_unless($product->type === 'product' && $product->status === 'published', 409,
                'Only published, downloadable products can receive licenses.');
            ProductReadiness::assertListing($product);
            abort_unless(ProductVault::verifiedPath($version->storage_path, $version->sha256, (int)$version->byte_size) !== null,
                409, 'Product original is missing or fails its SHA-256 integrity check.');
            abort_unless($tier === 'regular' || $tier === 'extended', 422);

            $definitionId = $tier === 'regular' ? $product->license_definition_id : $product->extended_license_definition_id;
            abort_if($tier === 'extended' && $product->extended_price === null, 409,
                'The product does not offer an Extended License.');
            $definition = $definitionId ? LicenseDefinition::query()->find($definitionId) : null;
            abort_unless($definition && $definition->status === 'active', 409,
                'The selected license definition must be active and reviewed.');

            $secret = 'BAM-'.strtoupper(bin2hex(random_bytes(24)));
            $license = ProductLicense::query()->create([
                'product_entitlement_id' => $grant->id,
                'marketplace_listing_id' => $product->id,
                'product_file_version_id' => $version->id,
                'user_id' => $grant->user_id,
                'license_definition_id' => $definition->id,
                'tier' => $tier,
                'source' => 'admin_complimentary',
                'issued_by' => $administrator->id,
                'key_hash' => hash('sha256', $secret),
                'key_prefix' => substr($secret,0,12),
                'key_ciphertext' => $secret,
                'status' => 'active',
                'max_domains' => 1,
                'expires_at' => null,
                ...ProductLicenseSupport::issuePeriod($supportTerm),
            ]);
            self::event($license,'issued',$administrator->id,null,null,
                mb_substr($reason.' · '.ProductLicenseSupport::TERMS[$supportTerm],0,500));
            return $license;
        });
    }

    /** The installer must send the key over HTTPS; no key ever appears in a URL. */
    public static function byKey(string $key): ?ProductLicense
    {
        if (! preg_match('/^BAM-[A-F0-9]{48}$/D', $key)) return null;
        return ProductLicense::query()->where('key_hash',hash('sha256',$key))
            ->with(['entitlement','definition','product','version'])->first();
    }

    public static function eligible(ProductLicense $license): bool
    {
        if (! $license->isActive()) return false;
        $product = $license->product;
        $version = $license->version;
        $definition = $license->definition;
        if (! $product || ! $version || ! $definition
            || $product->type !== 'product' || $product->status !== 'published'
            || (int)$version->marketplace_listing_id !== (int)$product->id
            || (int)$license->entitlement->product_file_version_id !== (int)$version->id
            || (int)$license->entitlement->user_id !== (int)$license->user_id
            || $definition->status !== 'active'
            || ! $version->isApproved()) return false;

        $expectedDefinition = $license->tier === 'regular'
            ? $product->license_definition_id : $product->extended_license_definition_id;
        if ($license->tier === 'extended' && $product->extended_price === null) return false;
        if ((int)$expectedDefinition !== (int)$definition->id) return false;
        return ProductVault::verifiedPath($version->storage_path, $version->sha256, (int)$version->byte_size) !== null;
    }

    public static function domain(string $input): string
    {
        $domain = strtolower(trim($input));
        if (str_starts_with($domain, 'https://')) {
            $parts = parse_url($domain);
            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
                || isset($parts['query']) || isset($parts['fragment'])
                || ! in_array($parts['path'] ?? '', ['','/'], true)) {
                throw ValidationException::withMessages(['domain' => 'Enter only a domain, without a path, port or credentials.']);
            }
            $domain = strtolower($parts['host'] ?? '');
        }
        $domain = rtrim($domain,'.');
        if (strlen($domain) > 253 || filter_var($domain,FILTER_VALIDATE_IP)
            || ! preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $domain)) {
            throw ValidationException::withMessages(['domain' => 'Use a real domain name, such as store.example.com. IPs, localhost and paths are not accepted.']);
        }
        return $domain;
    }

    public static function activate(ProductLicense $license, string $domain, ?User $actor = null): ProductLicenseActivation
    {
        return DB::transaction(function () use ($license, $domain, $actor): ProductLicenseActivation {
            $locked = ProductLicense::query()->lockForUpdate()->findOrFail($license->id);
            $locked->load(['entitlement','definition','product','version']);
            abort_unless(self::eligible($locked), 403, 'License validation failed.');
            $existing = ProductLicenseActivation::query()
                ->where('product_license_id',$locked->id)->where('domain',$domain)
                ->lockForUpdate()->first();
            if ($existing && $existing->status === 'active') return $existing;
            abort_if($locked->pending_domain !== null && $locked->pending_domain !== $domain, 409,
                'Activate only the replacement domain selected in the customer account.');

            $activeCount = ProductLicenseActivation::query()
                ->where('product_license_id',$locked->id)->where('status','active')->count();
            abort_if($activeCount >= $locked->max_domains, 409,
                'The licensed domain limit was reached. Deactivate the previous domain in your account.');
            if ($existing) {
                $existing->update(['status'=>'active','deactivated_at'=>null,'activated_at'=>now()]);
                $activation = $existing;
            } else {
                $activation = ProductLicenseActivation::query()->create([
                    'product_license_id'=>$locked->id,'domain'=>$domain,'status'=>'active','activated_at'=>now(),
                ]);
            }
            if ($locked->pending_domain !== null) {
                $locked->update(['pending_domain'=>null]);
            }
            self::event($locked,'domain.activated',$actor?->id,null,$domain);
            return $activation;
        });
    }

    public static function changeDomain(ProductLicense $license, ?string $domain, User $actor, string $reason): void
    {
        DB::transaction(function () use ($license,$domain,$actor,$reason): void {
            $locked = ProductLicense::query()->lockForUpdate()->findOrFail($license->id);
            abort_unless($locked->status !== 'revoked', 409, 'A revoked license cannot change domains.');
            $current = ProductLicenseActivation::query()->where('product_license_id',$locked->id)
                ->where('status','active')->lockForUpdate()->get();
            $previous = $current->pluck('domain')->implode(', ');
            foreach ($current as $activation) {
                $activation->update(['status'=>'deactivated','deactivated_at'=>now()]);
            }
            $locked->update(['pending_domain'=>$domain]);
            // Admin/customer initiated domain transfers require the script to
            // prove possession of the key at activation time; no bypass.
            self::event($locked,'domain.changed',$actor->id,$previous ?: null,$domain,$reason);
        });
        // New domain is an intended destination, not an active domain. The
        // installed script must call /api/v1/licenses/activate using its key.
    }

    public static function status(ProductLicense $license, string $action, User $actor, string $reason): void
    {
        DB::transaction(function () use ($license,$action,$actor,$reason): void {
            $locked = ProductLicense::query()->lockForUpdate()->findOrFail($license->id);
            abort_if($locked->status === 'revoked',409,'Revocation is permanent.');
            if ($action === 'suspend') {
                abort_unless($locked->status === 'active',409);
                $locked->update(['status'=>'suspended','suspended_at'=>now()]);
            } elseif ($action === 'activate') {
                abort_unless($locked->status === 'suspended',409);
                $locked->load(['entitlement','definition','product','version']);
                $locked->update(['status'=>'active','suspended_at'=>null]);
                // Transaction rollback restores suspension if any product,
                // entitlement, scanner, rights or file integrity gate fails.
                abort_unless(self::eligible($locked),409,
                    'The approved file, definition and entitlement must be valid before reactivation.');
            } elseif ($action === 'revoke') {
                $locked->update(['status'=>'revoked','revoked_at'=>now()]);
                ProductLicenseActivation::query()->where('product_license_id',$locked->id)
                    ->where('status','active')->update(['status'=>'deactivated','deactivated_at'=>now()]);
            } else abort(422);
            self::event($locked,$action,$actor->id,null,null,$reason);
        });
    }

    public static function upgrade(ProductLicense $license, User $administrator, string $reason): void
    {
        DB::transaction(function () use ($license,$administrator,$reason): void {
            $locked = ProductLicense::query()->lockForUpdate()->findOrFail($license->id);
            abort_unless($locked->status === 'active' && $locked->tier === 'regular',409);
            $locked->load(['entitlement','definition','product','version']);
            abort_unless(self::eligible($locked),409,'The original license must still be valid for upgrade.');
            $product = $locked->product()->firstOrFail();
            abort_unless($product->type === 'product' && $product->extended_price !== null,409);
            $extended = $product->extendedLicenseDefinition;
            abort_unless($extended && $extended->status === 'active',409);
            $locked->update(['tier'=>'extended','license_definition_id'=>$extended->id]);
            self::event($locked,'upgraded',$administrator->id,null,null,$reason);
        });
    }

    public static function rotate(ProductLicense $license, User $actor, string $reason): void
    {
        DB::transaction(function () use ($license,$actor,$reason): void {
            $locked = ProductLicense::query()->lockForUpdate()->findOrFail($license->id);
            abort_if($locked->status === 'revoked',409);
            $secret = 'BAM-'.strtoupper(bin2hex(random_bytes(24)));
            $locked->update([
                'key_hash'=>hash('sha256',$secret),
                'key_prefix'=>substr($secret,0,12),
                'key_ciphertext'=>$secret,
            ]);
            self::event($locked,'key.rotated',$actor->id,null,null,$reason);
        });
    }

    private static function event(ProductLicense $license, string $action, ?int $actorId,
        ?string $previous, ?string $domain, ?string $reason = null): void
    {
        ProductLicenseEvent::query()->create([
            'product_license_id'=>$license->id,'actor_id'=>$actorId,'action'=>$action,
            'previous_domain'=>$previous,'domain'=>$domain,'reason'=>$reason,
            'created_at'=>now(),
        ]);
    }
}

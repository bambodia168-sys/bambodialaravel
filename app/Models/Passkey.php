<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\LaravelPasskeys\Models\Passkey as SpatiePasskey;
use Webauthn\PublicKeyCredentialSource;

final class Passkey extends SpatiePasskey
{
    protected $hidden = ['credential_id', 'data'];

    public static function encodeCredentialId(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public function data(): Attribute
    {
        $original = parent::data();

        return Attribute::make(
            get: $original->get,
            set: function (PublicKeyCredentialSource $value) use ($original): array {
                $data = ($original->set)($value);
                $data['credential_id'] = static::encodeCredentialId($value->publicKeyCredentialId);

                return $data;
            },
        );
    }
}

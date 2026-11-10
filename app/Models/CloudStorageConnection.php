<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CloudStorageConnection extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'id','provider','endpoint','bucket','access_key_id','secret_access_key',
        'upload_mode','verified_at','updated_by',
    ];

    protected function casts(): array
    {
        return [
            'access_key_id'=>'encrypted',
            'secret_access_key'=>'encrypted',
            'verified_at'=>'datetime',
        ];
    }

    public function diskConfig(): array
    {
        return [
            'driver' => 's3',
            'key' => $this->access_key_id,
            'secret' => $this->secret_access_key,
            'region' => 'auto',
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => true,
        ];
    }
}

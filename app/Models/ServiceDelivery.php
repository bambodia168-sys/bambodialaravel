<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceDelivery extends Model
{
    protected $fillable = [
        'notes', 'original_filename', 'storage_path', 'file_type', 'byte_size', 'sha256',
        'decision', 'feedback', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}

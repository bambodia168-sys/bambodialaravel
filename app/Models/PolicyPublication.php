<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyPublication extends Model
{
    public $timestamps=false;
    protected $fillable=[
        'policy_id','revision','title','category','jurisdiction','body_snapshot','sha256','published_by','created_at',
    ];
    protected function casts(): array { return ['created_at'=>'datetime']; }
    public function policy(): BelongsTo { return $this->belongsTo(Policy::class); }
    public function acknowledgements(): HasMany { return $this->hasMany(PolicyAcknowledgement::class); }
    public function publishedBy(): BelongsTo { return $this->belongsTo(User::class,'published_by'); }

    public static function digestFor(Policy $policy): string
    {
        return hash('sha256',json_encode([
            'policy_public_id'=>$policy->public_id,'title'=>$policy->title,
            'category'=>$policy->category,'jurisdiction'=>$policy->jurisdiction,
            'body'=>$policy->body,
        ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
}

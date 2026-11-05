<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyRequest extends Model
{
    public const CATEGORIES=['access','correction','erasure','restriction','portability','objection','other'];
    protected $fillable=[
        'user_id','category','details','status','reviewed_by','response_note',
        'review_started_at','responded_at','closed_at',
    ];
    protected function casts(): array
    {
        return ['review_started_at'=>'datetime','responded_at'=>'datetime','closed_at'=>'datetime'];
    }
    protected static function booted(): void
    {
        static::creating(function(self $request): void {
            for($i=0;$i<30;$i++){
                $id=(string)random_int(100000000,999999999);
                if(!self::query()->where('public_id',$id)->exists()){
                    $request->public_id=$id;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate privacy request reference.');
        });
    }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function owner(): BelongsTo { return $this->belongsTo(User::class,'user_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class,'reviewed_by'); }
}

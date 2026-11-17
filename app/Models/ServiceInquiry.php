<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceInquiry extends Model
{
    public const DELIVERY_STATUSES = [
        'awaiting_requirements' => 'Awaiting requirements',
        'in_progress' => 'In progress',
        'delivered' => 'Ready for buyer review',
        'revision_requested' => 'Revision requested',
        'accepted' => 'Delivery accepted',
    ];

    protected $fillable = [
        'marketplace_order_id','marketplace_listing_id','client_id','creator_id','subject','proposed_budget',
        'currency','status','closed_by','closed_at','delivery_status',
    ];

    protected function casts(): array
    {
        return ['proposed_budget'=>'decimal:2','closed_at'=>'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $inquiry): void {
            for ($attempt = 0; $attempt < 30; $attempt++) {
                $candidate = (string) random_int(100000000, 999999999);
                if (! self::query()->where('public_id',$candidate)->exists()) {
                    $inquiry->public_id=$candidate;
                    return;
                }
            }
            throw new \RuntimeException('Unable to allocate a unique inquiry identifier.');
        });
    }

    public function listing(): BelongsTo { return $this->belongsTo(MarketplaceListing::class,'marketplace_listing_id'); }
    public function order(): BelongsTo { return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id'); }
    public function client(): BelongsTo { return $this->belongsTo(User::class,'client_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'creator_id'); }
    public function hasVerifiedOrder(): bool
    {
        return $this->marketplace_order_id !== null
            && $this->order()->where('status', 'completed')->whereHas('paidAttempt')->exists();
    }

    public function deliveryStatus(): string
    {
        return $this->delivery_status ?? 'awaiting_requirements';
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ServiceDelivery::class);
    }

    public function messages(): HasMany { return $this->hasMany(ServiceInquiryMessage::class); }
}

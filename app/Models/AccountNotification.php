<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountNotification extends Model
{
    public const EVENTS = [
        'job.reviewed',
        'job.proposal.received',
        'job.proposal.decision',
        'inquiry.received',
        'inquiry.replied',
        'inquiry.closed',
        'vault.access.granted',
        'vault.access.revoked',
        'license.issued',
        'license.status.changed',
        'license.creator.review.requested',
        'license.support.requested',
        'license.support.reviewed',
        'listing.reviewed',
        'vault.version.reviewed',
        'detail.comment.received',
        'support.replied',
        'support.status_changed',
        'contract.offered',
        'contract.accepted',
        'contract.declined',
        'contract.cancelled',
        'contract.milestone.submitted',
        'contract.milestone.reviewed',
        'contract.completion.acknowledged',
        'contract.completed',
        'contract.message.received',
        'contract.dispute.opened',
        'contract.dispute.resolved',
        'privacy.request.responded',
        'policy.updated',
    ];

    protected $fillable = ['user_id','event','title','message','target_path','read_at'];
    protected function casts(): array { return ['read_at'=>'datetime']; }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class,'user_id'); }

    public static function deliver(int $userId, string $event, string $title, string $message, string $path): void
    {
        if (! in_array($event,self::EVENTS,true)
            || mb_strlen($title)>180 || mb_strlen($message)>500
            || ! str_starts_with($path,'/') || str_starts_with($path,'//')
            || str_contains($path,"\r") || str_contains($path,"\n") || strlen($path)>500) {
            throw new \InvalidArgumentException('Invalid application notification.');
        }

        self::query()->create([
            'user_id'=>$userId,'event'=>$event,'title'=>$title,
            'message'=>$message,'target_path'=>$path,
        ]);
    }
}

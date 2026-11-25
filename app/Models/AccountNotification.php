<?php

namespace App\Models;

use App\Support\EmailOutbox;
use App\Support\UserManagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountNotification extends Model
{
    public const EVENTS = [
        'marketplace.submission.received',
        'marketplace.review.requested',
        'account.email.verification_sent',
        'account.email.verified',
        'job.reviewed',
        'job.proposal.received',
        'job.proposal.decision',
        'inquiry.received',
        'inquiry.replied',
        'inquiry.closed',
        'commerce.payment.approved',
        'commerce.payment.rejected',
        'commerce.delivery.delayed',
        'commerce.bakong.token_alert',
        'commerce.refund.confirmed',
        'commerce.service.purchased',
        'commerce.service.requirements',
        'commerce.service.delivered',
        'commerce.service.revision_requested',
        'commerce.service.accepted',
        'order.tracking.task_added',
        'order.tracking.task_updated',
        'order.tracking.schedule_requested',
        'order.tracking.schedule_approved',
        'order.tracking.schedule_declined',
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
        'security.alert.detected',
    ];

    protected $fillable = ['user_id','event','title','message','target_path','read_at'];
    protected function casts(): array { return ['read_at'=>'datetime']; }
    public function recipient(): BelongsTo { return $this->belongsTo(User::class,'user_id'); }

    public function scopeForRecipient(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id)
            ->when(!UserManagementAccess::allows($user, 'marketplace_review'),
                fn (Builder $query) => $query->where('event', '!=', 'marketplace.review.requested'));
    }

    public static function unreadTray(User $user): array
    {
        $unread = self::query()->forRecipient($user)->whereNull('read_at');

        return [
            'unreadCount' => (clone $unread)->count(),
            'unreadNotifications' => $unread->latest()->latest('id')->limit(6)->get(),
        ];
    }

    public static function isLocalTarget(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//')
            && !str_contains($path, chr(92)) && !preg_match('/[\x00-\x1f\x7f]/', $path);
    }

    public static function deliver(int $userId, string $event, string $title, string $message, string $path, ?string $emailTemplate = null): void
    {
        if (! in_array($event,self::EVENTS,true)
            || mb_strlen($title)>180 || mb_strlen($message)>500
            || !self::isLocalTarget($path) || strlen($path)>500) {
            throw new \InvalidArgumentException('Invalid application notification.');
        }

        $notification = self::query()->create([
            'user_id'=>$userId,'event'=>$event,'title'=>$title,
            'message'=>$message,'target_path'=>$path,
        ]);
        EmailOutbox::queue($emailTemplate ?? $event, $userId, 'notification-'.$notification->id, [
            'title' => $title, 'message' => $message,
        ], $path);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AuditEvent extends Model
{
    public $timestamps = false;

    public const EVENTS = [
        'auth.login.success',
        'auth.registration.terms_accepted',
        'auth.login.failed',
        'auth.logout',
        'creator.application.submitted',
        'creator.application.approved',
        'creator.application.rejected',
        'user.role.changed',
        'feature.flag.changed',
        'listing.review.published',
        'listing.review.rejected',
        'support.ticket.created',
        'support.customer.replied',
        'support.staff.replied',
        'support.status.changed',
        'policy.published',
        'policy.read.acknowledged',
        'privacy.request.submitted',
        'privacy.request.inspected',
        'privacy.request.in_review',
        'privacy.request.responded',
        'privacy.request.closed',
        'license.definition.created',
        'license.definition.updated',
        'license.definition.activated',
        'media.uploaded',
        'media.deleted',
        'media.review.approved',
        'media.review.rejected',
        'builder.terms.accepted',
        'product.created',
        'product.updated',
        'resume.updated',
        'job.submitted',
        'job.updated',
        'job.closed',
        'job.review.published',
        'job.review.rejected',
        'job.proposal.submitted',
        'job.proposal.reviewed',
        'job.saved',
        'job.unsaved',
        'inquiry.submitted',
        'inquiry.replied',
        'inquiry.closed',
        'category.created',
        'category.updated',
        'site.setting.changed',
        'contract.draft.created',
        'contract.milestone.created',
        'contract.milestone.removed',
        'contract.offered',
        'contract.accepted',
        'contract.declined',
        'contract.cancelled',
        'contract.milestone.submitted',
        'contract.milestone.accepted',
        'contract.milestone.revision_requested',
        'contract.completion.acknowledged',
        'contract.completed',
        'contract.message.sent',
        'contract.file.downloaded',
        'contract.file.case_inspected',
        'contract.dispute.opened',
        'contract.dispute.inspected',
        'contract.dispute.under_review',
        'contract.dispute.resolved',
        'listing.resubmitted',
        'listing.saved',
        'listing.unsaved',
        'creator.profile.updated',
        'vault.version.uploaded',
        'vault.version.inspected',
        'vault.malware.clean',
        'vault.malware.infected',
        'vault.malware.error',
        'vault.rights.verified',
        'vault.rights.rejected',
        'vault.rights.evidence_updated',
        'vault.version.approved',
        'vault.version.rejected',
        'vault.version.disabled',
        'vault.entitlement.granted',
        'vault.entitlement.revoked',
        'vault.download.served',
        'developer.token.created',
        'developer.token.revoked',
        'developer.api.account.read',
        'developer.api.listings.read',
    ];

    protected $fillable = ['actor_id', 'event', 'ip_fingerprint', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $event, ?User $actor = null, ?Request $request = null): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unrecognized audit event type.');
        }

        $request ??= request();

        // Short-lived daily pseudonymous fingerprint. Do not store raw IPs or request contents.
        $ip = $request->ip();
        $fingerprint = $ip ? substr(hash_hmac('sha256', $ip.'|'.now()->toDateString(), config('app.key')), 0, 16) : null;

        self::query()->create([
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'ip_fingerprint' => $fingerprint,
            'created_at' => now(),
        ]);
    }
}

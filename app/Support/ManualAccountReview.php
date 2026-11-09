<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManualAccountReview
{
    public const METHODS = [
        'account_review'=>'Account information review',
        'contact_confirmation'=>'Direct contact confirmation',
        'video_call'=>'Live video review',
        'document_review'=>'Document review (not stored here)',
        'other'=>'Other documented review',
    ];

    public static function canReview(User $actor, User $target): bool
    {
        if (! in_array($actor->role, ['super_admin', 'admin'], true)
            || $actor->account_status !== 'active' || $actor->is($target)) {
            return false;
        }
        return $actor->role === 'super_admin'
            || in_array($target->role, ['customer', 'subscriber', 'freelancer'], true);
    }

    public static function apply(Request $request, User $target, string $decision, string $method, string $note): void
    {
        $actor = $request->user();
        abort_unless($actor && self::canReview($actor, $target), 403);
        abort_unless(in_array($decision, ['verified','revoked'], true), 422);
        DB::transaction(function () use ($request, $target, $decision, $method, $note, $actor): void {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            abort_unless(self::canReview($actor, $locked),403);
            if ($decision === 'verified') {
                abort_unless($locked->account_status === 'active', 409, 'Only active accounts can be verified.');
                abort_if($locked->account_review_status === 'verified',409, 'Account already manually verified.');
                $locked->account_verified_at = now();
                $locked->account_verified_by = $actor->id;
                $locked->account_review_method = $method;
            } else {
                abort_unless($locked->account_review_status === 'verified',409, 'Only verified accounts may be revoked.');
                $locked->account_verified_at = null;
                $locked->account_verified_by = null;
                $locked->account_review_method = null;
            }
            $locked->account_review_status = $decision;
            $locked->account_review_note = $note;
            $locked->save();
            self::event($locked, $actor, $decision, $method, $note);
            AuditEvent::record(
                $decision === 'verified' ? 'user.account.verified' : 'user.account.revoked',
                $actor, $request
            );
        },3);
    }

    public static function invalidateForChange(Request $request, User $user, string $reason): void
    {
        if ($user->account_review_status !== 'verified') return;
        $user->account_review_status = 'unreviewed';
        $user->account_verified_at = null;
        $user->account_verified_by = null;
        $user->account_review_method = null;
        $user->account_review_note = null;
        self::event($user,$request->user(),'invalidated',null,$reason);
        AuditEvent::record('user.account.invalidated',$request->user(),$request);
    }

    private static function event(User $user, ?User $actor, string $decision, ?string $method, string $note): void
    {
        DB::table('user_account_review_events')->insert([
            'user_id'=>$user->id,'reviewer_id'=>$actor?->id,
            'decision'=>$decision,'method'=>$method,
            'note'=>$note,'created_at'=>now(),
        ]);
    }
}
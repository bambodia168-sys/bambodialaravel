<?php

namespace App\Jobs;

use App\Models\AccountNotification;
use App\Models\PolicyUpdateNotice;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DeliverPolicyNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;
    public int $timeout = 120;

    public function __construct(public int $noticeId, public int $afterUserId = 0)
    {
        $this->onConnection('database')->onQueue('legal');
    }

    public function handle(): void
    {
        $notice = PolicyUpdateNotice::query()->with('policy')->findOrFail($this->noticeId);
        if (! $notice->notify_account && ! $notice->notify_email) {
            return;
        }

        $users = User::query()->where('id', '>', $this->afterUserId)
            ->orderBy('id')->limit(50)->get(['id', 'email']);
        if ($users->isEmpty()) {
            return;
        }

        $path = '/policies/'.$notice->policy->public_code.'-'.$notice->policy->slug;
        foreach ($users as $user) {
            // A per-recipient record makes job retries idempotent for in-app delivery
            // and avoids requeuing a successfully completed mail attempt.
            DB::transaction(function () use ($notice, $user, $path): void {
                $row = DB::table('policy_notice_recipients')
                    ->where('policy_update_notice_id', $notice->id)
                    ->where('user_id', $user->id)->lockForUpdate()->first();

                if ($row === null) {
                    DB::table('policy_notice_recipients')->insert([
                        'policy_update_notice_id' => $notice->id, 'user_id' => $user->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $row = DB::table('policy_notice_recipients')
                        ->where('policy_update_notice_id', $notice->id)
                        ->where('user_id', $user->id)->lockForUpdate()->first();
                }
                if ($notice->notify_account && $row->account_sent_at === null) {
                    AccountNotification::deliver($user->id, 'policy.updated', $notice->headline, $notice->message, $path);
                    DB::table('policy_notice_recipients')->where('id', $row->id)
                        ->update(['account_sent_at' => now(), 'updated_at' => now()]);
                }
            });
            if ($notice->notify_email && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                $pending = DB::table('policy_notice_recipients')
                    ->where('policy_update_notice_id', $notice->id)
                    ->where('user_id', $user->id)->whereNull('email_sent_at')->exists();
                if ($pending) {
                    $link = url($path);
                    // This runs in the database queue worker, never in an admin HTTP request.
                    Mail::raw($notice->message."\n\nRead the updated policy: ".$link.
                        "\n\nPolicy reference: ".$notice->policy->public_code,
                        function ($mail) use ($user, $notice): void {
                            $mail->to($user->email)->subject($notice->headline);
                        });
                    DB::table('policy_notice_recipients')
                        ->where('policy_update_notice_id', $notice->id)
                        ->where('user_id', $user->id)
                        ->update(['email_sent_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        if ($users->count() === 50) {
            self::dispatch($notice->id, (int) $users->last()->id)->onConnection('database')->onQueue('legal');
        }
    }
}

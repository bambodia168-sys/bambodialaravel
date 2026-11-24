<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketCredential;
use App\Support\SupportTicketSecrets;
use App\Support\UserManagementAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupportTicketResourceController extends Controller
{
    public function download(Request $request, string $attachment): BinaryFileResponse
    {
        [$file, $absolute] = $this->privateFile($request, $attachment);
        AuditEvent::record('support.attachment.downloaded', $request->user(), $request);
        $response = response()->download($absolute, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ]);
        return $this->noStore($response);
    }

    public function preview(Request $request, string $attachment): BinaryFileResponse
    {
        [$file, $absolute] = $this->privateFile($request, $attachment);
        abort_unless(in_array($file->mime_type, [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        ], true), 404);

        $response = response()->file($absolute, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ]);
        return $this->noStore($response);
    }

    private function noStore(BinaryFileResponse $response): BinaryFileResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('max-age', 0);
        $response->headers->removeCacheControlDirective('public');
        return $response;
    }

    private function privateFile(Request $request, string $attachment): array
    {
        $file = SupportTicketAttachment::query()->where('public_id', $attachment)
            ->with('ticket')->firstOrFail();
        $ticket = $file->ticket;
        abort_unless($ticket && ! $ticket->trashed()
            && (
                (int) $ticket->user_id === (int) $request->user()->id
                || UserManagementAccess::allows($request->user(), 'support_center')
            ), 404);

        abort_unless($file->storage_disk === 'local' && $file->scan_status === 'clean', 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->storage_path), 404);
        $absolute = $disk->path($file->storage_path);
        abort_unless(is_file($absolute)
            && hash_equals($file->sha256, hash_file('sha256', $absolute)), 409);

        return [$file, $absolute];
    }

    public function credentialStore(Request $request, string $ticket): RedirectResponse
    {
        $record = SupportTicket::query()->where('public_id', $ticket)
            ->where('user_id', $request->user()->id)->firstOrFail();
        abort_if($record->status === 'closed', 403);

        $details = SupportTicketSecrets::fields($request);
        if ($details === null) {
            throw ValidationException::withMessages([
                'credential_label' => 'Enter at least one private credential field.',
            ]);
        }
        DB::transaction(function () use ($record, $request, $details): void {
            $locked = SupportTicket::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status === 'closed', 403);

            $active = $locked->credentials()
                ->whereNull('revoked_at')->where('expires_at', '>', now())->count();
            if ($active >= 3) {
                throw ValidationException::withMessages([
                    'credential_label' => 'Revoke an existing credential share before adding another.',
                ]);
            }
            SupportTicketSecrets::store($locked, $request->user(), $details, $request);
            if ($locked->assigned_to) {
                AccountNotification::deliver(
                    (int) $locked->assigned_to,
                    'support.replied',
                    'Temporary support access shared',
                    'The customer shared time-limited encrypted access on an assigned ticket.',
                    route('dashboard.support.show', $locked->public_id, false)
                );
            }
        });

        return back()->with('status', 'Temporary encrypted access details shared securely.');
    }

    public function credentialReveal(Request $request, string $credential): JsonResponse
    {
        $record = SupportTicketCredential::query()->where('public_id', $credential)
            ->with('ticket')->firstOrFail();
        abort_unless($record->ticket && $record->ticket->status !== 'closed', 410);

        $details = SupportTicketSecrets::reveal($record, $request->user(), $request);

        return response()->json(['details' => $details])
            ->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ]);
    }

    public function credentialRevoke(Request $request, string $credential): RedirectResponse
    {
        $record = SupportTicketCredential::query()->where('public_id', $credential)
            ->with('ticket')->firstOrFail();
        abort_unless($record->ticket && ! $record->ticket->trashed(), 404);
        SupportTicketSecrets::revoke($record, $request->user(), $request);

        return back()->with('status', 'Temporary access revoked; encrypted values erased.');
    }
}

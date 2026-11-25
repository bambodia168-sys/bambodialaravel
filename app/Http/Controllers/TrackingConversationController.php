<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\ServiceInquiry;
use App\Models\ServiceInquiryMessage;
use App\Models\TrackingMessageAttachment;
use App\Models\TrackingOneTimeNote;
use App\Support\ContractArtifact;
use App\Support\SupportMessageContent;
use App\Support\SupportTicketFiles;
use App\Support\VaultMalwareScanner;
use App\Support\VerifiedTrackingOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class TrackingConversationController extends Controller
{
    private const LIMIT = 30;

    public function messages(Request $request, string $reference): JsonResponse
    {
        $order = VerifiedTrackingOrder::resolve($request, $reference);
        $filters = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(isset($filters['after'], $filters['before']), 422, 'Use only one message cursor.');
        $query = $order->serviceInquiry->messages()
            ->with(['sender:id,name', 'trackingAttachments'])
            ->when(isset($filters['after']), fn ($q) => $q->where('id', '>', $filters['after']))
            ->when(isset($filters['before']), fn ($q) => $q->where('id', '<', $filters['before']));
        // New messages advance forward; older messages page backward.
        $records = isset($filters['after'])
            ? $query->orderBy('id')->limit(self::LIMIT)->get()
            : $query->orderByDesc('id')->limit(self::LIMIT)->get()->reverse()->values();
        $first = $records->first()?->id;
        $last = $records->last()?->id;
        $hasMore = isset($filters['after'])
            ? ($last && $order->serviceInquiry->messages()->where('id', '>', $last)->exists())
            : ($first && $order->serviceInquiry->messages()->where('id', '<', $first)->exists());

        return $this->privateJson([
            'html' => $records->map(fn ($message) => $this->renderMessage($message, $order))->implode(''),
            'first_id' => $first, 'last_id' => $last,
            'has_more' => (bool) $hasMore,
            'total' => $order->serviceInquiry->messages()->count(),
        ]);
    }

    public function send(Request $request, string $reference): JsonResponse|RedirectResponse
    {
        $order = VerifiedTrackingOrder::resolve($request, $reference);
        $inquiry = $order->serviceInquiry;
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:40000'],
        ]);
        $files = SupportTicketFiles::validate($request);
        $body = trim((string) ($validated['message'] ?? ''));
        if ($body === '' && $files !== []) {
            $body = '<p>Reference files attached.</p>';
        }
        $safeBody = SupportMessageContent::prepare($body, 2);
        $savedPaths = [];

        try {
            $message = DB::transaction(function () use ($request, $inquiry, $order, $safeBody, $files, &$savedPaths) {
                // Checking the locked inquiry prevents a message from being sent after closure.
                $locked = ServiceInquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
                abort_unless($locked->status === 'open' && $locked->hasVerifiedOrder()
                    && (int) $locked->client_id === (int) $order->buyer_id
                    && (int) $locked->creator_id === (int) $order->seller_id, 409,
                    'This conversation is no longer available.');

                $message = $locked->messages()->create([
                    'sender_id' => $request->user()->id, 'body' => $safeBody,
                    'content_format' => 'html',
                ]);
                foreach ($files as $file) {
                    $this->storeAttachment($file, $order->id, $message, $request, $savedPaths);
                }
                $locked->touch();
                $recipient = (int) $locked->client_id === (int) $request->user()->id
                    ? (int) $locked->creator_id : (int) $locked->client_id;
                AccountNotification::deliver($recipient, 'inquiry.replied', 'New project tracking message',
                    'You have a new private message about an active order.',
                    route('tracking.orders.show', $order->reference(), false));
                AuditEvent::record('inquiry.replied', $request->user(), $request);
                return $message;
            }, 3);
        } catch (Throwable $e) {
            // Database rollback never automatically deletes bytes in private local storage.
            if ($savedPaths !== []) {
                Storage::disk('local')->delete($savedPaths);
            }
            throw $e;
        }

        if ($request->expectsJson()) {
            $message->load(['sender:id,name', 'trackingAttachments']);
            return $this->privateJson([
                'message' => $this->renderMessage($message, $order),
                'id' => $message->id,
                'total' => $inquiry->messages()->count(),
            ], 201);
        }
        // Progressive fallback for clients without JavaScript: no "Message sent" banner.
        return redirect()->to(route('tracking.orders.show', $order->reference()).'#tracking-conversation');
    }

    public function download(Request $request, string $reference, string $attachment): BinaryFileResponse
    {
        $order = VerifiedTrackingOrder::resolve($request, $reference);
        $record = TrackingMessageAttachment::query()
            ->where('marketplace_order_id', $order->id)
            ->where('public_id', $attachment)
            ->whereHas('message', fn ($query) => $query->where('service_inquiry_id', $order->serviceInquiry->id))
            ->firstOrFail();
        abort_unless($record->scan_status === 'clean'
            && preg_match('#^tracking-conversation/'.(int)$order->id.'/[a-f0-9-]{36}$#D', $record->storage_path),
            404);
        $path = ContractArtifact::verifyStoredFile($record->storage_path, $record->sha256, (int) $record->bytes);
        abort_unless($path !== null, 409, 'File integrity verification failed.');
        AuditEvent::record('order.tracking.chat.attachment_downloaded', $request->user(), $request);
        return response()->download($path, $record->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

    public function shareOnce(Request $request, string $reference): JsonResponse|RedirectResponse
    {
        $order = VerifiedTrackingOrder::resolve($request, $reference);
        $data = $request->validate([
            'label' => ['required', 'string', 'min:3', 'max:100'],
            'content' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $note = DB::transaction(function () use ($request, $order, $data) {
            $pending = TrackingOneTimeNote::query()
                ->where('marketplace_order_id', $order->id)
                ->whereNull('opened_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->count();
            abort_if($pending >= 5, 409, 'Maximum five active one-time notes per order.');
            $recipient = (int) $order->buyer_id === (int) $request->user()->id
                ? (int) $order->seller_id : (int) $order->buyer_id;

            $record = TrackingOneTimeNote::query()->create([
                'marketplace_order_id' => $order->id,
                'created_by' => $request->user()->id,
                'recipient_id' => $recipient,
                'label' => trim($data['label']),
                'ciphertext' => Crypt::encryptString($data['content']),
                'expires_at' => now()->addDays(3),
            ]);
            AccountNotification::deliver($recipient, 'inquiry.replied', 'One-time handover available',
                'A private one-time note is available in Project Tracking.',
                route('tracking.orders.show', $order->reference(), false));
            AuditEvent::record('order.tracking.one_time.shared', $request->user(), $request);
            return $record;
        }, 3);

        if ($request->expectsJson()) {
            return $this->privateJson([
                'note' => $this->renderNote($note, $order),
                'id' => $note->public_id,
            ], 201);
        }
        return redirect()->to(route('tracking.orders.show', $order->reference()).'#tracking-one-time');
    }

    public function openOnce(Request $request, string $reference, string $note): JsonResponse
    {
        abort_unless($request->expectsJson(), 406);
        $order = VerifiedTrackingOrder::resolve($request, $reference);
        // Locked, destructive read: only the recipient can use this once.
        $content = DB::transaction(function () use ($request, $order, $note) {
            $record = TrackingOneTimeNote::query()
                ->where('marketplace_order_id', $order->id)
                ->where('public_id', $note)->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->recipient_id === (int) $request->user()->id, 404);
            abort_unless($record->isAvailable(), 410, 'This note has expired or was already opened.');
            $text = Crypt::decryptString($record->ciphertext);
            $record->update(['ciphertext' => null, 'opened_at' => now()]);
            AuditEvent::record('order.tracking.one_time.opened', $request->user(), $request);
            return $text;
        }, 3);

        return $this->privateJson(['content' => $content, 'opened' => true]);
    }

    public function revokeOnce(Request $request, string $reference, string $note): JsonResponse|RedirectResponse
    {
        $order = VerifiedTrackingOrder::resolve($request, $reference);
        DB::transaction(function () use ($request, $order, $note) {
            $record = TrackingOneTimeNote::query()
                ->where('marketplace_order_id', $order->id)
                ->where('public_id', $note)->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->created_by === (int) $request->user()->id, 404);
            abort_unless($record->opened_at === null, 409, 'An opened note cannot be revoked.');
            $record->update(['ciphertext' => null, 'revoked_at' => now()]);
            AuditEvent::record('order.tracking.one_time.revoked', $request->user(), $request);
        }, 3);

        if ($request->expectsJson()) {
            return $this->privateJson(['revoked' => true]);
        }
        return redirect()->to(route('tracking.orders.show', $order->reference()).'#tracking-one-time');
    }

    private function renderMessage(ServiceInquiryMessage $message, $order): string
    {
        return view('tracking.partials.message', compact('message', 'order'))->render();
    }

    private function renderNote(TrackingOneTimeNote $note, $order): string
    {
        return view('tracking.partials.one-time-note', compact('note', 'order'))->render();
    }

    private function storeAttachment(UploadedFile $file, int $orderId, ServiceInquiryMessage $message, Request $request, array &$paths): void
    {
        abort_unless($file->isValid(), 422, 'Attachment upload failed.');
        $source = $file->getRealPath();
        $hash = $source && is_readable($source) ? hash_file('sha256', $source) : false;
        abort_unless(is_string($hash) && strlen($hash) === 64, 422, 'Attachment integrity cannot be verified.');
        $scan = app(VaultMalwareScanner::class)->scanFile($source);
        if (($scan['status'] ?? 'error') !== 'clean') {
            Log::warning('tracking.attachment.screening_failed', [
                'status' => $scan['status'] ?? 'error',
                'bytes' => $file->getSize(), 'mime_type' => $file->getMimeType(),
            ]);
            throw ValidationException::withMessages([
                'attachments' => ($scan['status'] ?? '') === 'infected'
                    ? 'This file was blocked by security scanning.'
                    : 'Attachment scanning is currently unavailable. Please retry later.',
            ]);
        }
        $path = 'tracking-conversation/'.$orderId.'/'.Str::uuid();
        $written = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        abort_unless($written === $path, 500, 'Unable to store attachment privately.');
        $paths[] = $path;
        $stored = Storage::disk('local')->path($path);
        abort_unless(is_file($stored) && is_readable($stored)
            && hash_equals($hash, hash_file('sha256', $stored)), 422,
            'Attachment copy failed integrity verification.');
        $filename = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $filename = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', $filename)), 0, 180);
        TrackingMessageAttachment::query()->create([
            'marketplace_order_id' => $orderId,
            'service_inquiry_message_id' => $message->id,
            'uploaded_by' => $request->user()->id,
            'storage_path' => $path,
            'original_name' => $filename !== '' ? $filename : 'reference-file',
            'mime_type' => (string) $file->getMimeType(),
            'bytes' => $file->getSize(),
            'sha256' => $hash, 'scan_status' => 'clean',
        ]);
        AuditEvent::record('order.tracking.chat.attachment_uploaded', $request->user(), $request);
    }

    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

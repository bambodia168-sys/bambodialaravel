<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\SupportMessage;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupportTicketFiles
{
    public const MAX_COUNT = 4;

    public static function validate(Request $request): array
    {
        $data = $request->validate([
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_COUNT],
            'attachments.*' => [
                'required','file','max:10240',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain',
            ],
        ]);
        $files = $data['attachments'] ?? [];
        $size = array_sum(array_map(fn (UploadedFile $file) => $file->getSize(), $files));
        if ($size > 20 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'Attachments cannot exceed 20 MB per response.',
            ]);
        }
        return $files;
    }

    /**
     * Call within the ticket/message transaction. This method cleans up files
     * from all failed scans or database operations, including partial batches.
     * A scanner must be installed: no production malware-scan bypass.
     */
    public static function store(array $files, SupportMessage $message, User $actor, Request $request): void
    {
        $created = [];
        try {
            foreach ($files as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    throw ValidationException::withMessages(['attachments' => 'Invalid upload.']);
                }
                $name = (string) Str::uuid();
                $path = 'support-ticket-attachments/'.$message->support_ticket_id.'/'.$name;
                $stored = Storage::disk('local')->putFileAs(
                    dirname($path), $file, basename($path)
                );
                if ($stored !== $path) {
                    throw ValidationException::withMessages([
                        'attachments' => 'Unable to save an attachment privately.',
                    ]);
                }
                $created[] = $path;
                $absolute = Storage::disk('local')->path($path);
                $scan = app(VaultMalwareScanner::class)->scanFile($absolute);
                if (($scan['status'] ?? 'error') !== 'clean') {
                    throw ValidationException::withMessages([
                        'attachments' => 'Attachment security screening failed. Remove the file or contact support.',
                    ]);
                }
                $rawName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $safeName = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', $rawName));
                SupportTicketAttachment::query()->create([
                    'support_ticket_id' => $message->support_ticket_id,
                    'support_message_id' => $message->id,
                    'uploaded_by' => $actor->id,
                    'storage_disk' => 'local',
                    'storage_path' => $path,
                    'original_name' => mb_substr($safeName ?: 'reference-file', 0, 180),
                    'mime_type' => (string) $file->getMimeType(),
                    'bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $absolute),
                    'scan_status' => 'clean',
                ]);
                AuditEvent::record('support.attachment.uploaded', $actor, $request);
            }
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($created);
            throw $e;
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\ServiceDelivery;
use App\Models\ServiceInquiry;
use App\Support\ContractArtifact;
use App\Support\ProductVault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ServiceDeliveryController extends Controller
{
    public function requirements(Request $request, ServiceInquiry $inquiry): RedirectResponse
    {
        $this->authorizeWorkspace($request, $inquiry, 'client_id');
        $data = $request->validate(['requirements' => ['required', 'string', 'min:10', 'max:4900']]);

        DB::transaction(function () use ($request, $inquiry, $data): void {
            $locked = $this->lockWorkspace($request, $inquiry, 'client_id');
            abort_unless($locked->deliveryStatus() === 'awaiting_requirements', 409);

            $locked->messages()->create([
                'sender_id' => $request->user()->id,
                'body' => "Order requirements\n\n".$data['requirements'],
            ]);
            $locked->update(['status' => 'open', 'closed_by' => null, 'closed_at' => null, 'delivery_status' => 'in_progress']);
            $this->notify($request, $locked, (int) $locked->creator_id, 'requirements', 'Service requirements received',
                'The buyer submitted the requirements for this order.');
        }, 3);

        return $this->afterAction($request, $inquiry, 'Requirements sent.');
    }

    public function submit(Request $request, ServiceInquiry $inquiry): RedirectResponse
    {
        $this->authorizeWorkspace($request, $inquiry, 'creator_id');
        abort_unless(in_array($inquiry->deliveryStatus(), ['in_progress', 'revision_requested'], true), 409);
        $data = $request->validate([
            'notes' => ['required', 'string', 'min:10', 'max:5000'],
            'artifact' => ['nullable', 'file', 'max:'.ProductVault::MAX_UPLOAD_KB],
        ]);
        $file = $request->file('artifact');
        $metadata = $file ? ContractArtifact::persistIn($file, $this->directory($inquiry)) : [];

        try {
            DB::transaction(function () use ($request, $inquiry, $data, $metadata): void {
                $locked = $this->lockWorkspace($request, $inquiry, 'creator_id');
                abort_unless(in_array($locked->deliveryStatus(), ['in_progress', 'revision_requested'], true), 409);

                $locked->deliveries()->create(['notes' => $data['notes'], ...$metadata]);
                $locked->update(['delivery_status' => 'delivered']);
                $this->notify($request, $locked, (int) $locked->client_id, 'delivered', 'Service delivery ready',
                    'Review the latest delivery and accept it or request a revision.');
            }, 3);
        } catch (Throwable $exception) {
            if (isset($metadata['storage_path'])) {
                Storage::disk('local')->delete($metadata['storage_path']);
            }

            throw $exception;
        }

        return $this->afterAction($request, $inquiry, 'Delivery submitted for buyer review.');
    }

    public function decide(Request $request, ServiceInquiry $inquiry): RedirectResponse
    {
        $this->authorizeWorkspace($request, $inquiry, 'client_id');
        $data = $request->validate([
            'delivery' => ['required', 'integer', 'min:1'],
            'decision' => ['required', Rule::in(['accept', 'revise'])],
            'feedback' => ['required_if:decision,revise', 'nullable', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $inquiry, $data): void {
            $locked = $this->lockWorkspace($request, $inquiry, 'client_id');
            abort_unless($locked->deliveryStatus() === 'delivered', 409);
            $delivery = $locked->deliveries()->latest('id')->firstOrFail();
            abort_unless((int) $delivery->id === (int) $data['delivery'] && $delivery->decision === null, 409,
                'This delivery changed. Reload the workspace to review the latest version.');

            $accepted = $data['decision'] === 'accept';
            if ($accepted && $delivery->storage_path !== null) {
                abort_unless($this->verifiedPath($locked, $delivery) !== null, 409,
                    'The delivery file could not be verified.');
            }

            $state = $accepted ? 'accepted' : 'revision_requested';
            $delivery->update(['decision' => $state, 'feedback' => $data['feedback'] ?? null, 'decided_at' => now()]);
            $locked->update(['delivery_status' => $state]);
            $this->notify($request, $locked, (int) $locked->creator_id, $state,
                $accepted ? 'Service delivery accepted' : 'Service revision requested',
                $accepted ? 'The buyer accepted your delivery.' : 'Read the buyer’s feedback before resubmitting.');
        }, 3);

        return $this->afterAction($request, $inquiry,
            $data['decision'] === 'accept' ? 'Delivery accepted.' : 'Revision requested.');
    }

    public function download(Request $request, ServiceInquiry $inquiry, string $delivery): BinaryFileResponse
    {
        $this->authorizeWorkspace($request, $inquiry);
        $record = $inquiry->deliveries()->whereNotNull('storage_path')->findOrFail($delivery);
        $path = $this->verifiedPath($inquiry, $record);
        abort_unless($path !== null, 409, 'The delivery file could not be verified.');

        AuditEvent::record('commerce.service.file_downloaded', $request->user(), $request);

        return response()->download($path, 'bambodia-delivery-'.$record->id.'.'.$record->file_type, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

    private function afterAction(Request $request, ServiceInquiry $inquiry, string $message): RedirectResponse
    {
        if ($request->boolean('tracking') && $inquiry->hasVerifiedOrder()) {
            return redirect()->route('tracking.orders.show', $inquiry->order->reference())
                ->with('status', $message);
        }

        return redirect()->route('inquiries.show', $inquiry)->with('status', $message);
    }

    private function authorizeWorkspace(Request $request, ServiceInquiry $inquiry, ?string $role = null): void
    {
        $userId = (int) $request->user()->id;
        abort_unless(in_array($userId, [(int) $inquiry->client_id, (int) $inquiry->creator_id], true)
            && $inquiry->hasVerifiedOrder(), 404);
        abort_if($role !== null && $userId !== (int) $inquiry->{$role}, 403);
    }

    private function lockWorkspace(Request $request, ServiceInquiry $inquiry, string $role): ServiceInquiry
    {
        $locked = ServiceInquiry::query()->lockForUpdate()->findOrFail($inquiry->id);
        $this->authorizeWorkspace($request, $locked, $role);

        return $locked;
    }

    private function notify(Request $request, ServiceInquiry $inquiry, int $recipient, string $event, string $title, string $message): void
    {
        $event = 'commerce.service.'.$event;
        AccountNotification::deliver($recipient, $event, $title, $message, route('inquiries.show', $inquiry, false));
        AuditEvent::record($event, $request->user(), $request);
    }

    private function directory(ServiceInquiry $inquiry): string
    {
        return 'marketplace/service-deliverables/'.$inquiry->id;
    }

    private function verifiedPath(ServiceInquiry $inquiry, ServiceDelivery $delivery): ?string
    {
        $directory = preg_quote($this->directory($inquiry), '#');
        if (! $delivery->sha256 || ! $delivery->byte_size || ! in_array($delivery->file_type, ProductVault::ALLOWED_TYPES, true)
            || ! preg_match('#^'.$directory.'/[a-f0-9]{64}\\.'.$delivery->file_type.'$#D', (string) $delivery->storage_path)) {
            return null;
        }

        return ContractArtifact::verifyStoredFile($delivery->storage_path, $delivery->sha256, (int) $delivery->byte_size);
    }
}

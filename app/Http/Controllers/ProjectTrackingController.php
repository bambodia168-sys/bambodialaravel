<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ServiceInquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ProjectTrackingController extends Controller
{
    private const TYPES = ['service', 'project', 'freelance'];
    private const TASK_STATUSES = ['planned', 'in_progress', 'completed'];

    public function creatorIndex(Request $request): View
    {
        return $this->index($request, 'creator');
    }

    public function customerIndex(Request $request): View
    {
        return $this->index($request, 'account');
    }

    public function index(Request $request, string $workspace): View
    {
        abort_unless(config('commerce.enabled'), 404);
        abort_unless(in_array($workspace, ['creator', 'account'], true), 404);
        abort_if($workspace === 'creator' && ! $request->user()->isCreator(), 403);

        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['all', ...self::TYPES])],
            'status' => ['nullable', Rule::in(['all', 'awaiting_requirements', 'in_progress', 'delivered',
                'revision_requested', 'accepted'])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $type = $filters['type'] ?? 'all';
        $status = $filters['status'] ?? 'all';
        $search = trim($filters['q'] ?? '');
        $ownerKey = $workspace === 'creator' ? 'seller_id' : 'buyer_id';
        $orders = MarketplaceOrder::query()->where($ownerKey, $request->user()->id)
            ->where('status', 'completed')
            ->whereHas('paidAttempt')
            ->whereIn('marketplace_listing_id', MarketplaceListing::query()->select('id')
                ->whereIn('type', self::TYPES)
                ->when($type !== 'all', fn (Builder $query) => $query->where('type', $type)))
            ->whereHas('serviceInquiry', fn (Builder $query) => $query
                ->when($status !== 'all', fn (Builder $filter) => $filter->where('delivery_status', $status)))
            ->with(['serviceInquiry', 'paidAttempt'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->matchingReference($search)->orWhere('listing_title', 'like', '%'.$search.'%')))
            ->latest('id')->paginate(12)->withQueryString();
        $listingTypes = MarketplaceListing::query()
            ->whereIn('id', $orders->getCollection()->pluck('marketplace_listing_id')->all())
            ->pluck('type', 'id');

        return view('tracking.index', compact('orders', 'workspace', 'status', 'type', 'search', 'listingTypes'));
    }

    public function show(Request $request, string $reference): Response
    {
        $order = $this->verifiedOrder($request, $reference);
        $inquiry = $order->serviceInquiry;
        $inquiry->load(['client:id,name', 'creator:id,name']);
        $messages = $inquiry->messages()->with('sender:id,name')
            ->oldest('id')->paginate(15, ['*'], 'messages');
        $deliveries = $inquiry->deliveries()->latest('id')
            ->paginate(8, ['*'], 'deliveries');
        $currentDelivery = $inquiry->deliveries()->latest('id')->first();
        $tasks = DB::table('order_tracking_tasks')
            ->where('marketplace_order_id', $order->id)->orderBy('position')->get();
        $extensions = DB::table('order_tracking_extension_requests')
            ->where('marketplace_order_id', $order->id)->latest('id')->limit(10)->get();
        $pendingExtension = $extensions->firstWhere('status', 'pending');
        $targetDate = $extensions->firstWhere('status', 'approved')?->proposed_due_on;
        $completed = $tasks->where('status', 'completed')->count();
        $deliveryStatus = $inquiry->deliveryStatus();
        $progress = match ($deliveryStatus) {
            'awaiting_requirements' => 10,
            'in_progress' => $tasks->count() ? 35 + (int) round(35 * $completed / $tasks->count()) : 45,
            'delivered' => 85,
            'revision_requested' => 65,
            'accepted' => 100,
            default => 0,
        };

        $timeline = collect([[
            'time' => $order->completed_at, 'title' => 'Verified order opened',
            'description' => 'The private delivery workspace became available.', 'type' => 'order',
        ]]);
        foreach ($tasks as $task) {
            $timeline->push([
                'time' => $task->status === 'completed' ? $task->completed_at : $task->created_at,
                'title' => $task->status === 'completed' ? 'Task completed' : 'Task planned',
                'description' => $task->title, 'type' => 'task',
            ]);
        }
        foreach ($inquiry->messages()->with('sender:id,name')->latest('id')->limit(30)->get() as $message) {
            $timeline->push([
                'time' => $message->created_at,
                'title' => str_starts_with($message->body, "Order requirements\n\n")
                    ? 'Requirements submitted' : 'Message from '.($message->sender?->name ?? 'participant'),
                'description' => str_starts_with($message->body, "Order requirements\n\n")
                    ? 'Buyer supplied the project brief.' : 'Private conversation updated.',
                'type' => 'message',
            ]);
        }
        foreach ($inquiry->deliveries()->latest('id')->limit(20)->get() as $delivery) {
            $timeline->push(['time' => $delivery->created_at,
                'title' => 'Deliverable submitted', 'description' => 'A delivery is ready for customer review.',
                'type' => 'delivery']);
            if ($delivery->decided_at) {
                $timeline->push(['time' => $delivery->decided_at,
                    'title' => $delivery->decision === 'accepted' ? 'Delivery accepted' : 'Changes requested',
                    'description' => 'Customer decision recorded.', 'type' => 'review']);
            }
        }
        foreach ($extensions as $extension) {
            $timeline->push(['time' => $extension->created_at,
                'title' => 'Schedule change requested', 'description' => 'Proposed date: '.$extension->proposed_due_on,
                'type' => 'extension']);
            if ($extension->responded_at) {
                $timeline->push(['time' => $extension->responded_at,
                    'title' => $extension->status === 'approved' ? 'Schedule change approved' : 'Schedule change declined',
                    'description' => 'Decision logged by the other participant.', 'type' => 'extension']);
            }
        }
        $timeline = $timeline->filter(fn (array $entry) => $entry['time'] !== null)
            ->sortByDesc('time')->take(24)->values();

        $creatorView = (int) $request->user()->id === (int) $order->seller_id;
        $listingType = MarketplaceListing::query()->whereKey($order->marketplace_listing_id)->value('type');
        return response()->view('tracking.show', compact(
            'order', 'inquiry', 'messages', 'deliveries', 'currentDelivery',
            'tasks', 'extensions', 'pendingExtension', 'targetDate', 'completed',
            'deliveryStatus', 'progress', 'timeline', 'creatorView', 'listingType'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function addTask(Request $request, string $reference): RedirectResponse
    {
        $order = $this->verifiedOrder($request, $reference, sellerOnly: true);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:180'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addYear()->toDateString()],
        ]);
        DB::transaction(function () use ($request, $order, $data) {
            $order = $this->lockOrder($request, $order->id, sellerOnly: true);
            abort_if($order->serviceInquiry->deliveryStatus() === 'accepted', 409, 'This delivery is completed.');
            $last = (int) DB::table('order_tracking_tasks')->where('marketplace_order_id', $order->id)->max('position');
            abort_if($last >= 20, 409, 'A maximum of twenty tracking tasks is supported.');
            DB::table('order_tracking_tasks')->insert([
                'marketplace_order_id' => $order->id, 'created_by' => $request->user()->id,
                'title' => $data['title'], 'due_on' => $data['due_on'] ?? null,
                'position' => $last + 1, 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->notify($request, $order, 'task_added', 'New tracking task',
                'Your project workspace has a new task.');
        }, 3);
        return $this->back($order, 'Tracking task added.');
    }

    public function updateTask(Request $request, string $reference, int $task): RedirectResponse
    {
        $order = $this->verifiedOrder($request, $reference, sellerOnly: true);
        $data = $request->validate(['status' => ['required', Rule::in(self::TASK_STATUSES)]]);
        DB::transaction(function () use ($request, $order, $task, $data) {
            $locked = $this->lockOrder($request, $order->id, sellerOnly: true);
            abort_if(in_array($locked->serviceInquiry->deliveryStatus(), ['accepted', 'delivered'], true), 409,
                'This delivery is under review or already accepted.');
            $record = DB::table('order_tracking_tasks')->where('marketplace_order_id', $locked->id)
                ->where('id', $task)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            DB::table('order_tracking_tasks')->where('id', $record->id)->update([
                'status' => $data['status'], 'completed_at' => $data['status'] === 'completed' ? now() : null,
                'updated_at' => now(),
            ]);
            $this->notify($request, $locked, 'task_updated', 'Tracking task updated',
                'The creator updated project checklist progress.');
        }, 3);
        return $this->back($order, 'Task progress updated.');
    }

    public function requestExtension(Request $request, string $reference): RedirectResponse
    {
        $order = $this->verifiedOrder($request, $reference);
        $data = $request->validate([
            'proposed_due_on' => ['required', 'date', 'after:today', 'before_or_equal:'.now()->addYear()->toDateString()],
            'reason' => ['required', 'string', 'min:20', 'max:2500'],
        ]);
        DB::transaction(function () use ($order, $request, $data) {
            $locked = $this->lockOrder($request, $order->id);
            abort_if($locked->serviceInquiry->deliveryStatus() === 'accepted', 409,
                'Completed delivery dates cannot be changed.');
            abort_if(DB::table('order_tracking_extension_requests')
                ->where('marketplace_order_id', $locked->id)->where('status', 'pending')->exists(),
                409, 'Another schedule request is awaiting a decision.');
            DB::table('order_tracking_extension_requests')->insert([
                'marketplace_order_id' => $locked->id, 'requested_by' => $request->user()->id,
                'proposed_due_on' => $data['proposed_due_on'], 'reason' => $data['reason'],
                'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->notify($request, $locked, 'schedule_requested', 'Project schedule change requested',
                'The other participant requested a new tracking target date.');
        }, 3);
        return $this->back($order, 'Schedule change requested. The other party must approve it.');
    }

    public function decideExtension(Request $request, string $reference, int $extension): RedirectResponse
    {
        $order = $this->verifiedOrder($request, $reference);
        $values = $request->validate(['decision' => ['required', Rule::in(['approved', 'declined'])]]);
        DB::transaction(function () use ($request, $order, $extension, $values) {
            $locked = $this->lockOrder($request, $order->id);
            abort_if($locked->serviceInquiry->deliveryStatus() === 'accepted', 409);
            $record = DB::table('order_tracking_extension_requests')
                ->where('marketplace_order_id', $locked->id)->where('id', $extension)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            abort_unless($record->status === 'pending', 409);
            abort_if((int) $record->requested_by === (int) $request->user()->id, 403,
                'Your own request needs a decision by the other participant.');
            DB::table('order_tracking_extension_requests')->where('id', $record->id)->update([
                'status' => $values['decision'], 'responded_by' => $request->user()->id,
                'responded_at' => now(), 'updated_at' => now(),
            ]);
            $this->notify($request, $locked, 'schedule_'.$values['decision'],
                'Project schedule request '.$values['decision'],
                'The other participant has answered your tracking schedule request.');
        }, 3);
        return $this->back($order, 'Schedule decision recorded.');
    }

    private function verifiedOrder(Request $request, string $reference, bool $sellerOnly = false): MarketplaceOrder
    {
        abort_unless(config('commerce.enabled'), 404);
        $userId = (int) $request->user()->id;
        $order = MarketplaceOrder::query()
            ->where(fn (Builder $query) => $query->where('seller_id', $userId)
                ->orWhere('buyer_id', $userId))
            ->matchingReference($reference)
            ->where('status', 'completed')->whereHas('paidAttempt')
            ->whereIn('marketplace_listing_id', MarketplaceListing::query()
                ->select('id')->whereIn('type', self::TYPES))
            ->with('serviceInquiry')->firstOrFail();
        abort_unless($order->serviceInquiry
            && (int) $order->serviceInquiry->client_id === (int) $order->buyer_id
            && (int) $order->serviceInquiry->creator_id === (int) $order->seller_id
            && (int) $order->serviceInquiry->marketplace_listing_id === (int) $order->marketplace_listing_id
            && $order->serviceInquiry->hasVerifiedOrder(), 404);
        abort_if($sellerOnly && (int) $order->seller_id !== $userId, 403);
        return $order;
    }

    private function lockOrder(Request $request, int $id, bool $sellerOnly = false): MarketplaceOrder
    {
        $order = MarketplaceOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        return $this->verifiedOrder($request, $order->reference(), $sellerOnly);
    }

    private function notify(Request $request, MarketplaceOrder $order, string $event, string $title, string $body): void
    {
        $recipient = (int) $order->buyer_id === (int) $request->user()->id
            ? (int) $order->seller_id : (int) $order->buyer_id;
        AccountNotification::deliver($recipient, 'order.tracking.'.$event, $title, $body,
            route('tracking.orders.show', $order->reference(), false));
        AuditEvent::record('order.tracking.'.$event, $request->user(), $request);
    }

    private function back(MarketplaceOrder $order, string $message): RedirectResponse
    {
        return redirect()->route('tracking.orders.show', $order->reference())
            ->with('status', $message);
    }
}

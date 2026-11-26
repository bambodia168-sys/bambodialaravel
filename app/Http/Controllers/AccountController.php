<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\SavedJob;
use App\Models\ServiceInquiry;
use App\Support\CreatorStudioAnalytics;
use App\Support\ManualAccountReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $id = $request->user()->public_id;
        if ($request->server('QUERY_STRING', '') !== '='.$id) {
            return redirect('/account?='.$id);
        }

        $user=$request->user();
        $notifications = AccountNotification::query()->forRecipient($user);
        $stats=[
            'saved'=>SavedListing::query()->where('user_id',$user->id)->count(),
            'saved_jobs'=>SavedJob::query()->where('user_id',$user->id)->count(),
            'unread'=>(clone $notifications)->whereNull('read_at')->count(),
            'contracts'=>FreelanceContract::query()->where(function($query)use($user){
                $query->where('client_id',$user->id)->orWhere(function($q)use($user){
                    $q->where('freelancer_id',$user->id)->where('status','!=','draft');
                });
            })->whereIn('status',['offered','active','disputed'])->count(),
        ];
        if(in_array($user->role,['customer','subscriber','super_admin'],true)){
            $stats['jobs']=MarketplaceJob::query()->where('client_id',$user->id)->count();
        }
        if($user->role==='freelancer'){
            $stats['proposals']=JobProposal::query()->where('freelancer_id',$user->id)->count();
        }
        if($user->isCreator()){
            $stats['listings']=MarketplaceListing::query()->inCreatorStudio()->where('user_id',$user->id)->count();
            $stats['client_inquiries']=ServiceInquiry::query()->where('creator_id',$user->id)->where('status','open')->count();
        }
        $activity=$notifications->latest()->latest('id')->limit(5)->get();

        // Only agreements in which this account participates appear in the overview.
        // A freelancer cannot see the counterparty's private draft.
        $agreements=FreelanceContract::query()->where(function($query)use($user){
            $query->where('client_id',$user->id)
                ->orWhere(function($other)use($user){
                    $other->where('freelancer_id',$user->id)->where('status','!=','draft');
                });
        })->whereIn('status',['offered','active','disputed'])
          ->latest('updated_at')->limit(4)->get(['id','public_id','title','status','updated_at']);

        // Account overview only: never include seller orders or other buyers' records.
        $orderSummary = null;
        $recentOrders = collect();
        if (config('commerce.enabled')) {
            $orders = \App\Models\MarketplaceOrder::query()->where('buyer_id', $user->id);
            $orderSummary = array_fill_keys(array_keys(\App\Models\MarketplaceOrder::STATUSES), 0);
            $counts = (clone $orders)->select('status', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('status')->pluck('aggregate', 'status');
            foreach ($orderSummary as $status => $count) {
                $orderSummary[$status] = (int) ($counts[$status] ?? 0);
            }
            $recentOrders = $orders->latest('id')->limit(5)
                ->get(['id', 'number', 'public_reference', 'listing_title', 'status', 'currency', 'total', 'created_at']);
        }

        // Action centre reads current account-owned records; it never executes a payment or changes state.
        $accountActions = [];
        $supportReplies = collect();
        if ($orderSummary !== null && $orderSummary['awaiting_payment'] > 0) {
            $pending = (clone $orders)->where('status', 'awaiting_payment')->latest('id')->first();
            if ($pending) {
                $count = $orderSummary['awaiting_payment'];
                $accountActions[] = [
                    'icon'=>'shopping-cart', 'tone'=>'attention', 'title'=>$count === 1 ? 'Payment pending' : 'Payments pending',
                    'description'=>$pending->listing_title.' · '.$pending->currency.' '.$pending->total
                        .($count > 1 ? ' · '.($count - 1).' more unpaid order(s)' : ''),
                    'href'=>$count === 1 ? route('account.orders.show', $pending->reference()) : route('account.orders.index', ['status'=>'awaiting_payment']),
                    'label'=>$count === 1 ? 'Review payment' : 'Review orders',
                ];
            }
        }

        // Only unread support replies to this user's currently open tickets warrant a dedicated prompt.
        $replyCandidates = (clone $notifications)->reorder()->whereNull('read_at')->where('event', 'support.replied')
            ->latest()->latest('id')->limit(30)->get();
        $ticketIds = $replyCandidates->map(function ($notice) {
            return preg_match('~^/(?:[a-z]{2}/)?(?:account/support-tickets|studio/support-tickets|dashboard/support-tickets|support-center)/([0-9]{9})(?:[?#].*)?$~D', $notice->target_path, $match)
                ? $match[1] : null;
        })->filter()->unique()->values();
        $openTickets = \App\Models\SupportTicket::query()->where('user_id', $user->id)
            ->whereIn('status', ['open','pending','in_progress'])->whereIn('public_id', $ticketIds)
            ->get(['public_id','subject'])->keyBy('public_id');
        $seenTickets = [];
        foreach ($replyCandidates as $notice) {
            if (!preg_match('~^/(?:[a-z]{2}/)?(?:account/support-tickets|studio/support-tickets|dashboard/support-tickets|support-center)/([0-9]{9})(?:[?#].*)?$~D', $notice->target_path, $match)) {
                continue;
            }
            $ticket = $openTickets->get($match[1]);
            if (!$ticket || isset($seenTickets[$match[1]])) {
                continue;
            }
            $seenTickets[$match[1]] = true;
            $supportReplies->push(['notice'=>$notice,'subject'=>$ticket->subject]);
            if ($supportReplies->count() >= 3) {
                break;
            }
        }

        $milestoneCount = \App\Models\ContractMilestone::query()->where(function ($query) use ($user) {
            $query->where(fn ($q) => $q->where('status','submitted')->whereHas('contract',
                fn ($contract) => $contract->where('client_id',$user->id)->where('status','active')))
                ->orWhere(fn ($q) => $q->where('status','revision_requested')->whereHas('contract',
                    fn ($contract) => $contract->where('freelancer_id',$user->id)->where('status','active')));
        })->count();
        if ($milestoneCount > 0) {
            $accountActions[] = ['icon'=>'file-check','tone'=>'attention','title'=>'Contract milestones need attention',
                'description'=>number_format($milestoneCount).' milestone(s) awaiting your review or revision.',
                'href'=>route('contracts.index', ['status'=>'active']),'label'=>'Review contracts'];
        }

        if (in_array($user->role,['customer','subscriber','freelancer'],true)) {
            $downloadCount = \App\Models\ProductEntitlement::query()->where('user_id',$user->id)
                ->where('status','active')->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at','>',now()))
                ->whereHas('version', fn ($q) => $q->where('status','approved')->whereHas('product',
                    fn ($product) => $product->where('type','product')->where('status','published')))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('product_download_events')
                    ->whereColumn('product_download_events.product_entitlement_id','product_entitlements.id')
                    ->whereColumn('product_download_events.user_id','product_entitlements.user_id'))
                ->count();
            if ($downloadCount > 0) {
                $accountActions[] = ['icon'=>'download','tone'=>'info','title'=>'New download entitlements',
                    'description'=>number_format($downloadCount).' active entitlement(s) with no recorded download. Availability is checked in My Downloads.',
                    'href'=>route('account.downloads'),'label'=>'Review downloads'];
            }
            $supportExpiryCount = \App\Models\ProductLicense::query()->where('user_id',$user->id)
                ->where('status','active')->whereNull('revoked_at')
                ->whereHas('entitlement', fn ($q) => $q->where('status','active')->whereNull('revoked_at'))
                ->whereNotNull('support_term')->where('support_term','!=','lifetime')
                ->whereNotNull('support_expires_at')->where('support_expires_at','<=',now()->addDays(30))
                ->count();
            if ($supportExpiryCount > 0) {
                $accountActions[] = ['icon'=>'key-round','tone'=>'info','title'=>'Product support renewal',
                    'description'=>number_format($supportExpiryCount).' support term(s) expired or expiring within 30 days. Your installation license is separate.',
                    'href'=>route('account.licenses.index'),'label'=>'Review support terms'];
            }
        }

        if ($orderSummary !== null && !$user->hasVerifiedEmail()) {
            $accountActions[] = ['icon'=>'shield-check','tone'=>'info','title'=>'Verify your email address',
                'description'=>'Confirm your email address to continue using verified checkout.',
                'href'=>route('checkout.verification'),'label'=>'Verify email'];
        }

        // Unread notifications not individually presented here remain accessible in the notification centre.
        $otherUnread = max(0, $stats['unread'] - $supportReplies->count());
        if ($otherUnread > 0) {
            $accountActions[] = ['icon'=>'bell','tone'=>'info','title'=>'Unread account updates',
                'description'=>number_format($otherUnread).' other unread update(s) to review.',
                'href'=>route('account.notifications', ['filter'=>'unread']),'label'=>'View updates'];
        }

        $creatorApplicationStatus = in_array($user->role,['customer','subscriber'],true)
            ? \App\Models\CreatorApplication::query()->where('user_id',$user->id)->value('status') : null;

        return view('account.index', compact('user','stats','activity','agreements','orderSummary','recentOrders',
            'accountActions','supportReplies','creatorApplicationStatus'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'locale' => ['required', Rule::in(['en', 'zh', 'km', 'ko', 'ja', 'th', 'vi', 'de'])],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $member=\App\Models\User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if ($member->name !== $data['name']) {
                ManualAccountReview::invalidateForChange($request,$member,'Account holder changed their public name; new review required.');
            }
            $member->fill($data);
            $member->save();
        });

        return redirect('/account?='.$request->user()->public_id)
            ->with('status', 'Your profile was updated.');
    }

    public function studio(Request $request): Response|RedirectResponse
    {
        abort_unless($request->user()->isCreator(), 403);
        $id = $request->user()->public_id;
        // A dedicated Creator workspace; existing /studio links remain valid.
        if (! $request->routeIs('creator', 'localized.*.creator')
            && $request->server('QUERY_STRING', '') !== '='.$id) {
            return redirect('/studio?='.$id);
        }

        $user=$request->user();
        $stats=[
            'published'=>MarketplaceListing::query()->publiclyAvailable()->where('user_id',$user->id)
                ->where('type','!=','product')->count(),
            'pending'=>MarketplaceListing::query()->where('user_id',$user->id)
                ->where('type','!=','product')->where('status','pending')->count(),
            'inquiries'=>ServiceInquiry::query()->where('creator_id',$user->id)->where('status','open')->count(),
        ];
        $stats['products']=MarketplaceListing::query()->inCreatorStudio()->where('user_id',$user->id)
            ->where('type','product')->count();
        $stats['drafts']=MarketplaceListing::query()->where('user_id',$user->id)
            ->where('type','!=','product')->whereIn('status',['draft','rejected'])->count();
        $stats['product_drafts']=MarketplaceListing::query()->where('user_id',$user->id)
            ->where('type','product')->whereIn('status',['draft','rejected'])->count();
        $stats['product_pending']=MarketplaceListing::query()->where('user_id',$user->id)
            ->where('type','product')->where('status','pending')->count();
        $recentListings=MarketplaceListing::query()->inCreatorStudio()->where('user_id',$user->id)
            ->latest()->orderByDesc('id')->limit(5)->get(['id','public_id','type','title','status','updated_at']);
        $openInquiries=ServiceInquiry::query()->where('creator_id',$user->id)
            ->where('status','open')->latest('updated_at')->limit(4)
            ->get(['id','subject','updated_at']);

        $analytics = app(CreatorStudioAnalytics::class)->report((int) $user->id);

        return response()->view('account.studio',compact('user','stats','recentListings','openInquiries','analytics'))
            ->header('Cache-Control', 'private, no-store');
    }
}

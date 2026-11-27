<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ContractDispute;
use App\Models\CreatorApplication;
use App\Models\FreelanceContract;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\AdminDashboardFilters;
use App\Support\MarketplaceManagement;
use App\Support\UserManagementAccess;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $user = request()->user();
        $isStaff = $user->role === 'staff';
        $filters = in_array($user->role, ['admin', 'super_admin'], true) ? AdminDashboardFilters::fromRequest(request()) : null;
        $dated = fn ($query) => $filters ? $filters->dated($query) : $query;
        $listings = fn ($query) => $filters ? $filters->listings($query) : $query;
        $adminTrends = [];
        $periodPending = 0;
        $canSupport = UserManagementAccess::allows($user, 'support_center');
        $canReview = UserManagementAccess::allows($user, 'marketplace_review');
        $canViewMarketplace = MarketplaceManagement::mayView($user);
        $canCommerce = UserManagementAccess::allows($user, 'commerce_orders');
        $stats = [];
        $queues = [];
        $reports = [];
        $shortcuts = [];
        $recent = collect();
        $recentSupport = collect();

        if ($canSupport) {
            $support = $dated(SupportTicket::query())->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')->pluck('total', 'status');
            $queues[] = ['label' => 'Unresolved support tickets',
                'count' => $filters ? SupportTicket::query()->whereIn('status', ['open','in_progress'])->count() : (int) ($support['open'] ?? 0) + (int) ($support['in_progress'] ?? 0),
                'route' => 'dashboard.support', 'icon' => 'life-buoy'];
            $reports[] = ['title'=>'Support ticket status','description'=>$filters ? 'Tickets created in the selected period, by current status (all categories).' : 'Current ticket counts by status.',
                'icon'=>'life-buoy','rows'=>collect(SupportTicket::STATUSES)->map(fn ($label,$key)=>[
                    'label'=>$label,'count'=>(int)($support[$key] ?? 0),'href'=>route('dashboard.support',['status'=>$key])])->values()->all()];
            $recentSupport = SupportTicket::query()->whereIn('status', ['open','in_progress'])
                ->latest('updated_at')->limit(5)->get(['id','public_id','subject','status','updated_at']);
        }

        if (! $isStaff) {
            $stats = [
                'totalUsers' => $dated(User::query())->count(),
                'creators' => $dated(User::query())->where('role', 'freelancer')->count(),
                'customers' => $dated(User::query())->whereIn('role', ['customer', 'subscriber'])->count(),
            ];
            $queues[] = ['label'=>'Pending creator applications',
                'count'=>CreatorApplication::query()->where('status','pending')->count(),
                'route'=>'dashboard.creator-applications','icon'=>'user-round-check'];
            $queues[] = ['label'=>'Unresolved contract disputes',
                'count'=>ContractDispute::query()->whereIn('status',['open','under_review'])->count(),
                'route'=>'dashboard.contracts.disputes','icon'=>'shield'];
            $contractCounts = $dated(FreelanceContract::query())->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')->pluck('total','status');
            $reports[] = ['title'=>'Contract lifecycle','description'=>$filters ? 'Agreements created in the selected period, by current status (all categories).' : 'Current agreements by recorded status.','icon'=>'file-check',
                'rows'=>collect(['draft'=>'Draft','offered'=>'Offered','active'=>'Active','disputed'=>'Disputed','completed'=>'Completed','declined'=>'Declined','cancelled'=>'Cancelled'])->map(fn ($label,$key)=>[
                    'label'=>$label,'count'=>(int)($contractCounts[$key] ?? 0),'href'=>route('dashboard.contracts.index',['status'=>$key])])->values()->all()];
            $applicationCounts = $dated(CreatorApplication::query())->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')->pluck('total','status');
            $reports[] = ['title'=>'Creator applications','description'=>$filters ? 'Applications created in the selected period, by current status (all categories).' : 'Recorded applications by decision status.','icon'=>'user-round-check',
                'rows'=>collect(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'])->map(fn ($label,$key)=>[
                    'label'=>$label,'count'=>(int)($applicationCounts[$key] ?? 0),'href'=>route('dashboard.creator-applications',['status'=>$key])])->values()->all()];
            $shortcuts = [
                ['Account Verification','dashboard.account-verifications.index','shield-check'],
                ['Contracts','dashboard.contracts.index','file-check'],
                ['Media Library','dashboard.media.index','image'],
                ['License Manager','dashboard.licenses.index','key-round'],
                ['Service Inquiries','dashboard.inquiries.index','message'],
                ['Notification Analytics','dashboard.notifications.index','bell'],
                ['Appearance','dashboard.appearance','palette'],
                ['Page Manager','dashboard.pages','file-text'],
                ['Post Manager','dashboard.posts','newspaper'],
                ['Policy Center','dashboard.policies','scale'],
                ['Knowledge Base','dashboard.knowledge.index','book-open'],
                ['Digital File Vault','dashboard.vault.index','archive'],
            ];
            if ($user->role === 'super_admin') {
                array_push($shortcuts,
                    ['User Manager','dashboard.users','users'],
                    ['Security Center','security.index','shield-check'],
                    ['Feature Manager','dashboard.features','sliders-horizontal'],
                    ['Cloud Storage','dashboard.cloud-storage.index','archive'],
                    ['SMTP','dashboard.smtp.index','mail'],
                    ['Social Auth','dashboard.social-auth.index','key-round']);
                if ($canCommerce) {
                    $shortcuts[] = ['Commerce Operations','dashboard.commerce-operations','wallet'];
                    $shortcuts[] = ['Payment Methods','dashboard.payment-methods.index','credit-card'];
                }
            }
        }

        if ($canReview) {
            $queues[] = ['label'=>'All listings awaiting review',
                'count'=>MarketplaceListing::query()->whereIn('status',MarketplaceManagement::REVIEW_STATUSES)->count(),
                'route'=>'dashboard.reviews','icon'=>'layers'];
            $queues[] = ['label'=>'Digital products awaiting review',
                'count'=>MarketplaceListing::query()->whereIn('status',MarketplaceManagement::REVIEW_STATUSES)->where('type','product')->count(),
                'route'=>'dashboard.products.index','params'=>['status'=>'review_queue'],'icon'=>'package'];
            $queues[] = ['label'=>'Job posts awaiting review',
                'count'=>MarketplaceJob::query()->whereIn('status',MarketplaceManagement::REVIEW_STATUSES)->count(),
                'route'=>'dashboard.jobs.index','params'=>['status'=>'review_queue'],'icon'=>'briefcase'];
            $pending = $listings(MarketplaceListing::query())->whereIn('status',MarketplaceManagement::REVIEW_STATUSES);
            $periodPending = (clone $pending)->count();
            $recent = $pending
                ->latest()->limit(5)->get(['id','type','title','status','created_at']);
            $shortcuts[] = ['Review Queue','dashboard.reviews','clipboard-check'];
        }

        if ($canViewMarketplace) {
            $counts = $listings(MarketplaceListing::query())->selectRaw('type, COUNT(*) as total')
                ->groupBy('type')->pluck('total','type');
            $rows = [];
            foreach (['product'=>'Digital products','project'=>'Projects','freelance'=>'Freelance','service'=>'Services'] as $key=>$label) {
                if ($filters && $filters->type !== 'all' && $filters->type !== $key) continue;
                $rows[] = ['label'=>$label,'count'=>(int)($counts[$key] ?? 0),
                    'href'=>MarketplaceManagement::indexUrl($key)];
            }
            if (! $filters || $filters->type === 'all') {
                $rows[] = ['label'=>'Job posts','count'=>$dated(MarketplaceJob::query())->count(),'href'=>route('dashboard.jobs.index')];
            }
            $reports[] = ['title'=>'Marketplace inventory','description'=>$filters ? 'Records created in the selected period; jobs included only under All.' : 'All recorded listings by type, plus job posts.','icon'=>'package','rows'=>$rows];
        }
        if ($canCommerce) {
            $counts = ($filters ? $filters->orders(MarketplaceOrder::query()) : MarketplaceOrder::query())->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')->pluck('total','status');
            $reports[] = ['title'=>'Marketplace orders','description'=>$filters ? 'Orders created in the selected period, by current status and linked listing type; not revenue.' : 'Order records by status, not verified revenue.','icon'=>'shopping-cart',
                'rows'=>collect(MarketplaceOrder::STATUSES)->map(fn ($label,$key)=>[
                    'label'=>$label,'count'=>(int)($counts[$key] ?? 0),'href'=>route('dashboard.orders.index',['status'=>$key])])->values()->all()];
            $shortcuts[] = ['Marketplace Orders','dashboard.orders.index','shopping-cart'];
        }
        if ($canSupport) {
            $shortcuts[] = ['Support Management','dashboard.support','inbox'];
        }
        if (UserManagementAccess::allows($user,'badge_awards')) {
            $shortcuts[] = ['Creator Badges','dashboard.badges.index','award'];
        }
        if ($canViewMarketplace) {
            $shortcuts[] = ['Digital Products','dashboard.products.index','package'];
            $shortcuts[] = ['Job Manager','dashboard.jobs.index','briefcase'];
        }
        $shortcuts[] = ['My Support Tickets','dashboard.support-tickets.index','message'];
        $shortcuts[] = ['Account Security','account.security','shield-check'];

        if ($filters) {
            $adminTrends[] = $filters->trend('Account registrations', 'Accounts registered in this period (all categories).', 'users', $dated(User::query()));
            if ($canViewMarketplace) {
                $adminTrends[] = $filters->trend('Marketplace submissions', 'Listing creation dates, selected type, all statuses.', 'layers', $listings(MarketplaceListing::query()));
            }
            if ($canCommerce) {
                $adminTrends[] = $filters->trend('Order activity', 'Orders created in this period, selected listing type, all statuses.', 'shopping-cart', $filters->orders(MarketplaceOrder::query()));
            }
        }

        return view('dashboard.index', compact('isStaff','stats','queues','reports','shortcuts',
            'recent','recentSupport','canReview','canSupport','filters','adminTrends','periodPending'));
    }
}

<?php

namespace App\View\Components;

use App\Models\AccountNotification;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\MarketplaceManagement;
use App\Support\UserManagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\Component;
use Illuminate\View\View;

class RoleDashboardTrends extends Component
{
    public function __construct(public string $scope) {}

    public function render(): View
    {
        $user = request()->user();
        abort_unless($user && $user->account_status === 'active', 403);
        $today = now()->startOfDay();
        $start = $today->copy()->subDays(83);
        $end = $today->copy()->addDay();
        $series = [];
        $add = function (string $title, string $description, string $icon, Builder $query) use (&$series, $start, $end): void {
            $daily = $query->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
                ->groupByRaw('DATE(created_at)')->pluck('total', 'day');
            $weeks = [];
            for ($week = 0; $week < 12; $week++) {
                $day = $start->copy()->addDays($week * 7);
                $total = 0;
                for ($offset = 0; $offset < 7; $offset++) {
                    $total += (int) ($daily[$day->copy()->addDays($offset)->toDateString()] ?? 0);
                }
                $weeks[] = ['label' => $day->format('d M'), 'count' => $total];
            }
            $series[] = compact('title', 'description', 'icon', 'weeks');
        };

        if ($this->scope === 'account') {
            if (config('commerce.enabled')) {
                $add('Order activity', 'Orders created by your account, regardless of payment status.', 'shopping-cart',
                    MarketplaceOrder::query()->where('buyer_id', $user->id));
            }
            $add('Account updates', 'Notifications received by your account.', 'bell',
                AccountNotification::query()->forRecipient($user));
        } elseif ($this->scope === 'admin') {
            abort_unless($user->isAdministrator(), 403);
            if ($user->role !== 'staff') {
                $add('Account registrations', 'Currently recorded accounts by registration date.', 'users', User::query());
            }
            if (MarketplaceManagement::mayView($user)) {
                $add('Marketplace submissions', 'Currently recorded listings by creation date, all statuses.', 'layers', MarketplaceListing::query());
            }
            if ($user->role === 'staff' && UserManagementAccess::allows($user, 'support_center')) {
                $add('Support ticket activity', 'Currently recorded tickets by creation date.', 'life-buoy', SupportTicket::query());
            }
            if ($user->role === 'staff' && UserManagementAccess::allows($user, 'commerce_orders')) {
                $add('Order activity', 'Marketplace order records by creation date, all statuses.', 'shopping-cart', MarketplaceOrder::query());
            }
        } elseif ($this->scope === 'developer') {
            abort_unless($user->role === 'super_admin', 403);
            $add('Credential issuance', 'API credentials issued by your account.', 'key-round',
                ApiToken::query()->where('user_id', $user->id));
            $add('Recorded API activity', 'Recorded API read events for your account; not all network requests.', 'activity',
                AuditEvent::query()->where('actor_id', $user->id)->whereIn('event', ['developer.api.account.read', 'developer.api.listings.read']));
        } else {
            abort(404);
        }

        return view('components.role-dashboard-trends', compact('series'));
    }
}

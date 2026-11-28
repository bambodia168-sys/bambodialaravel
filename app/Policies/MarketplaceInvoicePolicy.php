<?php

namespace App\Policies;

use App\Models\MarketplaceInvoice;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class MarketplaceInvoicePolicy
{
    public static function enabled(): bool
    {
        return config('commerce.enabled') === true && config('invoices.enabled') === true;
    }

    public static function writesEnabled(): bool
    {
        return self::enabled() && config('invoices.draft_writes_enabled') === true;
    }

    public function viewAny(User $user): bool
    {
        return self::enabled() && $user->account_status === 'active';
    }

    public function studio(User $user): bool
    {
        return $this->viewAny($user) && $user->isCreator();
    }

    public function create(User $user, MarketplaceOrder $order): bool
    {
        return self::writesEnabled() && $this->studio($user)
            && (int)$order->seller_id === (int)$user->id && (int)$order->buyer_id !== (int)$user->id;
    }

    public function view(User $user, MarketplaceInvoice $invoice): bool
    {
        return $this->viewAny($user) && (
            ($user->isCreator() && (int)$invoice->issuer_id === (int)$user->id)
            || ((int)$invoice->recipient_id === (int)$user->id && in_array($invoice->status,['issued','void'],true))
        );
    }

    public function update(User $user, MarketplaceInvoice $invoice): bool
    {
        return self::writesEnabled() && $this->studio($user) && (int)$invoice->issuer_id === (int)$user->id;
    }

    public function scoped(User $user, bool $studio): Builder
    {
        abort_unless($studio ? $this->studio($user) : $this->viewAny($user),404);
        $query=MarketplaceInvoice::query()->whereHas('order',fn(Builder $q)=>$q
            ->whereColumn('marketplace_orders.seller_id','marketplace_invoices.issuer_id')
            ->whereColumn('marketplace_orders.buyer_id','marketplace_invoices.recipient_id'));
        return $studio ? $query->where('issuer_id',$user->id)
            : $query->where('recipient_id',$user->id)->whereIn('status',['issued','void']);
    }
}

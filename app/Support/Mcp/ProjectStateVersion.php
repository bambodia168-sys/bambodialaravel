<?php

namespace App\Support\Mcp;

use Illuminate\Support\Facades\DB;

/** Opaque state tokens, not raw contract text or consent digests. */
final class ProjectStateVersion
{
    public static function digest(string $purpose,array $state): string
    {
        return hash_hmac('sha256',$purpose.':'.json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)config('app.key'));
    }

    public static function tracking(int $id,int $inquiryId,bool $lock=false): string
    {
        $query=fn(string $table)=>DB::table($table)->when($lock,fn($q)=>$q->lockForUpdate());
        $order=$query('marketplace_orders')->where('id',$id)->first(['id','buyer_id','seller_id','marketplace_listing_id','status','updated_at']);
        $inquiry=$query('service_inquiries')->where('id',$inquiryId)->first(['id','marketplace_order_id','marketplace_listing_id','client_id','creator_id','status','delivery_status','updated_at']);
        $payments=$query('commerce_payments')->where('marketplace_order_id',$id)->orderBy('id')->get(['id','status','live_mode','settlement_reference','updated_at'])->all();
        $tasks=$query('order_tracking_tasks')->where('marketplace_order_id',$id)->orderBy('id')->get(['id','title','status','position','due_on','completed_at','updated_at'])->all();
        $last=$query('service_inquiry_messages')->where('service_inquiry_id',$inquiryId)->orderByDesc('id')->first(['id']);
        return self::digest('tracking-v1',[$order,$inquiry,$payments,$tasks,$last]);
    }

    public static function contract(int $id,bool $lock=false): string
    {
        $query=fn(string $table)=>DB::table($table)->when($lock,fn($q)=>$q->lockForUpdate());
        $contract=$query('freelance_contracts')->where('id',$id)->first(['id','client_id','freelancer_id','job_proposal_id',
            'title','scope','terms','terms_sha256','status','currency','agreed_budget','updated_at']);
        $milestones=$query('contract_milestones')->where('freelance_contract_id',$id)->orderBy('id')
            ->get(['id','sequence','title','description','amount','due_on','status','accepted_at','updated_at'])->all();
        return self::digest('contract-v1',[$contract,$milestones]);
    }
}

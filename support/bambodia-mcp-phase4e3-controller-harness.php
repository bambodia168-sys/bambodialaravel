<?php
namespace App\Support\Mcp {
    class OperationalReadTools {
        public static function definitions(): array { return array_map(fn($n)=>['name'=>$n],['bambodia_accounts_summary','bambodia_orders_summary','bambodia_review_queue','bambodia_support_summary','bambodia_notifications_summary','bambodia_email_delivery_summary']); }
    }
    class AdvancedContentTools {
        public static function definitions(bool $w): array {
            return array_map(fn($n)=>['name'=>$n],array_merge(['bambodia_categories','bambodia_content_summary','bambodia_media_search','bambodia_draft_validate','bambodia_draft_preview'],$w?['bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata']:[]));
        }
    }
    class CommerceReadTools {
        public const NAMES=['bambodia_orders_list','bambodia_order_get','bambodia_transactions_summary','bambodia_receipt_get','bambodia_licenses_list','bambodia_license_get'];
        public static function definitions(): array { return array_map(fn($n)=>['name'=>$n],self::NAMES); }
        public function call($name,$args,$owner,$read): array { return ['called'=>$name,'read'=>$read]; }
    }
    class ProjectReadTools {
        public const NAMES=['bambodia_tracking_list','bambodia_tracking_get','bambodia_contracts_list','bambodia_milestones_list'];
        public static function definitions(): array { return array_map(fn($n)=>['name'=>$n],self::NAMES); }
        public function call($name,$args,$owner,$read): array { return ['called'=>$name,'read'=>$read]; }
    }
    class ProjectWriteTools {
        public const NAMES=['bambodia_tracking_task_create','bambodia_tracking_task_update','bambodia_contract_milestone_create','bambodia_tracking_comment_create'];
        public static function definitions(bool $w): array { return $w?array_map(fn($n)=>['name'=>$n],self::NAMES):[]; }
        public function call($name,$args,$owner,$read,$write): array { return ['called'=>$name,'read'=>$read,'write'=>$write]; }
    }
    class InvoiceReadTools {
        public const NAMES=['bambodia_invoices_list','bambodia_invoice_get','bambodia_invoices_summary','bambodia_invoice_source_preview'];
        public static function definitions(): array { return \config('invoices.enabled') && \config('commerce.enabled')?array_map(fn($n)=>['name'=>$n],self::NAMES):[]; }
        public function call($name,$args,$owner,$read): array { return ['called'=>$name,'read'=>$read]; }
    }
    class InvoiceWriteTools {
        public const NAMES=['bambodia_invoice_draft_create','bambodia_invoice_draft_update','bambodia_invoice_draft_discard'];
        public static function definitions(bool $w): array { return $w && \config('commerce.enabled') && \config('invoices.enabled') && \config('invoices.draft_writes_enabled')?array_map(fn($n)=>['name'=>$n],self::NAMES):[]; }
        public function call($name,$args,$owner,$read,$write): array { return ['called'=>$name,'read'=>$read,'write'=>$write]; }
    }
}
namespace {
    $enabled=true;$invoice=false;$commerce=true;$draft=false;
    function config($name,$default=false) { global $enabled,$invoice,$commerce,$draft; return match($name){'invoices.enabled'=>$invoice,'commerce.enabled'=>$commerce,'invoices.draft_writes_enabled'=>$draft,default=>$enabled}; }
    function app($class) { return new $class; }
    class FixtureGrant {
        public function __construct(private bool $read,private bool $write) {}
        public function allows($scope): bool { return $scope==='mcp.read'?$this->read:($scope==='mcp.write'&&$this->write); }
    }
    function verify($ok,$why) { if (!$ok) throw new \RuntimeException($why); }
    require $argv[1];
    eval(str_replace('class FixtureController {','class CandidateController {',substr(file_get_contents($argv[2]),5)));
    $old=new FixtureController;$new=new CandidateController;
    foreach([true,false] as $enabled) foreach([true,false] as $invoice) foreach([true,false] as $commerce) foreach([true,false] as $draft) {
        $a=array_column($old->list(),null,'name');$b=array_column($new->list(),null,'name');
        $base=($enabled?38:25)+($invoice&&$commerce?4:0);$added=$enabled&&$invoice&&$commerce&&$draft?3:0;
        verify(count($a)===$base && count($b)===$base+$added && count($b)===count($new->list()),'Registry count');
        foreach($a as $n=>$d) verify(($b[$n]??null)===$d,'Existing definition changed');
        foreach(\App\Support\Mcp\InvoiceWriteTools::NAMES as $n) {
            if($added) {
                verify($b[$n]['securitySchemes']===[['type'=>'oauth2','scopes'=>['mcp:use','mcp.write']]],'WRITE scope');
                verify($b[$n]['_meta']['securitySchemes']===$b[$n]['securitySchemes'],'WRITE meta');
            } else verify(!isset($b[$n]),'Disabled discovery');
            verify(($new->invoke($n,new FixtureGrant(true,false))['isError']??false)===true,'Missing WRITE scope');
            if(!$enabled) verify(($new->invoke($n,new FixtureGrant(true,true))['isError']??false)===true,'Disabled global WRITE');
            else foreach([true,false] as $read) verify($new->invoke($n,new FixtureGrant($read,true))===['called'=>$n,'read'=>$read,'write'=>true],'Scope dispatch');
        }
        foreach(['bambodia_create_draft','bambodia_update_draft','bambodia_delete_draft','bambodia_update_own_profile','bambodia_manage_site',
            'bambodia_draft_media','bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata',...\App\Support\Mcp\ProjectWriteTools::NAMES] as $n) {
            verify(($new->invoke($n,new FixtureGrant(true,false))['isError']??false)===true,'Legacy WRITE gate');
            if(!$enabled) verify(($new->invoke($n,new FixtureGrant(true,true))['isError']??false)===true,'Legacy disabled gate');
        }
    }
    echo "PASS: 38/42/45 and 25/29 synthetic inventory, unchanged prior definitions and WRITE gates\n";
}

<?php
namespace App\Support\Mcp {
    class AdvancedContentTools {
        public static function definitions(bool $enabled): array {
            $reads = ['bambodia_categories','bambodia_content_summary','bambodia_media_search','bambodia_draft_validate','bambodia_draft_preview'];
            $writes = ['bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata'];
            return array_map(fn($n)=>['name'=>$n], $enabled ? array_merge($reads,$writes) : $reads);
        }
        public function call($name,$args,$owner,$read,$write): array {
            return ['called'=>$name,'read'=>$read,'write'=>$write];
        }
    }
    class OperationalReadTools {
        public static function definitions(): array {
            return array_map(fn($n)=>['name'=>$n],['bambodia_accounts_summary','bambodia_orders_summary','bambodia_review_queue','bambodia_support_summary','bambodia_notifications_summary','bambodia_email_delivery_summary']);
        }
        public function call(...$args): array { return ['operational'=>true]; }
    }
}
namespace {
    $enabled=true;
    function config($name,$default=false) { global $enabled; return $enabled; }
    function app($class) { return new $class; }
    class FixtureGrant {
        public function __construct(private bool $write) {}
        public function allows($scope): bool { return $scope==='mcp.read' || ($scope==='mcp.write' && $this->write); }
    }
    require $argv[1];
    function verify($pass,$message): void { if (!$pass) throw new \RuntimeException($message); }
    $c = new FixtureController;
    $read = new FixtureGrant(false);
    $write = new FixtureGrant(true);
    $all = $c->list();
    verify(count($all)===24 && count(array_unique(array_column($all,'name')))===24,'Registry count');
    foreach ($all as $d) {
        $n=$d['name'];
        $w=in_array($n,['bambodia_create_draft','bambodia_update_draft','bambodia_delete_draft','bambodia_update_own_profile','bambodia_manage_site','bambodia_draft_media','bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata'],true);
        verify($d['securitySchemes'][0]['scopes']===($w?['mcp:use','mcp.write']:['mcp:use']),'Scope metadata mismatch: '.$n);
        verify($d['_meta']['securitySchemes']===$d['securitySchemes'],'Security metadata mismatch');
    }
    foreach (['bambodia_draft_duplicate','bambodia_content_metadata','bambodia_media_metadata'] as $n) {
        verify(($c->invoke($n,$read)['isError']??false)===true,'Missing scope allowed');
        verify(isset($c->invoke($n,$read)['_meta']['mcp/www_authenticate']),'Missing scope challenge');
        verify(($c->invoke($n,$write)['called']??null)===$n,'Authorized write dispatch');
    }
    foreach (['bambodia_categories','bambodia_content_summary','bambodia_media_search','bambodia_draft_validate','bambodia_draft_preview'] as $n) {
        verify($c->invoke($n,$read)===['called'=>$n,'read'=>true,'write'=>false],'READ dispatch');
    }
    verify($c->invoke('bambodia_overview',$read)===['existing'=>true],'Existing tool');
    verify($c->invoke('bambodia_accounts_summary',$read)===['operational'=>true],'Phase 2A tool');
    $enabled=false;
    verify(count($c->list())===15,'Disabled write discovery');
    verify(($c->invoke('bambodia_draft_duplicate',$write)['isError']??false)===true,'Disabled write gate');
    echo "PASS: synthetic PHP controller dispatch and scope metadata\n";
}

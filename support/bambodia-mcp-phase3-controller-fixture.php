<?php
    class FixtureController {
    public function invoke(string $name, FixtureGrant $token): array { return $this->callTool($name,$token); }
    public function list(): array { return $this->authorizeDefinitions($this->definitions(config('mcp.production_writes_enabled',false))); }
    private function callTool($name,$token): array {
        // bambodia_api_owner bambodia_api_token
        $owner = new \stdClass;
        $args = [];
        $service = new class { public function overview(): array { return ['existing'=>true]; } };
        $writeNames = ['bambodia_create_draft','bambodia_update_draft','bambodia_delete_draft','bambodia_update_own_profile','bambodia_manage_site','bambodia_draft_media'];
        if (in_array($name,$writeNames,true) &&
            (! $token->allows('mcp.write') || ! config('mcp.production_writes_enabled', false))) {
            return ['isError'=>true,'_meta'=>['mcp/www_authenticate'=>['Bearer error="insufficient_scope"']]];
        }
        return match ($name) {
            'bambodia_accounts_summary' => app(\App\Support\Mcp\OperationalReadTools::class)->call($name,$args,$owner,$token->allows('mcp.read')),
            'bambodia_overview' => $service->overview(),
            default => ['existing'=>true],
        };
    }
    private function definitions(bool $includeWrites): array {
        $base = array_map(fn($n)=>['name'=>$n],['bambodia_overview','bambodia_list','bambodia_get']);
        $base = [...$base, ...\App\Support\Mcp\OperationalReadTools::definitions()];
        $base[] = [
            'name' => 'bambodia_analytics',
        ];
        if ($includeWrites) {
            foreach (['bambodia_create_draft','bambodia_update_draft','bambodia_delete_draft','bambodia_update_own_profile','bambodia_manage_site','bambodia_draft_media'] as $n) $base[]=['name'=>$n];
        }
        return $base;
    }
    private function authorizeDefinitions(array $definitions): array {
        $writes = [
            'bambodia_create_draft', 'bambodia_update_draft',
            'bambodia_delete_draft', 'bambodia_update_own_profile',
            'bambodia_manage_site', 'bambodia_draft_media',
        ];
        foreach ($definitions as &$definition) {
            $scopes = in_array($definition['name'],$writes,true) ? ['mcp:use','mcp.write'] : ['mcp:use'];
            $definition['securitySchemes'] = [['type'=>'oauth2','scopes'=>$scopes]];
            $definition['_meta'] = ['securitySchemes'=>$definition['securitySchemes']];
        }
        return $definitions;
    }
    }

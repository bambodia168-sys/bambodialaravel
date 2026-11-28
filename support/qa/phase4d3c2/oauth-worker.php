<?php
require __DIR__.'/guard.php';
phase4d3c2Environment();
require getcwd().'/vendor/autoload.php';
$app=require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $e): void { fwrite(STDERR,"OAUTH_WORKER_FAILED ".get_class($e).PHP_EOL); exit(1); });
phase4d3c2Database();
config(['commerce.enabled'=>true,'mcp.production_enabled'=>true,'mcp.production_writes_enabled'=>true,
    'mail.default'=>'array','queue.default'=>'sync','mcp.exact_redirect_uris'=>['https://chatgpt.com/connector/oauth/qa-callback']]);
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout=20');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$allowed=['bambodia_tracking_task_create','bambodia_tracking_task_update','bambodia_contract_milestone_create','bambodia_tracking_comment_create'];
if (!in_array($input['tool'],$allowed,true)) throw new RuntimeException('UNEXPECTED_TOOL');
echo 'READY '.(int)\Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id.PHP_EOL; flush();
$request=\Illuminate\Http\Request::create('https://bambodia.com/mcp','POST',[],[],[],[
    'HTTP_AUTHORIZATION'=>'Bearer '.$input['bearer'],'CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json',
],json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$input['tool'],'arguments'=>$input['args']]],JSON_THROW_ON_ERROR));
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$response=$kernel->handle($request);
$body=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
if ($response->getStatusCode()!==200 || !isset($body['result']['isError'])) throw new RuntimeException('UNEXPECTED_RPC_RESPONSE');
$grant=$request->attributes->get('bambodia_api_token');
$actor=$request->attributes->get('bambodia_api_owner');
if (!$grant instanceof \App\Support\Mcp\McpAccessGrant || !$actor instanceof \App\Models\User
    || (string)$actor->id!==(string)$input['actor'] || !$grant->allows('mcp.read') || !$grant->allows('mcp.write'))
    throw new RuntimeException('OAUTH_WORKER_IDENTITY_MISMATCH');
$result=$body['result'];
if ($result['isError']) {
    $safe=['kind'=>'rejected','stale'=>str_contains(json_encode($result,JSON_THROW_ON_ERROR),'Project state changed')];
} else {
    if (!is_array($result['structuredContent']??null)) throw new RuntimeException('MISSING_RECEIPT');
    $safe=['kind'=>'ok','receipt'=>$result['structuredContent']];
}
$kernel->terminate($request,$response);
echo 'RESULT '.json_encode($safe,JSON_THROW_ON_ERROR).PHP_EOL;

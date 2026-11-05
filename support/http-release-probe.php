<?php

/**
 * Post-install HTTP/TLS security smoke probe for a separately deployed staging
 * domain. CLI only, never use on production without operator authorization.
 *
 * php support/http-release-probe.php --url=https://staging.example.test --strict --json
 *
 * This checks public and unauthenticated routes; authenticated private-file
 * upload/download and manual device acceptance require separate tests.
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli'){ http_response_code(404); exit(1); }
if(!extension_loaded('curl')){ fwrite(STDERR,"BLOCKED: PHP curl is required.\n"); exit(2); }

$base='';
$strict=in_array('--strict',$argv,true);
$json=in_array('--json',$argv,true);
foreach($argv as $arg)if(str_starts_with($arg,'--url='))$base=rtrim(substr($arg,6),'/');

if(!filter_var($base,FILTER_VALIDATE_URL) || !str_starts_with($base,'https://')
    || parse_url($base,PHP_URL_USER)!==null || parse_url($base,PHP_URL_PASS)!==null
    || parse_url($base,PHP_URL_QUERY)!==null || parse_url($base,PHP_URL_FRAGMENT)!==null){
    fwrite(STDERR,"BLOCKED: supply an HTTPS staging origin using --url=https://host.\n");
    exit(2);
}
$tests=[
    ['id'=>'public-home','path'=>'/','accepted'=>[200]],
    ['id'=>'public-login','path'=>'/login','accepted'=>[200]],
    ['id'=>'public-search','path'=>'/search','accepted'=>[200]],
    ['id'=>'public-jobs','path'=>'/jobs','accepted'=>[200]],
    ['id'=>'installer-retired','path'=>'/installer','accepted'=>[404]],
    ['id'=>'env-not-public','path'=>'/.env','accepted'=>[403,404]],
    ['id'=>'private-files-not-public','path'=>'/storage/app/private/marketplace/originals/','accepted'=>[403,404]],
    ['id'=>'vendor-not-public','path'=>'/vendor/autoload.php','accepted'=>[403,404]],
    ['id'=>'diagnostics-not-public','path'=>'/support/hosting-preflight.php','accepted'=>[403,404]],
    ['id'=>'guest-downloads-restricted','path'=>'/account/downloads','accepted'=>[302,401,403]],
    ['id'=>'guest-file-restricted','path'=>'/account/downloads/1/file','accepted'=>[302,401,403,404]],
];
$results=[];
foreach($tests as $test){
    $ch=curl_init($base.$test['path']);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'BambodiaStagingReadOnlyProbe/1.0',
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_HTTPHEADER=>['Cache-Control: no-cache'],
    ]);
    $body=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $error=$body===false?curl_error($ch):null;
    curl_close($ch);
    $success=$error===null && in_array($code,$test['accepted'],true);
    $results[]=[
        'check'=>$test['id'],'path'=>$test['path'],
        'status'=>$success?'pass':'block','http_status'=>$code,
        'connection_error'=>$error!==null?'TLS/transport error':null,
    ];
}
$blocked=count(array_filter($results,fn($row)=>$row['status']==='block'));
$report=[
    'project'=>'Bambodia',
    'check'=>'Post-install HTTPS unauthenticated staging probe',
    'timestamp_utc'=>gmdate('c'),
    'origin'=>$base,
    'status'=>$blocked?'blocked':'public_http_checks_passed_private_flows_untested',
    'blockers'=>$blocked,'results'=>$results,
    'limitations'=>[
        'No login credentials or private file bytes are transmitted by this probe.',
        'Manual rights review, scanner signature verification and authenticated delivery acceptance are separate gates.',
        'This does not constitute independent aaPanel/cPanel customer acceptance.',
    ],
];
if($json){
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}else{
    echo $report['status'].PHP_EOL;
    foreach($results as $row){
        echo '['.strtoupper($row['status']).'] '.$row['check'].' HTTP '.$row['http_status'].PHP_EOL;
    }
    echo 'Blockers: '.$blocked.PHP_EOL;
}
exit($strict && $blocked?2:0);

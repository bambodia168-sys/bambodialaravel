<?php

/**
 * Bambodia shared-hosting release preflight.
 *
 * CLI only, read-only. Do not publish this script under public/.
 * Run: php support/hosting-preflight.php --webroot=/actual/document/root --json --strict
 *
 * --strict exits nonzero if the host cannot safely accept private originals.
 * This is NOT proof of a successful real upload, antivirus scan, license review,
 * customer download, or real mobile device test.
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit(1);
}

$root=dirname(__DIR__);
$json=in_array('--json',$argv,true);
$strict=in_array('--strict',$argv,true);
$webrootArg=null;
foreach($argv as $arg){
    if(str_starts_with($arg,'--webroot='))$webrootArg=substr($arg,10);
}

$items=[];
$check=static function(string $id,bool $ok,string $detail)use(&$items): void {
    $items[]=['id'=>$id,'status'=>$ok?'pass':'block','detail'=>$detail];
};
$note=static function(string $id,string $detail)use(&$items): void {
    $items[]=['id'=>$id,'status'=>'manual','detail'=>$detail];
};
$readEnv=static function(string $name)use($root): ?string {
    $value=getenv($name);
    if($value!==false && $value!=='')return $value;
    $file=$root.'/.env';
    if(!is_readable($file))return null;
    foreach(file($file,FILE_IGNORE_NEW_LINES)?:[] as $line){
        if(preg_match('/^\s*'.preg_quote($name,'/').'\s*=\s*(.*)$/',$line,$m)){
            return trim(trim($m[1]),'"\'');
        }
    }
    return null;
};
$parseSize=static function(string $value): int {
    $value=trim($value);
    if($value==='-1')return PHP_INT_MAX;
    if(!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i',$value,$m))return 0;
    return (int)round((float)$m[1]*match(strtoupper($m[2])){
        'K'=>1024,'M'=>1048576,'G'=>1073741824,default=>1,
    });
};

$check('php',PHP_VERSION_ID>=80300,'PHP 8.3 or newer required; installed '.PHP_VERSION);
foreach(['mbstring','pdo_mysql','fileinfo','zip','openssl','curl','xml'] as $extension){
    $check('php-ext-'.$extension,extension_loaded($extension),
        'Required PHP extension: '.$extension);
}
$check('process-runner',function_exists('proc_open') && function_exists('proc_close'),
    'ClamAV screening requires PHP process execution. Verify process execution under the actual PHP-FPM pool.');
$check('file-upload-limit',$parseSize((string)ini_get('upload_max_filesize'))>=25*1048576,
    'PHP upload_max_filesize must be at least 25 MiB.');
$check('post-body-limit',$parseSize((string)ini_get('post_max_size'))>=26*1048576,
    'PHP post_max_size must be at least 26 MiB to allow upload overhead.');

$expectedRoot=realpath($root.'/public');
$actualRoot=$webrootArg!==null?realpath($webrootArg):null;
$check('web-document-root',$expectedRoot!==false && $actualRoot!==false
    && $actualRoot===$expectedRoot,
    'Explicit --webroot must resolve to the Laravel public/ directory, not the project root.');
$private=$root.'/storage/app/private';
$privateReal=realpath($private);
$check('private-storage-root',$privateReal!==false && is_dir($privateReal) && !is_link($private)
    && $expectedRoot!==false && !str_starts_with($privateReal,$expectedRoot.DIRECTORY_SEPARATOR),
    'Private originals must live in a real private directory outside public/.');
$check('private-storage-write',$privateReal!==false && is_writable($privateReal),
    'The PHP worker must be able to write private originals; confirm ownership separately for PHP-FPM.');
$check('framework-storage-write',is_writable($root.'/storage/framework'),
    'The application must be able to write storage/framework/.');
$check('bootstrap-cache-write',is_writable($root.'/bootstrap/cache'),
    'The application must be able to write bootstrap/cache/.');

$env=$readEnv('APP_ENV');
$debug=$readEnv('APP_DEBUG');
$check('app-production',$env==='production','Installed deployment must use APP_ENV=production.');
$check('app-debug',$debug!==null && in_array(strtolower($debug),['false','0'],true),
    'APP_DEBUG must be false.');
$check('installer-retired',is_file($root.'/storage/app/installed.lock')
    && !is_file($root.'/storage/app/installer-access.txt'),
    'A finished installation must have installed.lock and no installer access code.');

$binary=$readEnv('VAULT_CLAMSCAN_BINARY')??'/usr/bin/clamscan';
$binaryReady=str_starts_with($binary,'/') && is_file($binary) && !is_link($binary) && is_executable($binary);
$check('clamav-executable',$binaryReady,'Real ClamAV binary must be installed and executable by the web process.');
if($binaryReady && function_exists('proc_open')){
    $process=proc_open([$binary,'--version'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(is_resource($process)){
        fclose($pipes[0]);
        $version=trim(stream_get_contents($pipes[1])); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code=proc_close($process);
        $parts=explode('/',$version);
        $age=isset($parts[2])?strtotime(trim($parts[2])):false;
        $maxAge=(int)($readEnv('VAULT_MAX_DEFINITION_AGE_DAYS')??7);
        $maxAge=max(1,min(30,$maxAge));
        $definitionsOkay=$code===0 && str_starts_with($version,'ClamAV ')
            && isset($parts[1]) && ctype_digit(trim($parts[1]))
            && $age!==false && $age>=time()-($maxAge*86400)
            && $age<=time()+86400;
        $check('clamav-signatures',$definitionsOkay,
            $definitionsOkay?'ClamAV engine and signature date verified.' :
            'Signature database missing, too old or cannot be verified.');
    }else $check('clamav-signatures',false,'Unable to execute ClamAV for signature inspection.');
}else $check('clamav-signatures',false,'ClamAV engine/signature inspection unavailable.');

$note('nginx-apache-upload','Verify nginx client_max_body_size / Apache LimitRequestBody and PHP-FPM request size on a real POST, not just CLI php.ini.');
$note('web-tls','Independently confirm valid HTTPS, application redirects, trusted proxy handling and headers on the actual domain.');
$note('files-mysql','Run real upload/review/download and the fresh-install/upgrade MySQL acceptance against isolated staging databases.');
$note('rights-scan','Review each downloadable file rights evidence independently; run the ClamAV EICAR/clean smoke acceptance and inspect scanner logs.');
$note('device-testing','Run physical iPhone, Android and tablet checks; browser emulation does not count as physical-device acceptance.');

$blocked=count(array_filter($items,fn($row)=>$row['status']==='block'));
$report=[
    'project'=>'Bambodia','check'=>'aaPanel/cPanel hosting preflight',
    'timestamp_utc'=>gmdate('c'),'php'=>PHP_VERSION,
    'status'=>$blocked?'blocked':'preflight_passed_manual_checks_outstanding',
    'blockers'=>$blocked,'results'=>$items,
];

if($json){
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}else{
    echo 'Bambodia hosting preflight: '.$report['status'].PHP_EOL;
    foreach($items as $item){
        echo '['.strtoupper($item['status']).'] '.$item['id'].' — '.$item['detail'].PHP_EOL;
    }
    echo 'Blockers: '.$blocked.PHP_EOL;
}
exit($strict && $blocked?2:0);

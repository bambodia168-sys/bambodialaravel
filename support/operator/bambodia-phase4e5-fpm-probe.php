<?php
/**
 * Prepared only: nonpublic, one-request FastCGI configuration probe.
 * Never install in public/ or invoke without separately approved operator plan.
 * Does not bootstrap providers, dispatch routes, access the DB or print secrets.
 */
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','0');
function report(array $checks,int $status): never {
    while(ob_get_level()>0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['probe'=>'bambodia-fpm-config-v1','checks'=>$checks,'pass'=>$status===200],JSON_THROW_ON_ERROR);
    exit;
}
$dir=__DIR__;
$root='/www/wwwroot/bambodia-staging-qa';
$control=$dir.'/expectation.json';
if(PHP_SAPI!=='fpm-fcgi' || !preg_match('#^/var/tmp/bambodia-fpm-review\.[A-Za-z0-9]+$#D',$dir)
    || realpath($dir)!==$dir || is_link($dir) || fileowner($dir)!==0 || (fileperms($dir)&0777)!==0750
    || is_link(__FILE__) || fileowner(__FILE__)!==0 || (fileperms(__FILE__)&0777)!==0640
    || !is_file($control) || is_link($control) || fileowner($control)!==0 || (fileperms($control)&0777)!==0640
    || ($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__) report([],404);
ob_start();
try {
    $e=json_decode(file_get_contents($control),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($e) || !is_int($e['expires_at']??null) || $e['expires_at']<time() || $e['expires_at']>time()+300
        || !is_string($e['nonce_sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$e['nonce_sha256'])
        || !hash_equals($e['nonce_sha256'],hash('sha256',(string)($_SERVER['HTTP_X_BAMBODIA_QA_NONCE']??''))))
        report([],404);
    if(realpath($root)!==$root || !is_file($root.'/bootstrap/app.php') || !is_file($root.'/vendor/autoload.php')) report([],409);
    $controller=$root.'/app/Http/Controllers/Api/BambodiaMcpController.php';
    if(!is_file($controller) || is_link($controller)) report([],409);
    $bytes=file_get_contents($controller);
    if($bytes===false) report([],409);
    $blob=sha1('blob '.strlen($bytes)."\0".$bytes);
    unset($bytes);
    if(!is_string($e['controller_blob']??null) || !preg_match('/^[a-f0-9]{40}$/D',$e['controller_blob'])
        || !hash_equals($e['controller_blob'],$blob)) report(['controller_fingerprint'=>false],409);
    // Use exactly Laravel's environment/configuration bootstrappers, NOT the
    // HTTP kernel, console kernel, service providers, routes, sessions or DB.
    require $root.'/vendor/autoload.php';
    $app=require $root.'/bootstrap/app.php';
    (new \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables)->bootstrap($app);
    (new \Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
    $config=$app['config'];
    $db=$config->get('database.default');
    $d=is_string($db)?$config->get('database.connections.'.$db,[]):[];
    $identity=hash('sha256',json_encode([$db,$d['host']??null,$d['port']??null,$d['database']??null,
        $d['username']??null,$d['unix_socket']??null],JSON_THROW_ON_ERROR));
    $actual=[
        'app_env'=>$config->get('app.env'),
        'debug'=>$config->get('app.debug'),
        'mcp_enabled'=>$config->get('mcp.production_enabled'),
        'mcp_writes'=>$config->get('mcp.production_writes_enabled'),
        'commerce_enabled'=>$config->get('commerce.enabled'),
        'invoices_enabled'=>$config->get('invoices.enabled',false),
        'invoice_writes'=>$config->get('invoices.draft_writes_enabled',false),
        'config_cached'=>$app->configurationIsCached(),
        'mail_driver'=>$config->get('mail.default'),
        'queue_driver'=>$config->get('queue.default'),
        'disk'=>$config->get('filesystems.default'),
        'db_identity_sha256'=>$identity,
        'db_url_absent'=>empty($d['url']),
        'app_key_present'=>is_string($config->get('app.key')) && $config->get('app.key')!=='',
        'php_version_id'=>PHP_VERSION_ID,
        'worker_uid'=>function_exists('posix_geteuid')?posix_geteuid():null,
    ];
    $expected=$e['expected']??null;
    if(!is_array($expected) || count($expected)!==count($actual) || array_diff_key($expected,$actual)) report([],409);
    $checks=['sapi'=>true,'controller_fingerprint'=>true,'root'=>realpath($app->basePath())===$root];
    foreach($actual as $key=>$value) $checks[$key]=array_key_exists($key,$expected) && $expected[$key]===$value;
    report($checks,in_array(false,$checks,true)?409:200);
} catch(\Throwable $error) {
    report(['safe_probe_completed'=>false],409);
}

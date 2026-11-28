<?php
declare(strict_types=1);
// Operator-only metadata preflight. No Laravel bootstrap, migrations or writes.
ini_set('display_errors','0');ini_set('log_errors','0');
$pdo=null;$step='identity';
function must(bool $ok,string $label): void { if(!$ok) throw new RuntimeException($label); }
try {
    $root='/www/wwwroot/bambodia-staging-qa';
    must(PHP_SAPI==='cli' && PHP_VERSION_ID>=80500 && realpath($root)===$root,'CLI_ROOT');
    must(!is_file($root.'/bootstrap/cache/config.php'),'CONFIG_CACHE');
    must(!is_link($root.'/.env') && is_file($root.'/.env'),'ENV_PATH');
    require $root.'/vendor/autoload.php';
    $env=\Dotenv\Dotenv::createArrayBacked($root)->load();
    must(($env['DB_CONNECTION']??'')==='mysql' && empty($env['DB_URL']),'DB_DRIVER_URL');
    foreach(['INVOICES_ENABLED','INVOICES_DRAFT_WRITES_ENABLED'] as $flag)
        must(!isset($env[$flag]) || in_array(strtolower($env[$flag]),['false','(false)','0',''],true),'FEATURE_FLAG');
    $db=$env['DB_DATABASE']??'';$host=$env['DB_HOST']??'127.0.0.1';$port=$env['DB_PORT']??'3306';$sock=$env['DB_SOCKET']??'';
    must(preg_match('/^[A-Za-z0-9_]+$/D',$db)===1 && in_array($host,['localhost','127.0.0.1','::1'],true) && preg_match('/^[0-9]{1,5}$/D',$port)===1,'DB_ENDPOINT');
    must($sock==='' || (preg_match('#^/[A-Za-z0-9_./-]+$#D',$sock)===1 && file_exists($sock)),'SOCKET');
    $dsn='mysql:'.($sock!==''?'unix_socket='.$sock:'host='.$host.';port='.$port).';dbname='.$db.';charset=utf8mb4';
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5,\Pdo\Mysql::ATTR_MULTI_STATEMENTS=>false];
    if(!empty($env['MYSQL_ATTR_SSL_CA'])) $options[\Pdo\Mysql::ATTR_SSL_CA]=$env['MYSQL_ATTR_SSL_CA'];
    $pdo=new PDO($dsn,$env['DB_USERNAME']??'',$env['DB_PASSWORD']??'',$options);
    $pdo->exec('START TRANSACTION READ ONLY');
    $q=fn(string $sql,array $params=[]) => (function()use($pdo,$sql,$params){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);})();
    $step='database_identity';
    $id=$q('SELECT DATABASE() db, VERSION() version, @@foreign_key_checks fk')[0];
    must($id['db']===$db && preg_match('/^8\./',$id['version'])===1 && stripos($id['version'],'mariadb')===false && (int)$id['fk']===1,'MYSQL_IDENTITY');
    echo "MYSQL_IDENTITY_AND_FOREIGN_KEYS=PASS\n";
    $step='schema_metadata';
    $cols=[
      'users'=>['id','account_status','role'],
      'marketplace_orders'=>['id','public_reference','seller_id','buyer_id','listing_title','currency','total','status','receipt_number','completed_at'],
      'commerce_payments'=>['id','marketplace_order_id','status','live_mode','settlement_reference'],
      'commerce_order_operations'=>['id','marketplace_order_id','commerce_payment_id','refund_status','refund_reference','refund_resolved_at'],
      'audit_events'=>['id','actor_id','event','ip_fingerprint','created_at'],
      'account_notifications'=>['id'],'email_deliveries'=>['id'],'product_entitlements'=>['id'],'service_inquiries'=>['id'],
      'oauth_clients'=>['id'],'oauth_access_tokens'=>['id'],'oauth_refresh_tokens'=>['id'],'oauth_auth_codes'=>['id'],'migrations'=>['migration']
    ];
    $tables=$q('SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');
    $map=array_column($tables,null,'TABLE_NAME');
    foreach($cols as $t=>$needed) must(isset($map[$t]) && $map[$t]['ENGINE']==='InnoDB' && $map[$t]['TABLE_TYPE']==='BASE TABLE','REQUIRED_INNODB_TABLE');
    foreach(['marketplace_invoices','marketplace_invoice_items','mcp_invoice_operations'] as $t) must(!isset($map[$t]),'INVOICE_TABLE_COLLISION');
    $all=$q('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,DATA_TYPE,NUMERIC_PRECISION,NUMERIC_SCALE,COLUMN_KEY,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()');
    $c=[];foreach($all as $row)$c[$row['TABLE_NAME']][$row['COLUMN_NAME']]=$row;
    foreach($cols as $t=>$needed)foreach($needed as $n)must(isset($c[$t][$n]),'REQUIRED_COLUMN');
    foreach(['users','marketplace_orders','audit_events'] as $t){
        $x=$c[$t]['id'];must($x['COLUMN_TYPE']==='bigint unsigned' && $x['COLUMN_KEY']==='PRI' && $x['IS_NULLABLE']==='NO','PARENT_ID_COMPATIBILITY');
    }
    $x=$c['marketplace_orders']['total'];must($x['DATA_TYPE']==='decimal' && (int)$x['NUMERIC_PRECISION']===12 && (int)$x['NUMERIC_SCALE']===2,'ORDER_MONEY_PRECISION');
    $idx=$q('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX');
    $unique=[];foreach($idx as $x)if((int)$x['NON_UNIQUE']===0)$unique[$x['TABLE_NAME']][$x['INDEX_NAME']][]=$x['COLUMN_NAME'];
    must(in_array(['public_reference'],$unique['marketplace_orders']??[],true),'ORDER_REFERENCE_UNIQUE');
    must(in_array(['marketplace_order_id'],$unique['commerce_order_operations']??[],true),'OPERATION_ORDER_UNIQUE');
    $constraints=$q('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()');
    foreach($constraints as $x)must(!in_array(strtolower($x['CONSTRAINT_NAME']),['invoice_amount_check','invoice_lifecycle_check','invoice_item_amount_check','marketplace_invoices_marketplace_order_id_foreign','marketplace_invoices_issuer_id_foreign','marketplace_invoices_recipient_id_foreign','marketplace_invoice_items_marketplace_invoice_id_foreign','mcp_invoice_operations_actor_id_foreign','mcp_invoice_operations_resource_reference_foreign','mcp_invoice_operations_audit_event_id_foreign'],true),'CONSTRAINT_COLLISION');
    echo "REQUIRED_SCHEMA_COLUMNS_AND_ENGINES=PASS\nPARENT_KEYS_AND_MONEY_PRECISION=PASS\nSOURCE_UNIQUE_INDEXES=PASS\nINVOICE_SCHEMA_COLLISIONS=NONE\n";
    $step='migration_history';
    $migrations=array_column($q('SELECT migration FROM migrations'),'migration');
    must(!in_array('2026_11_28_120000_create_marketplace_invoice_foundation',$migrations,true),'INVOICE_MIGRATION_ALREADY_APPLIED');
    $pending=0;foreach(glob($root.'/database/migrations/*.php') as $file)if(!in_array(basename($file,'.php'),$migrations,true))++$pending;
    echo "EXISTING_PENDING_MIGRATION_COUNT=".$pending."\n";
    must($pending===0,'UNREVIEWED_PENDING_MIGRATIONS');
    $pdo->exec('ROLLBACK');$pdo=null;
    echo "READ_ONLY_TRANSACTION_ROLLED_BACK=YES\nPRODUCTION_SCHEMA_PREFLIGHT=PASS\nPRODUCTION_DATABASE_MUTATIONS=0\n";
} catch(Throwable $e) {
    if($pdo instanceof PDO){try{$pdo->exec('ROLLBACK');}catch(Throwable){}}
    // Do not emit credentials, schema names, SQL errors or raw exception text.
    $reason=$e instanceof RuntimeException && preg_match('/^[A-Z_]+$/D',$e->getMessage())?$e->getMessage():'PRIVATE_ERROR';
    fwrite(STDERR,"PRODUCTION_SCHEMA_PREFLIGHT=BLOCKED\nPREFLIGHT_STEP=".$step."\nPREFLIGHT_REASON=".$reason."\n");exit(1);
}

#!/usr/bin/env bash
# Step 8E: existing isolated QA Unix socket, GET-only FastCGI smoke tests.
# No source, FPM, Nginx, DNS, TLS, service or production changes.
set -Eeuo pipefail
set +x
umask 077
QA='/var/lib/bambodia-preview-qa-20261112'
APP='/var/lib/bambodia-preview-restore-20261112'
USER='bambodia_pvrsqa'
PHP='/www/server/php/85/bin/php'
SERVICE='bambodia-previewqa-fpm.service'
SOCKET='/run/bambodia-previewqa/php-fpm.sock'
CONF='/etc/bambodia-previewqa/php-fpm.conf'
INDEX="$APP/public/index.php"
fail(){ printf 'BLOCKED=%s\n' "$*" >&2; exit 2; }

echo '========== STEP 8E — DEDICATED FASTCGI PREFLIGHT =========='
[[ "$(id -u)" -eq 0 ]] || fail root_required
for cmd in git stat systemctl runuser grep; do
  command -v "$cmd" >/dev/null || fail "missing_command:$cmd"
done
[[ -x "$PHP" && -d "$QA/.git" && ! -L "$QA" ]] || fail missing_qa_runtime
[[ "$(git -C "$QA" rev-parse HEAD)" = '584e1b29697fd87227dc42c987342fa405ea5c09' ]] || fail qa_commit
[[ -z "$(git -C "$QA" status --porcelain --untracked-files=no)" ]] || fail qa_source_modified
[[ -d "$APP" && ! -L "$APP" && "$(realpath -e "$APP")" = "$APP" ]] || fail recovered_app_path
[[ "$(stat -c '%U:%G:%a' "$APP")" = "$USER:$USER:700" ]] || fail recovered_app_mode
[[ "$(stat -c '%U:%G:%a' "$APP/public")" = "$USER:$USER:700" ]] || fail recovered_public_mode
[[ -s "$INDEX" && ! -L "$INDEX" && -s "$APP/storage/app/installed.lock" ]] || fail recovered_index_or_lock
[[ -f "$APP/.env" && ! -L "$APP/.env" &&
  "$(stat -c '%U:%G:%a' "$APP/.env")" = "$USER:$USER:600" ]] || fail recovered_qa_env_mode
[[ -f "$CONF" && ! -L "$CONF" ]] || fail dedicated_fpm_conf_missing
grep -Fxq 'user = bambodia_pvrsqa' "$CONF" || fail dedicated_worker_user_changed
grep -Fxq 'listen = /run/bambodia-previewqa/php-fpm.sock' "$CONF" || fail dedicated_socket_path_changed
grep -Fxq 'error_log = /var/log/bambodia-previewqa/php-fpm.log' "$CONF" || fail errorlog_repair_missing
[[ -S "$SOCKET" && ! -L "$SOCKET" &&
  "$(stat -c '%U:%G:%a' "$SOCKET")" = 'www:www:660' ]] || fail dedicated_socket_mode_changed
systemctl is-active --quiet "$SERVICE" || fail dedicated_service_not_active
[[ "$(systemctl is-enabled "$SERVICE" 2>/dev/null || true)" = disabled ]] || fail service_boot_status_changed
runuser -u www -- test -w "$SOCKET" || fail web_client_socket_denied
if runuser -u www -- test -r "$APP/.env"; then fail shared_user_can_read_private_env; fi
if runuser -u www -- test -r "$INDEX"; then fail shared_user_can_read_private_php; fi
echo 'RECOVERED_QA_SOCKET=READY'
echo 'RECOVERED_QA_FILES=PRIVATE'

echo '========== DIRECT FASTCGI GET ACCEPTANCE =========='
for PAIR in '/up 200' '/ 200' '/login 200' '/dashboard/preview-content 302'; do
  read -r PATH_ONLY EXPECTED <<< "$PAIR"
  runuser -u www -- "$PHP" -r '
function blocked($what) {fwrite(STDERR,"FASTCGI_BLOCKED=".$what."\n");exit(2);}
function rec($type,$body="") {
  if (strlen($body)>65535) blocked("oversize_record");
  return pack("CCnnCC",1,$type,1,strlen($body),0,0).$body;
}
function lng($n){return $n<128?chr($n):pack("N",$n|0x80000000);}
function param($k,$v){return lng(strlen($k)).lng(strlen($v)).$k.$v;}
function exactly($sock,$n) {
  $result="";
  while(strlen($result)<$n) {
    $chunk=fread($sock,$n-strlen($result));
    if ($chunk===false || $chunk==="") blocked("socket_timeout_or_close");
    $result.=$chunk;
  }
  return $result;
}
[$socket,$front,$url,$expected]=array_slice($argv,1);
if (!in_array($url,["/up","/","/login","/dashboard/preview-content"],true)) blocked("unsupported_route");
$fp=@stream_socket_client("unix://".$socket,$number,$text,3);
if (!$fp) blocked("socket_connection_failed");
stream_set_timeout($fp,12);
$values=[
"GATEWAY_INTERFACE"=>"CGI/1.1","REQUEST_METHOD"=>"GET",
"CONTENT_LENGTH"=>"0","SCRIPT_FILENAME"=>$front,
"SCRIPT_NAME"=>"/index.php","PHP_SELF"=>"/index.php",
"DOCUMENT_ROOT"=>dirname($front),"REQUEST_URI"=>$url,
"QUERY_STRING"=>"","SERVER_PROTOCOL"=>"HTTP/1.1",
"SERVER_NAME"=>"127.0.0.1","SERVER_ADDR"=>"127.0.0.1",
"SERVER_PORT"=>"18097","REMOTE_ADDR"=>"127.0.0.1",
"REMOTE_PORT"=>"53124","HTTP_HOST"=>"127.0.0.1:18097",
"REDIRECT_STATUS"=>"200",
];
$p="";foreach ($values as $k=>$v)$p.=param($k,$v);
$payload=rec(1,pack("n",1).str_repeat("\0",6)).rec(4,$p).rec(4).rec(5);
for($offset=0;$offset<strlen($payload);) {
  $n=fwrite($fp,substr($payload,$offset));
  if ($n===false || $n<1) blocked("socket_write_failed");
  $offset+=$n;
}
$out="";$err=false;$end=false;$appstatus=-1;$protocol=-1;
for($i=0;$i<512;$i++) {
  $head=unpack("Cversion/Ctype/nid/nsize/Cpadding/Creserved",exactly($fp,8));
  if($head["version"]!==1 || $head["id"]!==1) blocked("unexpected_protocol_header");
  $data=exactly($fp,$head["size"]);
  if($head["padding"]) exactly($fp,$head["padding"]);
  if($head["type"]===6) {
    $out.=$data;
    if(strlen($out)>8388608) blocked("response_too_large");
  } elseif($head["type"]===7) {
    if(trim($data)!=="") $err=true;
  } elseif($head["type"]===3) {
    if(strlen($data)<8) blocked("bad_end_request");
    $result=unpack("Napp/Cproto",substr($data,0,5));
    $appstatus=$result["app"];$protocol=$result["proto"];
    $end=true;break;
  }
}
fclose($fp);
if(!$end || $appstatus!==0 || $protocol!==0) blocked("fastcgi_not_completed");
$parts=preg_split("/\r?\n\r?\n/",$out,2);
if(count($parts)!==2) blocked("no_response_headers");
$headers=$parts[0];$status=200;
if(preg_match("/^Status:\s*([0-9]{3})/mi",$headers,$m)) $status=(int)$m[1];
if($status!==(int)$expected || $err) {
  fwrite(STDERR,"FASTCGI_STATUS=".$status." EXPECTED=".$expected.
  " STDERR_PRESENT=".($err?"YES":"NO")."\n");
  exit(2);
}
if($url==="/dashboard/preview-content") {
  if(!preg_match("/^Location:\s*(\S+)/mi",$headers,$m) ||
     !str_contains($m[1],"/login") ||
     str_contains($m[1],"/installer")) blocked("guest_not_redirected_to_login");
}
echo "FASTCGI_PASS=".$url." STATUS=".$status."\n";
' "$SOCKET" "$INDEX" "$PATH_ONLY" "$EXPECTED" ||
    fail "fastcgi_route_failed:$PATH_ONLY"
done
echo '========== STEP 8E ACCEPTANCE =========='
echo 'DEDICATED_FASTCGI_SOCKET=PASS'
echo 'RECOVERED_LARAVEL_GET_ROUTES=PASS'
echo 'GUEST_PREVIEW_ACCESS_CONTROL=PASS'
echo 'FPM_OR_NGINX_RESTARTS=NONE'
echo 'DNS_TLS_PUBLIC_VHOST_CHANGES=NONE'
echo 'PHYSICAL_DEVICE_ACCEPTANCE=NOT_TESTED'
echo 'STEP8E=PASS'

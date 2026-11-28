<?php
// Shared fail-closed guard. Never source deployment environment files.
function phase4d3c2Environment(): void {
    $root=getenv('QA_ROOT');
    if (!is_string($root) || !preg_match('~^/var/tmp/bambodia-phase4d3c2-runtime\.[A-Za-z0-9]+$~D',$root)
        || realpath($root)!==$root || realpath(getcwd())!==$root
        || getenv('APP_ENV')!=='testing' || getenv('DB_CONNECTION')!=='mysql'
        || getenv('DB_DATABASE')!=='bambodia_acceptance' || getenv('DB_USERNAME')!=='bambodia_acceptance'
        || getenv('DB_HOST')!=='127.0.0.1' || getenv('DB_URL')!==''
        || getenv('DB_SOCKET')!==$root.'/mysql.sock' || !getenv('APP_KEY')
        || getenv('MAIL_MAILER')!=='array' || file_exists($root.'/.env')
        || !is_file($root.'/.isolated-ready') || is_link($root.'/.isolated-ready'))
        throw new RuntimeException('PHASE4D3C2_ENVIRONMENT_REFUSED');
}
function phase4d3c2Database(): void {
    phase4d3c2Environment();
    $root=getenv('QA_ROOT');
    $db=\Illuminate\Support\Facades\DB::connection();
    if (!app()->environment('testing') || $db->getDriverName()!=='mysql'
        || $db->getDatabaseName()!=='bambodia_acceptance' || config('database.connections.mysql.unix_socket')!==$root.'/mysql.sock'
        || config('database.connections.mysql.url') || config('mail.default')!=='array')
        throw new RuntimeException('PHASE4D3C2_EFFECTIVE_CONFIG_REFUSED');
    $r=$db->selectOne('SELECT DATABASE() AS db, @@datadir AS data_path, @@socket AS socket_path, @@skip_networking AS no_tcp, @@event_scheduler AS scheduler, @@local_infile AS local_files');
    if ($r->db!=='bambodia_acceptance' || $r->data_path!==$root.'/data/' || $r->socket_path!==$root.'/mysql.sock'
        || (int)$r->no_tcp!==1 || $r->scheduler!=='OFF' || (int)$r->local_files!==0)
        throw new RuntimeException('PHASE4D3C2_DATABASE_IDENTITY_REFUSED');
}

<?php

/**
 * Run a real ClamAV clean-file and EICAR-signature smoke test.
 * CLI only. Creates two temporary harmless fixtures and deletes both.
 *
 * php support/vault-scan-acceptance.php --scanner=/usr/bin/clamscan
 *
 * This confirms that the configured engine can detect one standard test
 * signature; it cannot certify every uploaded file as malware-free.
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli'){ http_response_code(404); exit(1); }

$scanner=getenv('VAULT_CLAMSCAN_BINARY')?:'/usr/bin/clamscan';
foreach($argv as $arg){
    if(str_starts_with($arg,'--scanner='))$scanner=substr($arg,10);
}
if(!str_starts_with($scanner,'/') || !is_file($scanner)
    || is_link($scanner) || !is_executable($scanner)){
    fwrite(STDERR,"BLOCKED: executable ClamAV scanner not available.\n");
    exit(2);
}
if(!function_exists('proc_open')){
    fwrite(STDERR,"BLOCKED: PHP cannot invoke ClamAV.\n");
    exit(2);
}

$run=static function(array $arguments): array {
    $process=proc_open($arguments,[
        0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
    ],$pipes);
    if(!is_resource($process))return ['code'=>2,'output'=>'Unable to start scanner'];
    fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['code'=>proc_close($process),'output'=>$out."\n".$error];
};

$version=$run([$scanner,'--version']);
if($version['code']!==0 || !str_contains($version['output'],'ClamAV ')){
    fwrite(STDERR,"BLOCKED: ClamAV version information could not be confirmed.\n");
    exit(2);
}

$clean=tempnam(sys_get_temp_dir(),'bambodia-clean-');
$infected=tempnam(sys_get_temp_dir(),'bambodia-test-');
if($clean===false || $infected===false){
    fwrite(STDERR,"BLOCKED: could not allocate safe temporary fixtures.\n");
    exit(2);
}

try{
    file_put_contents($clean,"Bambodia test fixture: ordinary harmless text.\n");
    // Assemble harmless EICAR antivirus test marker at runtime rather than
    // storing the full marker in repository source.
    $marker='X5O!P%@AP[4'.chr(92).'PZX54(P^)7CC)7}'.
        '$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    file_put_contents($infected,$marker);
    $cleanResult=$run([$scanner,'--no-summary','--infected','--stdout',$clean]);
    $testResult=$run([$scanner,'--no-summary','--infected','--stdout',$infected]);

    $good=$cleanResult['code']===0
        && $testResult['code']===1
        && str_contains($testResult['output'],'FOUND');

    echo $good
        ? "PASS: real ClamAV accepted clean fixture and detected the EICAR test marker.\n"
        : "BLOCKED: ClamAV did not provide both expected independent results.\n";
    echo "Engine: ".trim($version['output'])."\n";
    echo "This is NOT a production-file malware clearance, legal-rights review or full hosting acceptance.\n";
    exit($good?0:2);
}finally{
    @unlink($clean);
    @unlink($infected);
}

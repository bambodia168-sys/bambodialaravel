<?php

return [
    /*
     * The only supported production scanner is ClamAV's clamscan executable.
     * Missing scanner or signatures FAIL CLOSED. Never enable downloads by
     * setting a bypass flag or by marking a manual report as a clean scan.
     */
    'scanner_binary'=>env('VAULT_CLAMSCAN_BINARY','/usr/bin/clamscan'),
    'scanner_timeout_seconds'=>(int)env('VAULT_CLAMSCAN_TIMEOUT',90),
    'max_scan_age_days'=>(int)env('VAULT_MAX_SCAN_AGE_DAYS',30),
    'max_definition_age_days'=>(int)env('VAULT_MAX_DEFINITION_AGE_DAYS',7),
];

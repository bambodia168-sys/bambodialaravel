<?php

return [
    'enabled' => env('INVOICES_ENABLED', false),
    'draft_writes_enabled' => env('INVOICES_DRAFT_WRITES_ENABLED', false),
    // No issuance implementation in Phase 4E.2A. Neither flag can issue an invoice.
    'timezone' => 'Asia/Phnom_Penh',
];

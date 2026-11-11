<?php

return [
    // Explicitly opt-in only after independent host acceptance, owner consent,
    // backup verification and an authorized, per-record operator decision.
    'enabled' => (bool) env('PREVIEW_VISIBILITY_WRITES_ENABLED', false),
];

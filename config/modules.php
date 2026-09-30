<?php

/*
|--------------------------------------------------------------------------
| Feature Modules
|--------------------------------------------------------------------------
|
| Every optional product area is registered here so it can be switched on
| per environment (and later per plan in Phase 10) without restructuring
| the codebase. Check a module with `modules()->enabled('warmup')`.
|
| A disabled module keeps its tables, settings and navigation entry. The
| UI shows it as locked instead of hiding it, so users know it's coming.
|
*/

return [

    'ai' => [
        'label' => 'AI Personalization',
        'phase' => 6,
        'enabled' => env('MODULE_AI_ENABLED', true),
    ],

    'warmup' => [
        'label' => 'Inbox Warmup',
        'phase' => 14,
        'enabled' => env('MODULE_WARMUP_ENABLED', false),
    ],

    'email_verification' => [
        'label' => 'Email Verification',
        'phase' => 11,
        'enabled' => env('MODULE_EMAIL_VERIFICATION_ENABLED', false),
    ],

    'white_label' => [
        'label' => 'White Label',
        'phase' => 15,
        'enabled' => env('MODULE_WHITE_LABEL_ENABLED', false),
    ],

];

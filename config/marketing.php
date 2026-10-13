<?php

/*
|--------------------------------------------------------------------------
| Public website
|--------------------------------------------------------------------------
|
| The landing page, pricing, help center and legal pages at the site root.
| Self-hosted installs (one company, no public sign-up) can turn the site
| off: "/" then goes straight to the app. Agencies' custom domains never
| show it.
|
*/

return [

    'enabled' => (bool) env('MARKETING_SITE_ENABLED', true),

    'tagline' => env('MARKETING_TAGLINE', 'Cold email that lands in the inbox.'),

    'description' => env('MARKETING_DESCRIPTION', 'Unlimited mailboxes, warmup, AI personalization and a shared inbox for replies. Find leads, send at a safe pace and see what works.'),

    // Shown on the legal pages and in the footer.
    'company' => env('MARKETING_COMPANY', env('APP_NAME', 'Outreach')),
    'contact_email' => env('MARKETING_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS')),
    'address' => env('MARKETING_ADDRESS'),

];

<?php

/*
|--------------------------------------------------------------------------
| PDF document identity
|--------------------------------------------------------------------------
|
| Letterhead details rendered into the running header and the closing
| footer band of every exported PDF (see resources/views/pdf/layout.blade.php).
| Kept in config — not hardcoded in the Blade views — so the institution can
| be rebranded without touching templates.
|
*/

return [
    'brand' => env('PDF_BRAND', config('app.name', 'EAPMS')),
    'organisation' => env('PDF_ORGANISATION', 'The Institute of Finance Management'),
    'motto' => env('PDF_MOTTO', 'Jifunze Uhudumie'),
    'tagline' => env('PDF_TAGLINE', 'Attendance & Permission Management System'),

    // Crest rendered in the running header. Relative to public/; blank or missing
    // file simply drops the image and the header reflows.
    'logo' => env('PDF_LOGO', 'logo.png'),

    'contact' => [
        'email' => env('PDF_CONTACT_EMAIL', 'hr@eapms.ifm.ac.tz'),
        'address' => env('PDF_CONTACT_ADDRESS', '5 Shaaban Robert St, Dar es Salaam'),
        'phone' => env('PDF_CONTACT_PHONE', '+255 22 211 2931'),
        'website' => env('PDF_CONTACT_WEBSITE', 'www.ifm.ac.tz'),
    ],

    // Shown in the running footer of every page.
    'classification' => env('PDF_CLASSIFICATION', 'Confidential — Internal Use Only'),
];

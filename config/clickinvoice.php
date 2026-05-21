<?php

/**
 * ClickInvoice brand links (aligned with clickinvoice.app / landing SEO).
 * Used in transactional and broadcast email footers.
 */
$appUrl = rtrim(env('APP_FRONTEND_URL', 'https://app.clickinvoice.app'), '/');

return [
    'site_url' => env('FRONTEND_URL', 'https://clickinvoice.app'),
    'app_url' => $appUrl,
    'support_email' => 'support@clickinvoice.app',
    'social_icon_base' => $appUrl . '/images/social',

    'social' => [
        [
            'platform' => 'Facebook',
            'url' => 'https://facebook.com/invoiceclick',
            'icon' => 'facebook.png',
        ],
        [
            'platform' => 'LinkedIn',
            'url' => 'https://www.linkedin.com/company/clickinvoice',
            'icon' => 'linkedin.png',
        ],
        [
            'platform' => 'X',
            'url' => 'https://x.com/clickinvoice',
            'icon' => 'x.png',
        ],
        [
            'platform' => 'Instagram',
            'url' => 'https://instagram.com/clickinvoiceapp',
            'icon' => 'instagram.png',
        ],
    ],
];

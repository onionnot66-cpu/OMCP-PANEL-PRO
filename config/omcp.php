<?php

return [
    // Use a zone-scoped DNS Read/Edit API token, never a global API key.
    'dns' => [
        'zone' => env('OMCP_DNS_ZONE_ID', ''),
        'domain' => env('OMCP_DNS_BASE_DOMAIN', ''),
        'token' => env('OMCP_DNS_TOKEN', ''),
    ],
];

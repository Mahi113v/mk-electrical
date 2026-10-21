<?php

/*
| Browsers on other addresses (Netlify frontend -> Railway backend) may call the API only from these origins.
| Set CORS_ALLOWED_ORIGINS in .env / Railway as a comma separated list.
*/
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        'https://mk-electrical.netlify.app,http://localhost:5173,http://127.0.0.1:5173'
    ))))),

    'allowed_origins_patterns' => [],

    // X-Company-Id and X-Year-Id are custom headers, they must be allowed
    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];

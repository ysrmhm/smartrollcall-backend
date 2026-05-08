<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // APK'dan, Capacitor WebView'dan ve dev sunuculardan gelen istekleri kabul et.
    // Bearer token auth kullandığımız için '*' güvenli (cookie/CSRF yok).
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

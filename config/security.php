<?php
// === Security defaults for host/CORS policy =================================
// For local development, set env var: ALLOWED_HOSTS=localhost,127.0.0.1
$envHosts = trim((string)(getenv('ALLOWED_HOSTS') ?: ''));
$defaultHosts = ['aplus-charisma.ru', 'www.aplus-charisma.ru'];

return [
    'allowed_hosts' => $envHosts !== ''
        ? array_values(array_unique(array_filter(array_map('trim', explode(',', $envHosts)))))
        : $defaultHosts,
    'default_cors_origin' => 'https://aplus-charisma.ru',
];

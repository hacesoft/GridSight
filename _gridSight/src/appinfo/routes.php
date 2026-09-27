<?php
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'lineaApi#health', 'url' => '/api/linea/health', 'verb' => 'GET'],
        ['name' => 'lineaApi#status', 'url' => '/api/linea/status', 'verb' => 'GET'],
        ['name' => 'history#get', 'url' => '/api/history', 'verb' => 'GET'],
        ['name' => 'history#info', 'url' => '/api/history/info', 'verb' => 'GET'],
        ['name' => 'history#daily', 'url' => '/api/history/daily', 'verb' => 'GET'],
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#testConnection', 'url' => '/api/settings/test', 'verb' => 'POST'],
    ],
];

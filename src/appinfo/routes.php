<?php
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'lineaApi#health', 'url' => '/api/linea/health', 'verb' => 'GET'],
        ['name' => 'lineaApi#status', 'url' => '/api/linea/status', 'verb' => 'GET'],
        ['name' => 'history#get', 'url' => '/api/history', 'verb' => 'GET'],
        ['name' => 'history#info', 'url' => '/api/history/info', 'verb' => 'GET'],
        ['name' => 'history#daily', 'url' => '/api/history/daily', 'verb' => 'GET'],
        ['name' => 'energyAnalysis#overview', 'url' => '/api/analysis', 'verb' => 'GET'],
        ['name' => 'energyAnalysis#saveProfile', 'url' => '/api/analysis/profile', 'verb' => 'PUT'],
        ['name' => 'energyAnalysis#importReport', 'url' => '/api/analysis/report', 'verb' => 'POST'],
        ['name'=>'energyAnalysis#deleteReport','url'=>'/api/analysis/report/{period}','verb'=>'DELETE'],
        ['name'=>'energyAnalysis#exportReports','url'=>'/api/analysis/export','verb'=>'POST'],
        ['name'=>'energyAnalysis#saveStatement','url'=>'/api/analysis/statement','verb'=>'POST'],
        ['name'=>'energyAnalysis#deleteStatement','url'=>'/api/analysis/statement/{id}','verb'=>'DELETE'],
        ['name' => 'settings#get', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#save', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#testConnection', 'url' => '/api/settings/test', 'verb' => 'POST'],
    ],
];

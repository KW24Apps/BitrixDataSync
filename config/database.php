<?php

// ===== AMBIENTE {teste2} =====
// 'teste' | 'producao'
$env = 'producao';

$config = [

    'teste' => [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'port'     => '5432',
        'user'     => 'postgres',
        'password' => '12Qwaszx!@',
        'charset'  => 'utf8',
        'storage'  => [
            'db_prefix' => 'bx_sync_'
        ]
    ],

    'producao' => [
        'driver'   => 'pgsql',
        'host'     => '127.0.0.1',
        'port'     => '5432',
        'user'     => 'appuser',
        'password' => '159Qwaszx753!@',
        'charset'  => 'utf8',
        'storage'  => [
            'db_prefix' => 'bx_sync_'
        ]
    ]

];

return $config[$env];

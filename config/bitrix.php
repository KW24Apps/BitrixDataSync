<?php
/**
 * Configuração Multi-Cliente do Bitrix24
 */
return [
    // Entidades que serão sincronizadas para TODOS os clientes automaticamente
    'global_entities' => [
        'empresas' => [
            'type' => 'company',
            'table_base_name' => 'empresas'
        ],
        'tarefas' => [
            'type' => 'task',
            'table_base_name' => 'tarefas'
        ]
    ],
    
    'clients' => [
        'Grupo Nimbus' => [
            'plan' => 'enterprise',
            'webhook_url' => 'https://gnapp.bitrix24.com.br/rest/21/g321gnxcrxnx4ing/',
            'entities' => [
                'spa_1054' => [
                    'type' => 'spa',
                    'id' => 1054,
                    'table_base_name' => 'kw24'
                ],
                'spa_190' => [
                    'type' => 'spa',
                    'id' => 190,
                    'table_base_name' => 'financeiro'
                ],
                'deal_2' => [
                    'type' => 'crm',
                    'id' => 2,
                    'table_base_name' => 'negocio'
                ]
            ]
        ]
    ]
];

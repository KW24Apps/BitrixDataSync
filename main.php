<?php
/**
 * BitrixDataSync - Ponto de Entrada Único
 * Este script decide automaticamente entre Carga Bruta ou Incremental.
 */
require_once __DIR__ . '/bootstrap.php';

use Services\SyncService;

$config = require __DIR__ . '/config/bitrix.php';
$clients = $config['clients'] ?? [];
$globalEntities = $config['global_entities'] ?? [];

foreach ($clients as $clientKey => $clientData) {
    $webhookUrl = $clientData['webhook_url'];
    $clientPlan = $clientData['plan'] ?? 'others';
    $allEntities = array_merge($globalEntities, $clientData['entities'] ?? []);

    foreach ($allEntities as $entityKey => $entityInfo) {
        $sync = new SyncService($clientKey, $webhookUrl, $entityInfo, $clientPlan);
        
        // Se a tabela não existe, faz a Carga Bruta Inicial
        if (!$sync->tableExists()) {
            echo "\n[INFO] Iniciando Carga Bruta Inicial para {$entityKey}...\n";
            $sync->runFull();
        } else {
            // Caso contrário, segue a Rotina Incremental Inteligente
            echo "\n[INFO] Iniciando Rotina Incremental para {$entityKey}...\n";
            $sync->runIncremental(10); // Janela de 10 horas
        }
    }
}

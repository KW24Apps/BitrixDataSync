<?php
/**
 * BitrixDataSync - Versão V1 (Carga Bruta Estável)
 * Este script executa a sincronização completa de todas as entidades.
 */
require_once __DIR__ . '/bootstrap.php';

use Services\SyncService;

echo "====================================================\n";
echo "INÍCIO DO PROCESSO (V1): " . date('d/m/Y H:i:s') . "\n";
echo "====================================================\n";

try {
    $config = require __DIR__ . '/config/bitrix.php';
    $clients = $config['clients'] ?? [];
    $globalEntities = $config['global_entities'] ?? [];

    foreach ($clients as $clientKey => $clientData) {
        $webhookUrl = $clientData['webhook_url'];
        $clientPlan = $clientData['plan'] ?? 'others';
        $allEntities = array_merge($globalEntities, $clientData['entities'] ?? []);

        foreach ($allEntities as $entityKey => $entityInfo) {
            $startTime = date('H:i:s');
            $sync = new SyncService($clientKey, $webhookUrl, $entityInfo, $clientPlan);
            
            try {
                // Executa a Carga Bruta (Completa)
                $total = $sync->syncData([]);
                
                // Log enxuto de finalização
                $sync->log($entityKey, $startTime, $total, "OK");
            } catch (Exception $e) {
                $sync->log($entityKey, $startTime, 0, "ERRO", $e->getMessage());
            }
        }
    }

    echo "\n====================================================\n";
    echo "FIM DO PROCESSO: " . date('d/m/Y H:i:s') . "\n";
    echo "====================================================\n";

} catch (Exception $e) {
    echo "ERRO GERAL: " . $e->getMessage() . "\n";
}

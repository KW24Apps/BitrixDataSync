<?php
namespace Services;

use Helpers\DatabaseHelper;
use Services\BitrixService;
use Services\MappingService;
use Services\SchemaService;
use PDO;
use Exception;

class SyncService
{
    private $db;
    private $bitrix;
    private $mapping;
    private $pdo;
    private $clientKey;
    private $clientPlan;
    private $entityId;
    private $entityType;
    private $tableBaseName;
    private $webhookUrl;
    private $entityInfo;

    public function __construct($clientKey, $webhookUrl, $entityInfo, $clientPlan = 'others')
    {
        $this->clientKey = $clientKey;
        $this->webhookUrl = $webhookUrl;
        $this->entityInfo = $entityInfo;
        $this->clientPlan = strtolower($clientPlan);
        $this->entityType = $entityInfo['type'] ?? 'spa';
        $this->entityId = $entityInfo['id'] ?? null;
        $this->tableBaseName = $entityInfo['table_base_name'];
        
        $this->db = DatabaseHelper::getInstance($clientKey);
        $this->pdo = $this->db->getPdo();
        $this->bitrix = new BitrixService($webhookUrl);
        $this->mapping = new MappingService($clientKey, $webhookUrl, $entityInfo);
    }

    /**
     * Executa a Sincronização Incremental (Rápida)
     */
    public function runIncremental($hours = 10)
    {
        $startTime = date('H:i:s');
        try {
            // 1. Limpeza de Deletados
            $this->syncDeleted();

            // 2. Atualização de Novos e Editados
            $timeAgo = date('Y-m-d\TH:i:s', strtotime("-{$hours} hours")) . "-03:00";
            $dateField = ($this->entityType === 'task') ? 'changedDate' : 'updatedTime';
            
            $this->syncData([">{$dateField}" => $timeAgo]);
            
            // 3. Checagem de Integridade
            $bitrixTotal = $this->getBitrixTotal();
            $localTotal = $this->getLocalTotal();

            if ($localTotal < $bitrixTotal) {
                echo "\n[ALERTA] Banco incompleto ({$localTotal} < {$bitrixTotal}). Disparando Carga Bruta...\n";
                return $this->runFull();
            }

            $this->log($this->tableBaseName, $startTime, $localTotal, "OK", "(Incremental)");
            return true;
        } catch (Exception $e) {
            $this->log($this->tableBaseName, $startTime, 0, "ERRO", $e->getMessage());
            return false;
        }
    }

    /**
     * Executa a Carga Bruta (Completa com Re-tentativa)
     */
    public function runFull($attempt = 1)
    {
        $startTime = date('H:i:s');
        $maxAttempts = 2;

        try {
            $this->syncData([]);
            
            $bitrixTotal = $this->getBitrixTotal();
            $localTotal = $this->getLocalTotal();
            $diff = abs($bitrixTotal - $localTotal);

            if ($diff == 0) {
                $this->log($this->tableBaseName, $startTime, $localTotal, "OK");
                return true;
            } elseif ($diff <= 20) {
                $this->syncData(['>ID' => 0]); 
                $localTotal = $this->getLocalTotal();
                $this->log($this->tableBaseName, $startTime, $localTotal, "OK", "(Ajustado +$diff)");
                return true;
            } else {
                if ($attempt < $maxAttempts) {
                    echo "[Tentativa {$attempt}] Divergência alta ({$diff}). Reiniciando...\n";
                    return $this->runFull($attempt + 1);
                }
                $this->log($this->tableBaseName, $startTime, $localTotal, "ERRO", "Divergência persistente ({$diff})");
                return false;
            }
        } catch (Exception $e) {
            $this->log($this->tableBaseName, $startTime, 0, "ERRO", $e->getMessage());
            return false;
        }
    }

    public function log($entity, $start, $total, $status, $extra = '')
    {
        $now = date('H:i:s');
        $date = date('d/m/Y');
        $client = strtoupper($this->clientKey);
        $entity = strtoupper($entity);
        $msg = "[{$date} {$now}] {$client} | {$entity} | INÍCIO: {$start} | FIM: {$now} | TOTAL: {$total} | STATUS: {$status} {$extra}\n";
        
        echo $msg;
        // Caminho absoluto para o log na raiz do projeto
        $logPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sync.log';
        file_put_contents($logPath, $msg, FILE_APPEND);
    }

    public function getBitrixTotal($filters = [])
    {
        $method = ($this->entityType === 'company') ? 'crm.company.list' : (($this->entityType === 'task') ? 'tasks.task.list' : 'crm.item.list');
        $params = ['filter' => $filters, 'select' => ['id']];
        if ($this->entityType === 'spa' || $this->entityType === 'crm') $params['entityTypeId'] = $this->entityId;
        
        $res = $this->bitrix->call($method, $params);
        return (int)($res['total'] ?? 0);
    }

    public function getLocalTotal()
    {
        $physicalTable = "tbl_" . $this->tableBaseName;
        return (int)$this->pdo->query("SELECT count(*) FROM \"{$physicalTable}\"")->fetchColumn();
    }

    public function syncDeleted()
    {
        $allBitrixIds = [];
        $lastId = 0;
        $method = ($this->entityType === 'company') ? 'crm.company.list' : (($this->entityType === 'task') ? 'tasks.task.list' : 'crm.item.list');
        $idKey = ($this->entityType === 'company') ? 'ID' : 'id';

        do {
            $params = ['select' => ['id'], 'filter' => [">{$idKey}" => $lastId], 'order' => ['id' => 'asc']];
            if ($this->entityId) $params['entityTypeId'] = $this->entityId;
            
            $res = $this->bitrix->call($method, $params);
            $items = $res['result']['items'] ?? $res['result']['tasks'] ?? $res['result'] ?? [];
            if (empty($items)) break;

            foreach ($items as $item) {
                $id = $item['ID'] ?? $item['id'] ?? null;
                if ($id) {
                    $allBitrixIds[] = (int)$id;
                    $lastId = (int)$id;
                }
            }
        } while (count($items) == 50);

        $physicalTable = "tbl_" . $this->tableBaseName;
        $this->cleanupDeletedRecords($allBitrixIds, $physicalTable);
        return count($allBitrixIds);
    }

    public function tableExists()
    {
        $physicalTable = "tbl_" . $this->tableBaseName;
        $stmt = $this->pdo->prepare("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)");
        $stmt->execute([$physicalTable]);
        return (bool)$stmt->fetchColumn();
    }

    public function syncData($filters = [], $limit = null)
    {
        $isIncremental = !empty($filters);

        // 1. Garante que o índice e o schema físico estão 100%
        $this->mapping->syncFieldMapping();
        $schema = new SchemaService($this->clientKey, $this->mapping);
        $schema->updatePhysicalSchema();

        // 2. Carrega o mapa completo do índice para validação de tipos
        $mappedFields = $this->mapping->getMappedFields();
        $fieldMap = [];
        $selectFields = [];
        $physicalTable = "tbl_" . $this->tableBaseName;

        foreach ($mappedFields as $f) {
            if ($f['is_deleted']) continue;
            $selectFields[] = $f['uf_field'];
            $fieldMap[strtoupper($f['uf_field'])] = [
                'column' => $f['physical_column'],
                'type' => $f['field_type'],
                'is_multiple' => (bool)$f['is_multiple']
            ];
        }

        if (!in_array('ID', $selectFields)) $selectFields[] = 'ID';
        if (!in_array('id', $selectFields)) $selectFields[] = 'id';

        $lastId = 0;
        $totalProcessed = 0;
        $page = 0;
        $allBitrixIds = [];
        $retryCount = 0;
        $maxRetries = 3;

        $method = '';
        $idKey = 'ID';
        $baseParams = [
            'select' => $selectFields,
            'filter' => $filters,
            'order' => ['ID' => 'ASC']
        ];

        switch ($this->entityType) {
            case 'company': $method = 'crm.company.list'; break;
            case 'task': 
                $method = 'tasks.task.list'; 
                $idKey = 'ID'; 
                $baseParams['order'] = ['id' => 'asc'];
                break;
            default: 
                $method = 'crm.item.list'; 
                $idKey = 'id';
                $baseParams['entityTypeId'] = $this->entityId;
                $baseParams['order'] = ['id' => 'asc'];
                break;
        }

        do {
            try {
                $page++;
                $params = $baseParams;
                $params['filter']['>' . $idKey] = $lastId;
                $params['start'] = -1;

                $response = $this->bitrix->call($method, $params);
                
                if (isset($response['result']['items'])) {
                    $items = $response['result']['items'];
                } elseif ($this->entityType === 'task') {
                    $items = $response['result']['tasks'] ?? $response['result'] ?? [];
                } else {
                    $items = $response['result'] ?? [];
                }

                if (empty($items)) break;

                $prevLastId = $lastId;
                foreach ($items as $item) {
                    $bitrixId = $item['ID'] ?? $item['id'] ?? $item[$idKey] ?? null;
                    if (!$bitrixId) continue;

                    $allBitrixIds[] = $bitrixId;
                    $this->saveItem($item, $fieldMap, $physicalTable);
                    $totalProcessed++;

                    // Feedback de progresso para tabelas grandes no modo incremental
                    if ($isIncremental && $totalProcessed % 500 === 0) {
                        echo "[Progresso] {$totalProcessed} registros processados...\n";
                    }

                    if ($limit && $totalProcessed >= $limit) break 2;
                }

                $lastItem = end($items);
                $lastId = $lastItem['ID'] ?? $lastItem['id'] ?? $lastItem[$idKey] ?? $lastId;

                if ($lastId === $prevLastId && !empty($items)) break;

                if (isset($response['time']['operating'])) {
                    $operating = $response['time']['operating'];
                    if ($operating > 400) {
                        $resetAt = $response['time']['operating_reset_at'] ?? (time() + 60);
                        $waitTime = max(10, $resetAt - time() + 5);
                        sleep($waitTime);
                    }
                }
                

                $retryCount = 0;
                if (count($items) < 50) break;
                $pauseTime = ($this->clientPlan === 'enterprise') ? 200000 : 500000;
                usleep($pauseTime);

            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'time limit') !== false && $retryCount < $maxRetries) {
                    $retryCount++;
                    sleep(10);
                    continue;
                }
                throw $e;
            }
        } while (true);

        if (!$limit && !empty($allBitrixIds)) {
            $this->cleanupDeletedRecords($allBitrixIds, $physicalTable);
        }

        return $totalProcessed;
    }

    private function cleanupDeletedRecords($activeIds, $tableName)
    {
        if (empty($activeIds)) return;
        try {
            $this->pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS temp_active_ids (bitrix_id BIGINT PRIMARY KEY)");
            $this->pdo->exec("TRUNCATE temp_active_ids");
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("INSERT INTO temp_active_ids (bitrix_id) VALUES (?) ON CONFLICT DO NOTHING");
            foreach ($activeIds as $id) $stmt->execute([$id]);
            $this->pdo->commit();
            $this->pdo->exec("DELETE FROM \"{$tableName}\" WHERE bitrix_id NOT IN (SELECT bitrix_id FROM temp_active_ids)");
            $this->pdo->exec("DROP TABLE temp_active_ids");
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        }
    }

    private function saveItem($item, $fieldMap, $tableName)
    {
        $bitrixId = $item['ID'] ?? $item['id'] ?? null;
        if (!$bitrixId) return;

        $data = [];
        foreach ($item as $key => $value) {
            $upperKey = strtoupper($key);
            if (isset($fieldMap[$upperKey])) {
                $columnName = $fieldMap[$upperKey]['column'];
                $expectedType = $fieldMap[$upperKey]['type'];
                $isMultiple = $fieldMap[$upperKey]['is_multiple'];
                
                if (is_array($value) && isset($value[0]['VALUE'])) $value = $value[0]['VALUE'];
                if ($value === 'Y') $value = 1; elseif ($value === 'N') $value = 0;
                
                $isEmpty = (is_array($value) && empty($value)) || (!is_array($value) && trim((string)$value) === "");
                if ($isEmpty) { $value = null; } else {
                    if (is_array($value)) {
                        foreach ($value as &$val) { if (!is_array($val) && strpos((string)$val, '|') !== false) { $parts = explode('|', (string)$val); $val = trim($parts[0]); } }
                        unset($val);
                        $value = $isMultiple ? implode(', ', $value) : (!empty($value) ? reset($value) : null);
                    } else { if (strpos((string)$value, '|') !== false) { $parts = explode('|', (string)$value); $value = trim($parts[0]); } }
                    if (in_array($expectedType, ['double', 'money', 'integer', 'date', 'datetime']) && !is_null($value) && !is_array($value)) {
                        $strValue = (string)$value;
                        if ($strValue === '' || strpos($strValue, '0000-00-00') !== false) { $value = null; }
                        elseif (in_array($expectedType, ['double', 'money', 'integer'])) {
                            $cleanValue = str_replace([' ', ','], ['', '.'], $strValue);
                            if (is_numeric($cleanValue)) $value = $cleanValue; else $value = null;
                        }
                    }
                }
                $data[$columnName] = $value;
            }
        }
        $this->upsert($tableName, $bitrixId, $data);
    }

    private function upsert($tableName, $bitrixId, $data)
    {
        if (empty($data)) return;
        $columns = array_keys($data);
        $columns[] = 'bitrix_id';
        $columns[] = 'sync_updated_at';
        $placeholders = array_fill(0, count($data), '?');
        $placeholders[] = '?';
        $placeholders[] = 'CURRENT_TIMESTAMP';
        $updateParts = [];
        foreach (array_keys($data) as $col) $updateParts[] = "\"$col\" = EXCLUDED.\"$col\"";
        $updateParts[] = "sync_updated_at = CURRENT_TIMESTAMP";
        $sql = "INSERT INTO \"{$tableName}\" (" . implode(', ', array_map(fn($c) => "\"$c\"", $columns)) . ") 
                VALUES (" . implode(', ', $placeholders) . ") 
                ON CONFLICT (bitrix_id) DO UPDATE SET " . implode(', ', $updateParts);
        $stmt = $this->pdo->prepare($sql);
        $values = array_values($data);
        $values[] = $bitrixId;
        $stmt->execute($values);
    }
}

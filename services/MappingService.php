<?php
namespace Services;

use Helpers\DatabaseHelper;
use Services\BitrixService;
use PDO;
use Exception;

class MappingService
{
    private $db;
    private $bitrix;
    private $pdo;
    private $entityId;
    private $entityType;
    private $tableBaseName;
    private $indexTableName;

    public function __construct($clientKey, $webhookUrl, $entityInfo)
    {
        $this->entityType = $entityInfo['type'] ?? 'spa';
        $this->entityId = $entityInfo['id'] ?? null;
        $this->tableBaseName = $entityInfo['table_base_name'];
        $this->indexTableName = "idx_" . $this->tableBaseName;
        
        $this->db = DatabaseHelper::getInstance($clientKey);
        $this->pdo = $this->db->getPdo();
        $this->bitrix = new BitrixService($webhookUrl);
        
        $this->db->createMetadataTable($this->indexTableName);
    }

    public function syncFieldMapping()
    {
        $bitrixFields = $this->fetchFieldsByType();
        $stats = ['new' => 0, 'updated_name' => 0, 'deleted' => 0, 'unchanged' => 0];

        $this->pdo->exec("UPDATE \"{$this->indexTableName}\" SET is_deleted = 0");
        $seenFields = [];

        foreach ($bitrixFields as $fieldId => $fieldData) {
            $fieldType = strtolower($fieldData['type'] ?? 'string');

            // 1. Ignora campos booleanos e de arquivo conforme solicitado
            if (in_array($fieldType, ['boolean', 'bool', 'file', 'disk_file', 'webdav', 'attachment'])) {
                continue;
            }

            // Normalização de tipos para garantir consistência no Schema e Sync
            if (in_array($fieldType, ['double', 'money', 'number', 'numeric'])) {
                $fieldType = 'double';
            }

            $friendlyName = $fieldData['title'] ?? $fieldData['TITLE'] ?? $fieldData['formLabel'] ?? $fieldId;
            
            // Ajuste para Tarefas onde a flag de múltiplo pode vir em lugares diferentes ou pelo tipo 'array'
            $isMultipleRaw = $fieldData['isMultiple'] ?? $fieldData['multiple'] ?? $fieldData['MULTIPLE'] ?? false;
            $isMultiple = ($isMultipleRaw === true || $isMultipleRaw === 'Y' || $fieldType === 'array') ? 1 : 0;
            
            $seenFields[] = $fieldId;

            $stmt = $this->pdo->prepare("SELECT * FROM \"{$this->indexTableName}\" WHERE uf_field = ?");
            $stmt->execute([$fieldId]);
            $existing = $stmt->fetch();

            $physicalColumn = DatabaseHelper::sanitizeColumnName($friendlyName) . "_" . DatabaseHelper::sanitizeColumnName($fieldId);

            if (!$existing) {
                $physicalTable = "tbl_" . $this->tableBaseName;
                $this->insertNewField($fieldId, $friendlyName, $fieldType, $physicalTable, $physicalColumn, $isMultiple);
                $stats['new']++;
            } else {
                // Verifica se algo mudou (nome ou flag de múltiplo)
                if ($existing['friendly_name'] !== $friendlyName || $existing['is_multiple'] != $isMultiple) {
                    $this->updateFieldMetadata($existing['id'], $friendlyName, $physicalColumn, $isMultiple);
                    $stats['updated_name']++;
                } else {
                    $stats['unchanged']++;
                }
                
                if ($existing['is_deleted']) {
                    $this->pdo->prepare("UPDATE \"{$this->indexTableName}\" SET is_deleted = 0 WHERE id = ?")->execute([$existing['id']]);
                }
            }
        }

        if (!empty($seenFields)) {
            $placeholders = implode(',', array_fill(0, count($seenFields), '?'));
            $sqlDeleted = "SELECT * FROM \"{$this->indexTableName}\" WHERE uf_field NOT IN ($placeholders)";
            $stmtDeleted = $this->pdo->prepare($sqlDeleted);
            $stmtDeleted->execute($seenFields);
            $toDelete = $stmtDeleted->fetchAll();

            foreach ($toDelete as $field) {
                $this->pdo->prepare("UPDATE \"{$this->indexTableName}\" SET is_deleted = 1 WHERE id = ?")->execute([$field['id']]);
                $stats['deleted']++;
            }
        }

        return $stats;
    }

    private function fetchFieldsByType()
    {
        switch ($this->entityType) {
            case 'company': return $this->bitrix->getCompanyFields();
            case 'task': return $this->bitrix->getTaskFields();
            default: return $this->bitrix->getFields($this->entityId);
        }
    }

    private function insertNewField($fieldId, $friendlyName, $fieldType, $physicalTable, $physicalColumn, $isMultiple)
    {
        $sql = "INSERT INTO \"{$this->indexTableName}\" 
                (uf_field, friendly_name, field_type, physical_table, physical_column, is_multiple, last_updated) 
                VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
        $this->pdo->prepare($sql)->execute([$fieldId, $friendlyName, $fieldType, $physicalTable, $physicalColumn, $isMultiple]);
    }

    private function updateFieldMetadata($id, $newName, $newPhysicalColumn, $isMultiple)
    {
        $sql = "UPDATE \"{$this->indexTableName}\" 
                SET new_friendly_name = ?, 
                    is_multiple = ?,
                    needs_rename = 1, 
                    last_updated = CURRENT_TIMESTAMP 
                WHERE id = ?";
        $this->pdo->prepare($sql)->execute([$newName, $isMultiple, $id]);
    }

    public function getMappedFields()
    {
        return $this->pdo->query("SELECT * FROM \"{$this->indexTableName}\" ORDER BY id ASC")->fetchAll();
    }

    public function getIndexTableName() { return $this->indexTableName; }
}

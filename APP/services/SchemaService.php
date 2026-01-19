<?php
namespace Services;

use Helpers\DatabaseHelper;
use Services\MappingService;
use PDO;
use Exception;

class SchemaService
{
    private $db;
    private $pdo;
    private $clientKey;
    private $mapping;

    public function __construct($clientKey, $mapping)
    {
        $this->clientKey = $clientKey;
        $this->mapping = $mapping;
        $this->db = DatabaseHelper::getInstance($clientKey);
        $this->pdo = $this->db->getPdo();
    }

    /**
     * Sincroniza o schema físico (Tabela Única com nomes amigáveis sanitizados)
     */
    public function updatePhysicalSchema()
    {
        $indexTable = $this->mapping->getIndexTableName();
        
        // 1. Processa campos ativos
        $stmt = $this->pdo->query("SELECT * FROM \"{$indexTable}\" WHERE is_deleted = 0");
        $fields = $stmt->fetchAll();

        foreach ($fields as $field) {
            $this->ensureTableExists($field['physical_table']);
            
            if ($field['needs_rename']) {
                $this->renamePhysicalColumn($field);
            } else {
                $this->ensureColumnExists($field);
            }
        }

        // 2. Processa campos excluídos
        $this->processDeletedFields();

        // 3. Efetiva renomeações no índice
        $this->commitFriendlyNames();
    }

    private function processDeletedFields()
    {
        $indexTable = $this->mapping->getIndexTableName();
        $stmt = $this->pdo->query("SELECT * FROM \"{$indexTable}\" WHERE is_deleted = 1");
        $deletedFields = $stmt->fetchAll();

        foreach ($deletedFields as $field) {
            $tableName = $field['physical_table'];
            $columnName = $field['physical_column'];

            if ($this->columnExists($tableName, $columnName)) {
                echo "Removendo coluna física '{$columnName}' da tabela '{$tableName}'...\n";
                $this->pdo->exec("ALTER TABLE \"{$tableName}\" DROP COLUMN \"{$columnName}\"");
            }

            $this->pdo->prepare("DELETE FROM \"{$indexTable}\" WHERE id = ?")->execute([$field['id']]);
        }
    }

    private function ensureTableExists($tableName)
    {
        $sql = "CREATE TABLE IF NOT EXISTS \"{$tableName}\" (
            bitrix_id INT PRIMARY KEY,
            sync_updated_at TIMESTAMP
        );";
        $this->pdo->exec($sql);
    }

    private function ensureColumnExists($field)
    {
        $tableName = $field['physical_table'];
        $columnName = $field['physical_column'];
        $sqlType = $this->mapType($field);

        if (!$this->columnExists($tableName, $columnName)) {
            $this->pdo->exec("ALTER TABLE \"{$tableName}\" ADD COLUMN \"{$columnName}\" {$sqlType}");
        }
    }

    private function renamePhysicalColumn($field)
    {
        $tableName = $field['physical_table'];
        $oldName = $field['physical_column'];
        
        // Gera o novo nome da coluna baseado no novo nome amigável + UF_CRM
        $newName = DatabaseHelper::sanitizeColumnName($field['new_friendly_name']) . "_" . DatabaseHelper::sanitizeColumnName($field['uf_field']);
        $sqlType = $this->mapType($field);

        if ($this->columnExists($tableName, $oldName)) {
            echo "Renomeando coluna '{$oldName}' para '{$newName}' na tabela '{$tableName}'...\n";
            $this->pdo->exec("ALTER TABLE \"{$tableName}\" RENAME COLUMN \"{$oldName}\" TO \"{$newName}\"");
        } else {
            $field['physical_column'] = $newName;
            $this->ensureColumnExists($field);
        }

        // Atualiza o nome da coluna física no índice
        $indexTable = $this->mapping->getIndexTableName();
        $this->pdo->prepare("UPDATE \"{$indexTable}\" SET physical_column = ? WHERE id = ?")->execute([$newName, $field['id']]);
    }

    private function columnExists($tableName, $columnName)
    {
        $stmt = $this->pdo->prepare("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = ? AND column_name = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (bool)$stmt->fetch();
    }

    private function mapType($field)
    {
        // Se for múltiplo, sempre vira TEXT (JSON)
        if ($field['is_multiple']) {
            return 'TEXT';
        }

        switch (strtolower($field['field_type'])) {
            case 'integer':
            case 'int': return 'INTEGER';
            case 'date': return 'DATE';
            case 'datetime': return 'TIMESTAMP';
            case 'double':
            case 'money':
            case 'number':
            case 'numeric': return 'NUMERIC';
            default: return 'TEXT';
        }
    }

    private function commitFriendlyNames()
    {
        $indexTable = $this->mapping->getIndexTableName();
        $this->pdo->exec("UPDATE \"{$indexTable}\" SET friendly_name = new_friendly_name, new_friendly_name = NULL, needs_rename = 0 WHERE needs_rename = 1");
    }
}

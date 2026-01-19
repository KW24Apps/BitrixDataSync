<?php
namespace Helpers;

use PDO;
use Exception;

class DatabaseHelper
{
    private static $instances = [];
    private $pdo;
    private $config;
    private $clientDb;

    private function __construct($clientKey)
    {
        $this->config = require __DIR__ . '/../config/database.php';
        
        // Sanitiza o nome do banco (remove espaços e caracteres especiais)
        $sanitizedKey = self::sanitizeColumnName($clientKey);
        $this->clientDb = $this->config['storage']['db_prefix'] . $sanitizedKey;
        
        // No PostgreSQL, conectamos ao banco 'postgres' para criar novos bancos
        $dsn_admin = "pgsql:host={$this->config['host']};port={$this->config['port']};dbname=postgres";
        
        try {
            $admin_pdo = new PDO($dsn_admin, $this->config['user'], $this->config['password']);
            
            // Verifica se o banco do cliente existe
            $stmt = $admin_pdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $stmt->execute([$this->clientDb]);
            
            if (!$stmt->fetch()) {
                $admin_pdo->exec("CREATE DATABASE \"{$this->clientDb}\" ENCODING 'UTF8'");
            }
            $admin_pdo = null;

            // Conecta ao banco específico do cliente
            $dsn = "pgsql:host={$this->config['host']};port={$this->config['port']};dbname={$this->clientDb}";
            
            // Usando valores numéricos para as constantes do PDO para evitar erros de ambiente
            // 3 = ATTR_ERR_MODE, 2 = ERR_MODE_EXCEPTION
            // 19 = ATTR_DEFAULT_FETCH_MODE, 2 = FETCH_ASSOC
            // 20 = ATTR_EMULATE_PREPARES
            $options = [
                3 => 2,
                19 => 2,
                20 => false,
            ];
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['password'], $options);
        } catch (Exception $e) {
            die("Erro ao conectar ao PostgreSQL (Cliente: {$clientKey}): " . $e->getMessage());
        }
    }

    public static function getInstance($clientKey)
    {
        if (!isset(self::$instances[$clientKey])) {
            self::$instances[$clientKey] = new self($clientKey);
        }
        return self::$instances[$clientKey];
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    public function createMetadataTable($indexTableName)
    {
        $sql = "CREATE TABLE IF NOT EXISTS \"{$indexTableName}\" (
            id SERIAL PRIMARY KEY,
            uf_field VARCHAR(100) NOT NULL,
            friendly_name VARCHAR(255),
            new_friendly_name VARCHAR(255),
            field_type VARCHAR(50),
            physical_table VARCHAR(100),
            physical_column VARCHAR(255),
            is_multiple SMALLINT DEFAULT 0,
            is_deleted SMALLINT DEFAULT 0,
            needs_rename SMALLINT DEFAULT 0,
            last_updated TIMESTAMP,
            UNIQUE (uf_field)
        );";
        
        $this->pdo->exec($sql);
    }

    public static function sanitizeColumnName($text)
    {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace(
            array('/[áàâãª]/u', '/[ÁÀÂÃ]/u', '/[éèêë]/u', '/[ÉÈÊË]/u', '/[íìî]/u', '/[ÍÌÎ]/u', '/[óòôõº]/u', '/[ÓÒÔÕ]/u', '/[úùû]/u', '/[ÚÙÛ]/u', '/[ç]/u', '/[Ç]/u', '/[ñ]/u', '/[Ñ]/u'),
            array('a', 'A', 'e', 'E', 'i', 'I', 'o', 'O', 'u', 'U', 'c', 'C', 'n', 'N'),
            $text
        );
        $text = preg_replace('/[^a-zA-Z0-9_]/', '_', $text);
        $text = preg_replace('/_+/', '_', $text);
        return strtolower(trim($text, '_'));
    }
}

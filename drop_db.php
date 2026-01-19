<?php
/**
 * Script para forçar a exclusão do banco de dados (encerra conexões ativas)
 */
require_once __DIR__ . '/bootstrap.php';
$config = require __DIR__ . '/config/database.php';

$clientKey = 'kw24';
$dbName = $config['storage']['db_prefix'] . $clientKey;

try {
    $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname=postgres";
    $pdo = new PDO($dsn, $config['user'], $config['password']);

    echo "Encerrando conexões ativas com o banco '{$dbName}'...\n";
    $pdo->exec("
        SELECT pg_terminate_backend(pg_stat_activity.pid)
        FROM pg_stat_activity
        WHERE pg_stat_activity.datname = '{$dbName}'
          AND pid <> pg_backend_pid();
    ");

    echo "Removendo banco de dados '{$dbName}'...\n";
    $pdo->exec("DROP DATABASE IF EXISTS \"{$dbName}\"");
    
    echo "Sucesso! O banco foi excluído.\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

<?php
/**
 * Bootstrap do projeto BitrixDataSync
 */
spl_autoload_register(function ($class) {
    $base_dir = __DIR__ . '/';
    
    // Tenta o caminho direto (respeitando o namespace)
    $file = $base_dir . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
        return;
    }

    // Tenta converter a primeira parte (Namespace) para minúsculo (ex: Services -> services)
    // Isso resolve problemas de case-sensitivity no Linux
    $parts = explode('\\', $class);
    if (count($parts) > 1) {
        $parts[0] = strtolower($parts[0]);
        $file = $base_dir . implode('/', $parts) . '.php';
        if (file_exists($file)) {
            require $file;
        }
    }
});
date_default_timezone_set('America/Sao_Paulo');

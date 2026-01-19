<?php
namespace Services;

use Exception;

class BitrixService
{
    private $webhookUrl;

    public function __construct($webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    /**
     * Chama um método da API REST do Bitrix24
     */
    public function call($method, $params = [])
    {
        $url = $this->webhookUrl . $method . '.json';
        $query = http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro cURL: " . $error);
        }

        $data = json_decode($response, true);
        if (isset($data['error'])) {
            throw new Exception("Erro API Bitrix ({$method}): " . ($data['error_description'] ?? $data['error']));
        }

        return $data;
    }

    /**
     * Executa múltiplas chamadas em um único lote (Batch)
     */
    public function batch($commands)
    {
        return $this->call('batch', [
            'halt' => 0,
            'cmd' => $commands
        ]);
    }

    /**
     * Obtém os campos de uma entidade CRM (método universal)
     */
    public function getFields($entityTypeId)
    {
        $response = $this->call('crm.item.fields', ['entityTypeId' => $entityTypeId]);
        return $response['result']['fields'] ?? [];
    }

    /**
     * Obtém os campos de Empresas
     */
    public function getCompanyFields()
    {
        $response = $this->call('crm.company.fields');
        return $response['result'] ?? [];
    }

    /**
     * Obtém os campos de Tarefas
     */
    public function getTaskFields()
    {
        $response = $this->call('tasks.task.getFields');
        return $response['result']['fields'] ?? [];
    }
}

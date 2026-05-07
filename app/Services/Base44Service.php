<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class Base44Service
{
    private Client $client;
    private string $appId;

    public function __construct()
    {
        $this->appId = config('services.base44.app_id');

        $this->client = new Client([
            'base_uri' => rtrim(config('services.base44.api_url'), '/') . '/',
            'headers'  => [
                'Authorization' => 'Bearer ' . config('services.base44.api_key'),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    // ── Generic entity methods ────────────────────────────────────────────────

    public function listEntities(string $entity, array $filters = [], array $sort = [], int $limit = 100): array
    {
        $query = ['limit' => $limit];
        
        if (!empty($filters)) {
            $query['filters'] = json_encode($filters);
        }
        
        if (!empty($sort)) {
            $query['sort'] = json_encode($sort);
        }

        return $this->request('GET', "apps/{$this->appId}/entities/{$entity}", $query);
    }

    public function getEntity(string $entity, string $id): array
    {
        return $this->request('GET', "apps/{$this->appId}/entities/{$entity}/{$id}");
    }

    public function createEntity(string $entity, array $data): array
    {
        return $this->request('POST', "apps/{$this->appId}/entities/{$entity}", $data);
    }

    public function updateEntity(string $entity, string $id, array $data): array
    {
        return $this->request('PATCH', "apps/{$this->appId}/entities/{$entity}/{$id}", $data);
    }

    public function uploadFile(string $filePath, string $mimeType, string $fileName): ?string
    {
        try {
            $response = $this->client->request('POST', "apps/{$this->appId}/files/upload", [
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => $fileName,
                        'headers'  => ['Content-Type' => $mimeType],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            return $body['file_url'] ?? $body['url'] ?? null;
        } catch (RequestException $e) {
            Log::error('Base44 file upload error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── AgentConfig helpers ───────────────────────────────────────────────────

    public function getAgentConfig(): ?array
    {
        $result = $this->listEntities('AgentConfig', [], [], 1);
        return $result['entities'][0] ?? $result[0] ?? null;
    }

    public function upsertAgentConfig(array $data): array
    {
        $existing = $this->getAgentConfig();

        if ($existing) {
            return $this->updateEntity('AgentConfig', $existing['id'], $data);
        }

        return $this->createEntity('AgentConfig', array_merge([
            'connection_status'          => 'disconnected',
            'qr_code_data_url'           => null,
            'whatsapp_session'           => null,
            'system_prompt'              => '',
            'company_name'               => '',
            'welcome_message'            => '',
            'escalation_message'         => '',
            'vision_enabled'             => true,
            'audio_transcription_enabled' => true,
        ], $data));
    }

    // ── Conversation helpers ──────────────────────────────────────────────────

    public function findConversationByPhone(string $phone): ?array
    {
        $result = $this->listEntities('Conversation', [
            ['field' => 'contact_phone', 'operator' => '=', 'value' => $phone],
        ]);
        return $result['entities'][0] ?? $result[0] ?? null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function request(string $method, string $uri, array $data = []): array
    {
        try {
            $options = [];
            
            if ($method === 'GET') {
                $options['query'] = $data;
            } elseif (!empty($data)) {
                $options['json'] = $data;
            }

            $response = $this->client->request($method, $uri, $options);
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            $context = ['method' => $method, 'uri' => $uri];
            if ($e->hasResponse()) {
                $context['response'] = $e->getResponse()->getBody()->getContents();
            }
            Log::error('Base44 API error', array_merge($context, ['error' => $e->getMessage()]));
            throw $e;
        }
    }
}

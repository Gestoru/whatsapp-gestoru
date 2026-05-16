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
                'api_key'       => config('services.base44.api_key'),
                'x-app-id'      => $this->appId,
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

        return $this->request('GET', "entities/{$entity}", $query);
    }

    public function getEntity(string $entity, string $id): array
    {
        return $this->request('GET', "entities/{$entity}/{$id}");
    }

    public function createEntity(string $entity, array $data): array
    {
        return $this->request('POST', "entities/{$entity}", $data);
    }

    public function updateEntity(string $entity, string $id, array $data): array
    {
        return $this->request('PATCH', "entities/{$entity}/{$id}", $data);
    }

    public function uploadFile(string $filePath, string $mimeType, string $fileName): ?string
    {
        try {
            $response = $this->client->request('POST', "files/upload", [
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

    // ── AgentConfig helpers (using Backend Functions) ─────────────────────────

    public function getAgentConfig(): ?array
    {
        try {
            return $this->request('POST', "functions/getAgentConfig");
        } catch (\Exception $e) {
            return null;
        }
    }

    public function upsertAgentConfig(array $data): array
    {
        return $this->request('POST', "functions/updateAgentConfig", $data);
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

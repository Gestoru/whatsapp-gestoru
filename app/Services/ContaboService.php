<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Cliente de la API de Contabo.
 * Auth: OAuth2 password grant contra Keycloak; luego Bearer token en la API.
 * Docs: https://api.contabo.com/
 */
class ContaboService
{
    private const AUTH = 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token';
    private const API  = 'https://api.contabo.com';

    private ?string $clientId;
    private ?string $clientSecret;
    private ?string $apiUser;
    private ?string $apiPassword;

    public function __construct()
    {
        $this->clientId = Setting::get('contabo_client_id') ?: null;
        $this->apiUser  = Setting::get('contabo_api_user') ?: null;
        $this->clientSecret = $this->decrypt('contabo_client_secret');
        $this->apiPassword  = $this->decrypt('contabo_api_password');
    }

    public function configured(): bool
    {
        return $this->clientId && $this->clientSecret && $this->apiUser && $this->apiPassword;
    }

    public static function saveCredentials(?string $clientId, ?string $clientSecret, ?string $apiUser, ?string $apiPassword): void
    {
        Setting::put('contabo_client_id', trim((string) $clientId));
        Setting::put('contabo_api_user', trim((string) $apiUser));
        if ($clientSecret) {
            Setting::put('contabo_client_secret', Crypt::encryptString(trim($clientSecret)));
        }
        if ($apiPassword) {
            Setting::put('contabo_api_password', Crypt::encryptString(trim($apiPassword)));
        }
    }

    private function decrypt(string $key): ?string
    {
        $v = Setting::get($key);
        try {
            return $v ? Crypt::decryptString($v) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Obtiene (y cachea 4 min) un token de acceso. */
    private function token(): string
    {
        return Cache::remember('contabo_token', 240, function () {
            $res = (new Client(['timeout' => 30]))->post(self::AUTH, [
                'form_params' => [
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'username'      => $this->apiUser,
                    'password'      => $this->apiPassword,
                    'grant_type'    => 'password',
                ],
            ]);
            $data = json_decode($res->getBody()->getContents(), true) ?: [];

            return $data['access_token'] ?? '';
        });
    }

    /**
     * Lista todas las instancias (VPS) de la cuenta.
     *
     * @return array{ok: bool, instances: array<int, array<string, mixed>>, error: ?string}
     */
    public function listInstances(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'instances' => [], 'error' => 'Faltan las credenciales de Contabo.'];
        }

        try {
            $token = $this->token();
            if (! $token) {
                return ['ok' => false, 'instances' => [], 'error' => 'No se pudo autenticar con Contabo (revisa client_id/secret y usuario/API password).'];
            }

            $client = new Client(['base_uri' => self::API, 'timeout' => 30, 'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/json',
            ]]);

            $all  = [];
            $page = 1;
            do {
                $res = $client->get('/v1/compute/instances', [
                    'headers' => ['x-request-id' => (string) Str::uuid()],
                    'query'   => ['page' => $page, 'size' => 100],
                ]);
                $body = json_decode($res->getBody()->getContents(), true) ?: [];
                $data = $body['data'] ?? [];

                foreach ($data as $i) {
                    $all[] = [
                        'instance_id' => $i['instanceId'] ?? null,
                        'name'        => $i['displayName'] ?? ($i['name'] ?? null),
                        'product'     => $i['productId'] ?? null,
                        'region'      => $i['region'] ?? null,
                        'status'      => $i['status'] ?? null,
                        'ip'          => $i['ipConfig']['v4']['ip'] ?? null,
                        'created'     => $i['createdDate'] ?? null,
                        'cancel'      => $i['cancelDate'] ?? null,
                    ];
                }

                $total = $body['_pagination']['totalPages'] ?? 1;
                $page++;
            } while ($page <= $total);

            return ['ok' => true, 'instances' => $all, 'error' => null];
        } catch (ClientException $e) {
            $code = $e->getResponse()->getStatusCode();
            $msg = match ($code) {
                401, 403 => 'Contabo denegó el acceso. Revisa las credenciales de API (client_id, client_secret, usuario API y API password).',
                429      => 'Demasiadas consultas a Contabo. Intenta en unos minutos.',
                default  => 'Error de Contabo ('.$code.').',
            };

            return ['ok' => false, 'instances' => [], 'error' => $msg];
        } catch (\Throwable $e) {
            return ['ok' => false, 'instances' => [], 'error' => 'No se pudo conectar con Contabo: '.$e->getMessage()];
        }
    }

    /**
     * Sincroniza las instancias con la tabla servers (match por IP).
     *
     * @return array{ok: bool, matched: int, created: int, total: int, error: ?string}
     */
    public function syncToServers(): array
    {
        $res = $this->listInstances();
        if (! $res['ok']) {
            return ['ok' => false, 'matched' => 0, 'created' => 0, 'total' => 0, 'error' => $res['error']];
        }

        $matched = 0;
        $created = 0;

        foreach ($res['instances'] as $i) {
            if (! $i['ip']) {
                continue;
            }

            $server = Server::firstOrNew(['host' => $i['ip']]);
            $isNew  = ! $server->exists;

            if ($isNew) {
                $server->name      = $i['name'] ?: ('Contabo '.$i['instance_id']);
                $server->provider  = 'contabo';
                $server->port      = 22;
                $server->username  = 'root';
                $server->auth_type = 'password';
                $server->is_active = true;
                $server->color     = '#6366f1';
            }

            $server->provider_status      = $i['status'];
            $server->provider_product     = $i['product'];
            $server->provider_region      = $i['region'];
            $server->provider_instance_id = $i['instance_id'];
            $server->provider_synced_at   = now();

            // Fecha de renovación: cancelDate si existe, si no la próxima
            // mensualidad calculada desde la fecha de creación.
            if (! empty($i['cancel'])) {
                $server->paid_until = substr($i['cancel'], 0, 10);
            } elseif (! empty($i['created'])) {
                $server->paid_until = $this->nextRenewal($i['created']);
            }
            if (! $server->renewal_url) {
                $server->renewal_url = 'https://my.contabo.com/invoices';
            }

            $server->save();
            $isNew ? $created++ : $matched++;
        }

        return ['ok' => true, 'matched' => $matched, 'created' => $created, 'total' => count($res['instances']), 'error' => null];
    }

    /** Próxima mensualidad a partir de la fecha de creación. */
    private function nextRenewal(string $createdIso): string
    {
        try {
            $d     = Carbon::parse($createdIso);
            $today = Carbon::today();
            while ($d->lt($today)) {
                $d->addMonthNoOverflow();
            }

            return $d->format('Y-m-d');
        } catch (\Throwable) {
            return Carbon::today()->format('Y-m-d');
        }
    }
}

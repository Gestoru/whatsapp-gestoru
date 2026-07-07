<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Crypt;

/**
 * Cliente de la API de dominios de GoDaddy.
 * Auth: Authorization: sso-key <API_KEY>:<API_SECRET>
 * Docs: https://developer.godaddy.com/doc/endpoint/domains
 */
class GoDaddyService
{
    private const BASE = 'https://api.godaddy.com';

    private ?string $key;
    private ?string $secret;

    public function __construct()
    {
        $this->key = Setting::get('godaddy_api_key') ?: null;

        $enc = Setting::get('godaddy_api_secret');
        try {
            $this->secret = $enc ? Crypt::decryptString($enc) : null;
        } catch (\Throwable) {
            $this->secret = null;
        }
    }

    public function configured(): bool
    {
        return ! empty($this->key) && ! empty($this->secret);
    }

    /** Guarda las credenciales (el secreto cifrado). */
    public static function saveCredentials(?string $key, ?string $secret): void
    {
        Setting::put('godaddy_api_key', trim((string) $key));
        if ($secret !== null && $secret !== '') {
            Setting::put('godaddy_api_secret', Crypt::encryptString(trim($secret)));
        }
    }

    /**
     * Lista todos los dominios de la cuenta con su estado.
     *
     * @return array{ok: bool, domains: array<int, array<string, mixed>>, error: ?string}
     */
    public function listDomains(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'domains' => [], 'error' => 'Faltan las credenciales de GoDaddy.'];
        }

        $client = new Client([
            'base_uri' => self::BASE,
            'headers'  => [
                'Authorization' => 'sso-key '.$this->key.':'.$this->secret,
                'Accept'        => 'application/json',
            ],
            'timeout'  => 30,
        ]);

        $all    = [];
        $marker = null;

        try {
            do {
                $query = ['limit' => 1000];
                if ($marker) {
                    $query['marker'] = $marker;
                }

                $res  = $client->get('/v1/domains', ['query' => $query]);
                $page = json_decode($res->getBody()->getContents(), true) ?: [];

                foreach ($page as $d) {
                    $all[] = [
                        'domain'     => $d['domain'] ?? null,
                        'status'     => $d['status'] ?? null,
                        'expires'    => $d['expires'] ?? null,
                        'renew_auto' => $d['renewAuto'] ?? null,
                    ];
                }

                $marker = count($page) === 1000 ? ($page[999]['domain'] ?? null) : null;
            } while ($marker);

            return ['ok' => true, 'domains' => array_filter($all, fn ($d) => $d['domain']), 'error' => null];
        } catch (ClientException $e) {
            $code = $e->getResponse()->getStatusCode();
            $msg = match ($code) {
                401     => 'Credenciales inválidas: revisa la API Key y el Secret (¿son de Producción?).',
                403     => 'GoDaddy denegó el acceso. Verifica que tu cuenta tenga al menos 1 dominio y que la llave sea de Producción.',
                404     => 'Endpoint no encontrado.',
                429     => 'Demasiadas consultas a GoDaddy. Intenta de nuevo en unos minutos.',
                default => 'Error de GoDaddy ('.$code.').',
            };

            return ['ok' => false, 'domains' => [], 'error' => $msg];
        } catch (\Throwable $e) {
            return ['ok' => false, 'domains' => [], 'error' => 'No se pudo conectar con GoDaddy: '.$e->getMessage()];
        }
    }

    /**
     * Sincroniza los dominios de GoDaddy con la base de datos local.
     *
     * @return array{ok: bool, created: int, updated: int, total: int, error: ?string}
     */
    public function syncToDatabase(): array
    {
        $res = $this->listDomains();
        if (! $res['ok']) {
            return ['ok' => false, 'created' => 0, 'updated' => 0, 'total' => 0, 'error' => $res['error']];
        }

        $created = 0;
        $updated = 0;

        foreach ($res['domains'] as $d) {
            $name = strtolower($d['domain']);
            $expires = ! empty($d['expires']) ? substr($d['expires'], 0, 10) : null;

            $domain = Domain::firstOrNew(['name' => $name]);
            $isNew  = ! $domain->exists;

            $domain->registrar   = 'godaddy';
            $domain->status      = $d['status'] ?? null;
            $domain->auto_renew  = $d['renew_auto'];
            $domain->expires_at  = $expires;
            $domain->source      = 'godaddy';
            $domain->synced_at   = now();
            if (! $domain->renewal_url) {
                $domain->renewal_url = 'https://dcc.godaddy.com/control/portfolio';
            }
            $domain->save();

            $isNew ? $created++ : $updated++;
        }

        return ['ok' => true, 'created' => $created, 'updated' => $updated, 'total' => count($res['domains']), 'error' => null];
    }
}

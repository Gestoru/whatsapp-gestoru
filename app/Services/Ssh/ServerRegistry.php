<?php

namespace App\Services\Ssh;

use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Unifica los servidores definidos en config/servers.php (desde .env) con los
 * almacenados en la base de datos, y resuelve la conexión de cada uno.
 */
class ServerRegistry
{
    /**
     * Lista completa de servidores (config + base de datos), normalizada.
     *
     * @return Collection<int, array>
     */
    public function all(): Collection
    {
        $fromConfig = collect(config('servers.inventory', []))->map(function (array $s) {
            return [
                'source' => 'config',
                'key' => $s['key'],
                'name' => $s['name'],
                'group' => $s['group'] ?? 'vps',
                'host' => $s['host'],
                'port' => $s['port'] ?? 22,
                'username' => $s['username'] ?? 'root',
                'auth_method' => $s['auth_method'] ?? 'key',
                'base_path' => $s['base_path'] ?: config('servers.default_base_path'),
                'is_active' => true,
            ];
        });

        $fromDb = Server::query()->get()->map(function (Server $s) {
            return [
                'source' => 'db',
                'id' => $s->id,
                'key' => $s->key,
                'name' => $s->name,
                'group' => $s->group,
                'host' => $s->host,
                'port' => $s->port,
                'username' => $s->username,
                'auth_method' => $s->auth_method,
                'base_path' => $s->basePath(),
                'is_active' => $s->is_active,
            ];
        });

        // La base de datos tiene prioridad si comparten la misma 'key'.
        return $fromConfig
            ->concat($fromDb)
            ->keyBy('key')
            ->values();
    }

    /**
     * Devuelve la metadata de un servidor por su 'key'.
     */
    public function find(string $key): ?array
    {
        return $this->all()->firstWhere('key', $key);
    }

    /**
     * Construye la configuración de conexión completa (incluye credenciales).
     */
    public function connectionConfig(string $key): ?array
    {
        // Primero base de datos (credenciales cifradas).
        $server = Server::where('key', $key)->first();
        if ($server) {
            return $server->connectionConfig();
        }

        // Luego inventario de config/.env.
        $raw = collect(config('servers.inventory', []))->firstWhere('key', $key);
        if ($raw) {
            return [
                'host' => $raw['host'],
                'port' => $raw['port'] ?? 22,
                'username' => $raw['username'] ?? 'root',
                'auth_method' => $raw['auth_method'] ?? 'key',
                'private_key' => $raw['private_key'] ?? null,
                'password' => $raw['password'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Crea un servicio de monitoreo listo para usar contra un servidor.
     */
    public function monitor(string $key): ?ServerMonitorService
    {
        $config = $this->connectionConfig($key);
        if (! $config) {
            return null;
        }

        return new ServerMonitorService(new SshClient($config));
    }
}

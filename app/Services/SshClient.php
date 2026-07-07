<?php

namespace App\Services;

use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * Cliente SSH liviano basado en phpseclib3 (PHP puro, sin extensiones del sistema).
 * Abre una conexión por servidor y reutiliza mientras viva la petición.
 */
class SshClient
{
    /** @var array<int, SSH2> conexiones cacheadas por id de servidor */
    private array $pool = [];

    /**
     * Devuelve una conexión SSH2 autenticada para el servidor.
     */
    public function connection(Server $server): SSH2
    {
        if (isset($this->pool[$server->id]) && $this->pool[$server->id]->isConnected()) {
            return $this->pool[$server->id];
        }

        $ssh = new SSH2($server->host, $server->port ?: 22);
        $ssh->setTimeout(20);

        $ok = $server->auth_type === 'key'
            ? $this->authWithKey($ssh, $server)
            : $ssh->login($server->username, (string) $server->password);

        if (! $ok) {
            throw new RuntimeException('Autenticación SSH fallida. Revisa usuario, contraseña/llave y puerto.');
        }

        return $this->pool[$server->id] = $ssh;
    }

    private function authWithKey(SSH2 $ssh, Server $server): bool
    {
        $key = PublicKeyLoader::load(
            (string) $server->private_key,
            (string) ($server->key_passphrase ?? '')
        );

        return $ssh->login($server->username, $key);
    }

    /**
     * Ejecuta un comando y devuelve stdout (+stderr) como string.
     * $timeout permite operaciones más largas (p. ej. pruebas de estrés).
     */
    public function run(Server $server, string $command, ?int $timeout = null): string
    {
        $ssh = $this->connection($server);

        if ($timeout !== null) {
            $ssh->setTimeout($timeout);
        }

        $out = $ssh->exec($command);

        if ($timeout !== null) {
            $ssh->setTimeout(20); // restaurar por defecto
        }

        return is_string($out) ? $out : '';
    }

    /**
     * Prueba la conexión. Devuelve [ok, mensaje].
     *
     * @return array{ok: bool, message: string}
     */
    public function test(Server $server): array
    {
        try {
            $whoami = trim($this->run($server, 'whoami 2>/dev/null; echo "|"; hostname 2>/dev/null'));

            return ['ok' => true, 'message' => 'Conexión exitosa ('.str_replace('|', ' @ ', $whoami).')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

<?php

namespace App\Services\Ssh;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * Cliente SSH ligero basado en phpseclib (PHP puro, sin ext-ssh2).
 * Soporta autenticación por llave privada o usuario+contraseña.
 */
class SshClient
{
    private ?SSH2 $ssh = null;

    /**
     * @param  array  $config  host, port, username, auth_method(key|password), private_key, password
     */
    public function __construct(private array $config) {}

    public function connect(): void
    {
        $timeout = (int) config('servers.ssh_timeout', 15);

        $ssh = new SSH2($this->config['host'], $this->config['port'] ?? 22, $timeout);
        $ssh->setTimeout($timeout);

        $authenticated = false;

        if (($this->config['auth_method'] ?? 'key') === 'key') {
            $keyPath = $this->config['private_key'] ?? null;

            if (! $keyPath || ! is_file($keyPath)) {
                throw new RuntimeException('Llave privada no encontrada: '.($keyPath ?: '(vacía)'));
            }

            $key = PublicKeyLoader::load(
                file_get_contents($keyPath),
                $this->config['password'] ?? false // passphrase opcional
            );

            $authenticated = $ssh->login($this->config['username'], $key);
        } else {
            $authenticated = $ssh->login($this->config['username'], (string) ($this->config['password'] ?? ''));
        }

        if (! $authenticated) {
            throw new RuntimeException('Autenticación SSH fallida para '.$this->config['username'].'@'.$this->config['host']);
        }

        $this->ssh = $ssh;
    }

    /**
     * Ejecuta un comando y devuelve la salida (stdout+stderr combinada).
     */
    public function exec(string $command): string
    {
        if (! $this->ssh) {
            $this->connect();
        }

        $output = $this->ssh->exec($command);

        return is_string($output) ? $output : '';
    }

    /**
     * Ejecuta un comando y devuelve la salida limpia y recortada.
     */
    public function run(string $command): string
    {
        return trim($this->exec($command));
    }

    public function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

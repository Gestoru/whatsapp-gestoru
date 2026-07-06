<?php

namespace App\Services\Ssh;

/**
 * Recolecta métricas de rendimiento e información de carpetas de un servidor
 * a través de una conexión SSH, ejecutando solo comandos de lectura.
 */
class ServerMonitorService
{
    public function __construct(private SshClient $ssh) {}

    /**
     * Prueba de conexión rápida. Devuelve hostname y uptime.
     */
    public function ping(): array
    {
        $this->ssh->connect();

        return [
            'ok' => true,
            'hostname' => $this->ssh->run('hostname'),
            'uptime' => $this->ssh->run('uptime -p 2>/dev/null || uptime'),
        ];
    }

    /**
     * Resumen general del servidor: sistema, CPU, memoria y disco.
     */
    public function overview(): array
    {
        $this->ssh->connect();

        return [
            'system' => $this->system(),
            'cpu' => $this->cpu(),
            'memory' => $this->memory(),
            'disk' => $this->disk(),
        ];
    }

    public function system(): array
    {
        $os = $this->ssh->run('cat /etc/os-release 2>/dev/null | grep PRETTY_NAME | cut -d= -f2 | tr -d \'"\'');

        return [
            'hostname' => $this->ssh->run('hostname'),
            'os' => $os ?: $this->ssh->run('uname -sr'),
            'kernel' => $this->ssh->run('uname -r'),
            'uptime' => $this->ssh->run('uptime -p 2>/dev/null || uptime'),
            'cores' => (int) ($this->ssh->run('nproc 2>/dev/null') ?: 0),
        ];
    }

    public function cpu(): array
    {
        $cores = max(1, (int) ($this->ssh->run('nproc 2>/dev/null') ?: 1));

        // Carga promedio (1, 5, 15 min)
        $loadRaw = $this->ssh->run('cat /proc/loadavg 2>/dev/null');
        $parts = preg_split('/\s+/', $loadRaw);
        $load1 = isset($parts[0]) ? (float) $parts[0] : 0.0;
        $load5 = isset($parts[1]) ? (float) $parts[1] : 0.0;
        $load15 = isset($parts[2]) ? (float) $parts[2] : 0.0;

        // Uso instantáneo vía vmstat (idle en la última fila)
        $usage = null;
        $vm = $this->ssh->run('vmstat 1 2 2>/dev/null | tail -1');
        if ($vm !== '') {
            $cols = preg_split('/\s+/', trim($vm));
            if (isset($cols[14]) && is_numeric($cols[14])) {
                $usage = max(0, min(100, 100 - (int) $cols[14]));
            }
        }

        return [
            'cores' => $cores,
            'load' => [$load1, $load5, $load15],
            // % de carga relativo a los núcleos (aprox.)
            'load_pct' => $cores > 0 ? round(min(100, ($load1 / $cores) * 100)) : 0,
            'usage_pct' => $usage,
            'processes' => $this->topProcesses(),
        ];
    }

    public function memory(): array
    {
        $raw = $this->ssh->run('free -m 2>/dev/null');
        $mem = ['total' => 0, 'used' => 0, 'free' => 0, 'pct' => 0];
        $swap = ['total' => 0, 'used' => 0];

        foreach (explode("\n", $raw) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (($cols[0] ?? '') === 'Mem:') {
                $mem['total'] = (int) ($cols[1] ?? 0);
                $mem['used'] = (int) ($cols[2] ?? 0);
                $mem['free'] = (int) ($cols[3] ?? 0);
                $mem['pct'] = $mem['total'] > 0 ? round(($mem['used'] / $mem['total']) * 100) : 0;
            }
            if (($cols[0] ?? '') === 'Swap:') {
                $swap['total'] = (int) ($cols[1] ?? 0);
                $swap['used'] = (int) ($cols[2] ?? 0);
            }
        }

        return ['ram' => $mem, 'swap' => $swap];
    }

    public function disk(): array
    {
        // Un renglón por sistema de archivos "real"
        $raw = $this->ssh->run('df -h -x tmpfs -x devtmpfs -x squashfs 2>/dev/null | tail -n +2');
        $rows = [];

        foreach (explode("\n", $raw) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 6) {
                continue;
            }
            $rows[] = [
                'filesystem' => $cols[0],
                'size' => $cols[1],
                'used' => $cols[2],
                'available' => $cols[3],
                'pct' => (int) rtrim($cols[4], '%'),
                'mount' => $cols[5],
            ];
        }

        return $rows;
    }

    /**
     * Procesos que más consumen (CPU).
     */
    public function topProcesses(int $limit = 8): array
    {
        $raw = $this->ssh->run('ps -eo pid,comm,%cpu,%mem --sort=-%cpu 2>/dev/null | head -n '.($limit + 1).' | tail -n +2');
        $rows = [];

        foreach (explode("\n", $raw) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 4) {
                continue;
            }
            $rows[] = [
                'pid' => $cols[0],
                'name' => $cols[1],
                'cpu' => (float) $cols[2],
                'mem' => (float) $cols[3],
            ];
        }

        return $rows;
    }

    /**
     * Lista el contenido de una carpeta (proyectos/archivos) con tamaño y fecha.
     */
    public function listPath(string $path): array
    {
        $this->ssh->connect();

        $safe = escapeshellarg($path);
        $raw = $this->ssh->run("ls -lAh --time-style=long-iso {$safe} 2>&1");

        // Si ls falló, devolver el error para mostrarlo en el panel.
        if (str_contains($raw, 'No such file') || str_contains($raw, 'cannot access') || str_contains($raw, 'Permission denied')) {
            return ['path' => $path, 'error' => $raw, 'entries' => []];
        }

        $entries = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '' || str_starts_with($line, 'total ')) {
                continue;
            }
            $cols = preg_split('/\s+/', trim($line), 8);
            if (count($cols) < 8) {
                continue;
            }

            $perms = $cols[0];
            $type = match ($perms[0]) {
                'd' => 'dir',
                'l' => 'link',
                default => 'file',
            };

            $entries[] = [
                'type' => $type,
                'perms' => $perms,
                'owner' => $cols[2],
                'group' => $cols[3],
                'size' => $cols[4],
                'modified' => $cols[5].' '.$cols[6],
                'name' => $cols[7],
            ];
        }

        // Ordenar: carpetas primero, luego por nombre.
        usort($entries, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return ['path' => $path, 'error' => null, 'entries' => $entries];
    }
}

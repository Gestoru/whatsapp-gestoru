<?php

namespace App\Services;

use App\Models\Server;

/**
 * Recoge, a través de SSH, todo lo que el dashboard muestra de un servidor:
 * rendimiento, proyectos instalados, dominios y navegación de archivos.
 * Todos los comandos son de solo lectura.
 */
class ServerMonitor
{
    public function __construct(private SshClient $ssh) {}

    /**
     * Métricas de rendimiento del servidor.
     *
     * @return array<string, mixed>
     */
    public function metrics(Server $server): array
    {
        $raw = $this->ssh->run($server, $this->metricsScript());
        $kv  = $this->parseKeyValues($raw);

        $memTotal = (int) ($kv['MEM_TOTAL'] ?? 0);
        $memAvail = (int) ($kv['MEM_AVAIL'] ?? 0);
        $memUsed  = max(0, $memTotal - $memAvail);

        $diskTotal = (int) ($kv['DISK_TOTAL'] ?? 0);
        $diskUsed  = (int) ($kv['DISK_USED'] ?? 0);

        return [
            'hostname'  => $kv['HOSTNAME'] ?? $server->host,
            'os'        => $kv['OS'] ?? '—',
            'kernel'    => $kv['KERNEL'] ?? '—',
            'uptime'    => $this->humanUptime((float) ($kv['UPTIME'] ?? 0)),
            'load'      => $kv['LOAD'] ?? '—',
            'cpu_cores' => (int) ($kv['CPU_CORES'] ?? 0),
            'cpu_pct'   => $this->clampPct($kv['CPU_PCT'] ?? null),
            'mem' => [
                'total_bytes' => $memTotal,
                'used_bytes'  => $memUsed,
                'total'       => $this->humanBytes($memTotal),
                'used'        => $this->humanBytes($memUsed),
                'pct'         => $memTotal ? (int) round($memUsed / $memTotal * 100) : null,
            ],
            'disk' => [
                'total_bytes' => $diskTotal,
                'used_bytes'  => $diskUsed,
                'total'       => $this->humanBytes($diskTotal),
                'used'        => $this->humanBytes($diskUsed),
                'pct'         => $diskTotal ? (int) round($diskUsed / $diskTotal * 100) : null,
            ],
        ];
    }

    /**
     * Proyectos / servicios detectados en el servidor.
     *
     * @return array<int, array<string, string>>
     */
    public function projects(Server $server): array
    {
        $raw      = $this->ssh->run($server, $this->projectsScript());
        $projects = [];

        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if ($line === '' || ! str_contains($line, "\t")) {
                continue;
            }
            [$type, $name, $detail] = array_pad(explode("\t", $line, 3), 3, '');
            $projects[] = [
                'type'   => $type,
                'name'   => $name,
                'detail' => $detail,
            ];
        }

        return $projects;
    }

    /**
     * Dominios detectados en nginx / apache.
     *
     * @return array<int, string>
     */
    public function domains(Server $server): array
    {
        $raw     = $this->ssh->run($server, $this->domainsScript());
        $domains = [];

        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line !== '' && ! in_array($line, ['_', 'localhost'], true)) {
                $domains[$line] = $line;
            }
        }

        ksort($domains);

        return array_values($domains);
    }

    /**
     * Lista un directorio. Devuelve entradas ordenadas (carpetas primero).
     *
     * @return array{path: string, entries: array<int, array<string, mixed>>}
     */
    public function listPath(Server $server, string $path = '/'): array
    {
        $path = $this->normalizePath($path);
        $raw  = $this->ssh->run($server, 'ls -lAph --time-style=long-iso '.escapeshellarg($path).' 2>&1');

        $entries = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $entry = $this->parseLsLine($line);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return ['path' => $path, 'entries' => $entries];
    }

    /**
     * Lee el contenido de un archivo (limitado a 200 KB para no saturar).
     *
     * @return array{path: string, size: string, content: string, truncated: bool, binary: bool}
     */
    public function readFile(Server $server, string $path): array
    {
        $path  = $this->normalizePath($path);
        $arg   = escapeshellarg($path);
        $limit = 200 * 1024;

        $meta = $this->ssh->run($server, 'stat -c "%s" '.$arg.' 2>/dev/null; file -b --mime-encoding '.$arg.' 2>/dev/null');
        $lines    = preg_split('/\r?\n/', trim($meta));
        $size     = (int) ($lines[0] ?? 0);
        $encoding = strtolower(trim($lines[1] ?? ''));
        $binary   = $encoding === 'binary';

        if ($binary) {
            return [
                'path' => $path, 'size' => $this->humanBytes($size),
                'content' => '', 'truncated' => false, 'binary' => true,
            ];
        }

        $content = $this->ssh->run($server, 'head -c '.$limit.' '.$arg.' 2>&1');

        return [
            'path'      => $path,
            'size'      => $this->humanBytes($size),
            'content'   => $content,
            'truncated' => $size > $limit,
            'binary'    => false,
        ];
    }

    // ── Scripts remotos ─────────────────────────────────────────────────────

    private function metricsScript(): string
    {
        return <<<'SH'
echo "HOSTNAME=$(hostname 2>/dev/null)"
echo "OS=$(. /etc/os-release 2>/dev/null; echo "$PRETTY_NAME")"
echo "KERNEL=$(uname -r 2>/dev/null)"
echo "UPTIME=$(cut -d' ' -f1 /proc/uptime 2>/dev/null)"
echo "LOAD=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null)"
echo "CPU_CORES=$(nproc 2>/dev/null)"
read -r _ a b c d e f g _ < /proc/stat 2>/dev/null
t1=$((a+b+c+d+e+f+g)); i1=$((d+e))
sleep 1
read -r _ a b c d e f g _ < /proc/stat 2>/dev/null
t2=$((a+b+c+d+e+f+g)); i2=$((d+e))
dt=$((t2-t1)); di=$((i2-i1))
if [ "$dt" -gt 0 ]; then echo "CPU_PCT=$(( (100*(dt-di))/dt ))"; fi
mt=$(awk '/MemTotal/{print $2*1024}' /proc/meminfo 2>/dev/null)
ma=$(awk '/MemAvailable/{print $2*1024}' /proc/meminfo 2>/dev/null)
echo "MEM_TOTAL=$mt"
echo "MEM_AVAIL=$ma"
df -P -B1 / 2>/dev/null | awk 'NR==2{print "DISK_TOTAL="$2"\nDISK_USED="$3}'
SH;
    }

    private function projectsScript(): string
    {
        return <<<'SH'
# PM2
if command -v pm2 >/dev/null 2>&1; then
  pm2 jlist 2>/dev/null | grep -o '"name":"[^"]*"' | sed 's/"name":"//;s/"//' | sort -u | while read -r n; do
    [ -n "$n" ] && printf 'pm2\t%s\tproceso node\n' "$n"
  done
fi
# Docker
if command -v docker >/dev/null 2>&1; then
  docker ps --format '{{.Names}}\t{{.Image}}' 2>/dev/null | while IFS="$(printf '\t')" read -r n img; do
    [ -n "$n" ] && printf 'docker\t%s\t%s\n' "$n" "$img"
  done
fi
# Servicios systemd relevantes
systemctl list-units --type=service --state=running --no-legend --no-pager 2>/dev/null \
  | awk '{print $1}' | grep -Ei 'nginx|apache|mysql|mariadb|postgres|php|node|redis|mongo|docker' | sort -u | while read -r s; do
    printf 'servicio\t%s\tactivo\n' "$s"
  done
# Carpetas de proyectos web
for base in /var/www /opt /home /srv/www; do
  if [ -d "$base" ]; then
    find "$base" -maxdepth 1 -mindepth 1 -type d 2>/dev/null | sort | while read -r d; do
      printf 'carpeta\t%s\t%s\n' "$(basename "$d")" "$d"
    done
  fi
done
SH;
    }

    private function domainsScript(): string
    {
        return <<<'SH'
{
  grep -rhoE 'server_name[[:space:]]+[^;]+;' /etc/nginx 2>/dev/null | sed 's/server_name//;s/;//' | tr ' ' '\n'
  grep -rhoE 'ServerName[[:space:]]+[^ ]+' /etc/apache2 /etc/httpd 2>/dev/null | awk '{print $2}'
  grep -rhoE 'ServerAlias[[:space:]]+.+' /etc/apache2 /etc/httpd 2>/dev/null | sed 's/ServerAlias//' | tr ' ' '\n'
} 2>/dev/null | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -vE '^\*|^$' | sort -u
SH;
    }

    // ── Parsers y helpers ───────────────────────────────────────────────────

    /** @return array<string, string> */
    private function parseKeyValues(string $raw): array
    {
        $kv = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $kv[trim($k)] = trim($v);
            }
        }
        return $kv;
    }

    /** @return array<string, mixed>|null */
    private function parseLsLine(string $line): ?array
    {
        $line = trim($line);
        // Descarta total, líneas vacías y errores
        if ($line === '' || str_starts_with($line, 'total ') || str_contains($line, 'No such file')
            || str_contains($line, 'Permission denied') || str_contains($line, 'cannot open')) {
            return null;
        }

        // permisos  enlaces  dueño  grupo  tamaño  fecha  hora  nombre[/@]
        if (! preg_match('/^([\-dlbcps])(\S{9})\s+\d+\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})\s+(.+)$/', $line, $m)) {
            return null;
        }

        $typeChar = $m[1];
        $name     = $m[8];
        $isDir    = $typeChar === 'd';
        $isLink   = $typeChar === 'l';

        // ls -p añade "/" a carpetas y símbolos a otros tipos; los quitamos del nombre
        if ($isLink && str_contains($name, ' -> ')) {
            $name = explode(' -> ', $name, 2)[0];
        }
        $name = rtrim($name, '/*@=|');

        if ($name === '.' || $name === '..') {
            return null;
        }

        return [
            'name'     => $name,
            'is_dir'   => $isDir || $isLink,
            'is_link'  => $isLink,
            'perms'    => $typeChar.$m[2],
            'owner'    => $m[3].':'.$m[4],
            'size'     => $isDir ? '' : $m[5],
            'modified' => $m[6].' '.$m[7],
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');
        // Colapsa .. y . para evitar rutas raras
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        return '/'.implode('/', $parts);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), $i ? 1 : 0).' '.$units[$i];
    }

    private function humanUptime(float $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        $d = (int) floor($seconds / 86400);
        $h = (int) floor(($seconds % 86400) / 3600);
        $m = (int) floor(($seconds % 3600) / 60);

        $out = [];
        if ($d) $out[] = $d.'d';
        if ($h) $out[] = $h.'h';
        $out[] = $m.'m';

        return implode(' ', $out);
    }

    private function clampPct(?string $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        return max(0, min(100, (int) $v));
    }
}

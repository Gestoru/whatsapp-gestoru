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
            $line = strtolower(trim($line));
            if ($this->isRealDomain($line)) {
                $domains[$line] = $line;
            }
        }

        ksort($domains);

        return array_values($domains);
    }

    /** Filtra placeholders y valores que no son dominios reales. */
    private function isRealDomain(string $d): bool
    {
        if ($d === '' || str_starts_with($d, '*') || str_starts_with($d, '_')) {
            return false;
        }
        // Placeholders y dominios de ejemplo/locales
        $blacklist = ['localhost', 'example.com', 'example.org', 'example.net',
            'test.com', 'domain.tld', 'yourdomain.com', 'localhost.localdomain'];
        if (in_array($d, $blacklist, true) || str_ends_with($d, '.local') || str_ends_with($d, '.localdomain')) {
            return false;
        }

        // Debe parecer un dominio válido (etiqueta.tld)
        return (bool) preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $d);
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

    /**
     * Sitios definidos en nginx: dominios → raíz y rutas de logs.
     *
     * @return array<int, array{domains: array<int,string>, root: ?string, access_log: string, error_log: string}>
     */
    public function sites(Server $server): array
    {
        $raw = $this->ssh->run(
            $server,
            'echo "==NGINX=="; nginx -T 2>/dev/null || cat /etc/nginx/nginx.conf /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf 2>/dev/null; '
            .'echo "==APACHE=="; cat /etc/apache2/sites-enabled/* /etc/apache2/vhosts.d/* /etc/httpd/conf.d/*.conf /etc/httpd/sites-enabled/* 2>/dev/null'
        );

        $sections = $this->splitSections($raw);

        return array_merge(
            $this->parseNginxSites($sections['NGINX'] ?? ''),
            $this->parseApacheSites($sections['APACHE'] ?? '')
        );
    }

    /**
     * Diagnóstico: dónde aparece configurado un dominio en el servidor.
     * Útil cuando el reporte no encuentra logs (sitio servido por Docker, etc.).
     *
     * @return array<string, array<int, string>>
     */
    public function domainDiagnostic(Server $server, string $domain): array
    {
        $d = escapeshellarg($domain);
        $script = <<<SH
echo "==NGINX=="
grep -rl {$d} /etc/nginx 2>/dev/null | head -5
echo "==APACHE=="
grep -rl {$d} /etc/apache2 /etc/httpd 2>/dev/null | head -5
echo "==SSL=="
ls /etc/letsencrypt/live 2>/dev/null | grep -i "\$(echo {$d} | sed 's/^www\\.//')" | head -5
echo "==DOCKER=="
if command -v docker >/dev/null 2>&1; then
  for c in \$(docker ps -q 2>/dev/null); do
    if docker inspect "\$c" 2>/dev/null | grep -qi {$d}; then
      docker ps --filter "id=\$c" --format '{{.Names}} · {{.Image}} · {{.Ports}}' 2>/dev/null
    fi
  done
fi
echo "==LOGS=="
ls -1 /var/log/nginx/ /var/log/apache2/ /var/log/httpd/ 2>/dev/null | grep -iE "access|\$(echo {$d} | cut -d. -f1)" | head -10
SH;

        $sections = $this->splitSections($this->ssh->run($server, $script));

        return [
            'nginx'  => $this->nonEmptyLines($sections['NGINX'] ?? ''),
            'apache' => $this->nonEmptyLines($sections['APACHE'] ?? ''),
            'ssl'    => $this->nonEmptyLines($sections['SSL'] ?? ''),
            'docker' => $this->nonEmptyLines($sections['DOCKER'] ?? ''),
            'logs'   => $this->nonEmptyLines($sections['LOGS'] ?? ''),
        ];
    }

    /**
     * Busca el sitio nginx que atiende un dominio.
     *
     * @return array{domains: array<int,string>, root: ?string, access_log: string, error_log: string}|null
     */
    public function siteFor(Server $server, string $domain): ?array
    {
        foreach ($this->sites($server) as $site) {
            if (in_array($domain, $site['domains'], true)) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Reporte de observabilidad de un dominio: tráfico, usuarios (IPs únicas),
     * códigos de estado, rutas top, últimos 5xx y logs de error/aplicación.
     *
     * @param  array{domains: array<int,string>, root: ?string, access_log: string, error_log: string}  $site
     * @return array<string, mixed>
     */
    public function domainReport(Server $server, array $site): array
    {
        $al   = escapeshellarg($site['access_log']);
        $el   = escapeshellarg($site['error_log']);
        $root = escapeshellarg($site['root'] ?? '/nonexistent');

        $script = <<<SH
AL={$al}; EL={$el}; ROOT={$root}
TODAY=\$(date '+%d/%b/%Y'); HOUR=\$(date '+%d/%b/%Y:%H')
T=\$(mktemp)
tail -n 100000 "\$AL" 2>/dev/null > "\$T"
echo "==TOTALS=="
echo "log_exists=\$([ -s "\$T" ] && echo yes || echo no)"
echo "today_requests=\$(grep -c "\\[\$TODAY" "\$T")"
echo "today_ips=\$(grep "\\[\$TODAY" "\$T" | awk '{print \$1}' | sort -u | wc -l)"
echo "hour_requests=\$(grep -c "\\[\$HOUR" "\$T")"
echo "hour_ips=\$(grep "\\[\$HOUR" "\$T" | awk '{print \$1}' | sort -u | wc -l)"
echo "==STATUS=="
grep "\\[\$TODAY" "\$T" | awk '{print \$9}' | grep -E '^[0-9]{3}\$' | sort | uniq -c | sort -rn | head -8
echo "==TOPPATHS=="
grep "\\[\$TODAY" "\$T" | awk '{print \$7}' | cut -d'?' -f1 | sort | uniq -c | sort -rn | head -10
echo "==LAST5XX=="
awk '\$9 ~ /^5[0-9][0-9]\$/' "\$T" | tail -12
rm -f "\$T"
echo "==ERRLOG=="
tail -n 30 "\$EL" 2>/dev/null
echo "==APPLOG=="
APP=\$(dirname "\$ROOT")
if [ -f "\$APP/artisan" ]; then
  L=\$(ls -t "\$APP"/storage/logs/*.log 2>/dev/null | head -1)
  [ -n "\$L" ] && { echo "archivo: \$L"; tail -n 40 "\$L"; }
elif [ -f "\$ROOT/../artisan" ]; then
  L=\$(ls -t "\$ROOT"/../storage/logs/*.log 2>/dev/null | head -1)
  [ -n "\$L" ] && { echo "archivo: \$L"; tail -n 40 "\$L"; }
fi
SH;

        $raw      = $this->ssh->run($server, $script);
        $sections = $this->splitSections($raw);
        $totals   = $this->parseKeyValues($sections['TOTALS'] ?? '');

        return [
            'log_exists'     => ($totals['log_exists'] ?? 'no') === 'yes',
            'today_requests' => (int) ($totals['today_requests'] ?? 0),
            'today_ips'      => (int) ($totals['today_ips'] ?? 0),
            'hour_requests'  => (int) ($totals['hour_requests'] ?? 0),
            'hour_ips'       => (int) ($totals['hour_ips'] ?? 0),
            'status_codes'   => $this->parseCountLines($sections['STATUS'] ?? ''),
            'top_paths'      => $this->parseCountLines($sections['TOPPATHS'] ?? ''),
            'last_5xx'       => $this->nonEmptyLines($sections['LAST5XX'] ?? ''),
            'error_log'      => $this->nonEmptyLines($sections['ERRLOG'] ?? ''),
            'app_log'        => $this->nonEmptyLines($sections['APPLOG'] ?? ''),
        ];
    }

    /**
     * Procesos que más CPU y memoria consumen.
     *
     * @return array{cpu: array<int, array<string,string>>, mem: array<int, array<string,string>>}
     */
    public function topProcesses(Server $server): array
    {
        $raw = $this->ssh->run($server, 'echo "==CPU=="; ps aux --sort=-%cpu 2>/dev/null | head -9; echo "==MEM=="; ps aux --sort=-%mem 2>/dev/null | head -9');
        $sections = $this->splitSections($raw);

        return [
            'cpu' => $this->parsePsLines($sections['CPU'] ?? ''),
            'mem' => $this->parsePsLines($sections['MEM'] ?? ''),
        ];
    }

    /**
     * Consultas SQL lentas de MySQL/MariaDB (si el slow-query-log está activo).
     *
     * @return array{enabled: bool, file: ?string, top: array<int,string>}
     */
    public function slowQueries(Server $server): array
    {
        $script = <<<'SH'
F=""
for f in /var/log/mysql/mysql-slow.log /var/log/mysql/slow.log /var/log/mysql/mariadb-slow.log /var/lib/mysql/*-slow.log; do
  [ -f "$f" ] && { F="$f"; break; }
done
if [ -n "$F" ]; then
  echo "FILE=$F"
  echo "==TOP=="
  if command -v mysqldumpslow >/dev/null 2>&1; then
    mysqldumpslow -s t -t 5 "$F" 2>/dev/null | head -50
  else
    tail -n 60 "$F" 2>/dev/null
  fi
fi
SH;

        $raw = $this->ssh->run($server, $script);
        $sections = $this->splitSections($raw);
        $kv = $this->parseKeyValues($raw);

        return [
            'enabled' => isset($kv['FILE']),
            'file'    => $kv['FILE'] ?? null,
            'top'     => $this->nonEmptyLines($sections['TOP'] ?? ''),
        ];
    }

    /**
     * Reporte analítico integral del servidor: CPU, RAM (por proceso y por
     * programa), conexiones de red, MySQL (procesos activos, tamaño de BD,
     * consultas lentas) y ancho de banda + peticiones por dominio.
     *
     * @return array<string, mixed>
     */
    public function analytics(Server $server): array
    {
        // Mapear cada log de acceso a sus dominios (para el desglose por dominio)
        $sites   = $this->sites($server);
        $logMap  = [];   // access_log => [dominios]
        foreach ($sites as $s) {
            $logMap[$s['access_log']] = array_merge($logMap[$s['access_log']] ?? [], $s['domains']);
        }
        $logs = array_keys($logMap);
        // Si no hay sitios, usar el log por defecto
        if (empty($logs)) {
            $logs = ['/var/log/nginx/access.log'];
        }
        $logArgs = implode(' ', array_map('escapeshellarg', $logs));

        $raw      = $this->ssh->run($server, $this->analyticsScript($logArgs));
        $sections = $this->splitSections($raw);

        // Ancho de banda / peticiones por log → mapear a dominios
        $perLog = $this->parseBandwidthLogs($sections['BANDWIDTH'] ?? '');
        $domains = [];
        foreach ($perLog as $log => $stats) {
            $names = $logMap[$log] ?? [basename($log)];
            $domains[] = [
                'label'    => implode(', ', $names),
                'requests' => $stats['req'],
                'bytes'    => $stats['bytes'],
                'human'    => $this->humanBytes($stats['bytes']),
                'ips'      => $stats['ips'],
            ];
        }
        usort($domains, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);

        $totalReq   = array_sum(array_column($domains, 'requests'));
        $totalBytes = array_sum(array_column($domains, 'bytes'));

        $mysqlProc = $this->parsePipeTable($sections['MYSQLPROC'] ?? '', ['id', 'user', 'db', 'time', 'state', 'info']);
        $mysqlDb   = $this->parsePipeTable($sections['MYSQLDB'] ?? '', ['db', 'mb']);
        $mysqlTop  = $this->parsePipeTable($sections['MYSQLTOP'] ?? '', ['db', 'total_s', 'execs', 'avg_ms', 'query']);
        $mysqlVia  = trim($sections['MYSQLVIA'] ?? '');

        return [
            'top_summary' => $this->nonEmptyLines($sections['TOPSUMMARY'] ?? ''),
            'cpu'         => $this->parsePsLines($sections['CPUTOP'] ?? ''),
            'mem'         => $this->parsePsLines($sections['MEMTOP'] ?? ''),
            'mem_by_prog' => $this->parseMemByProgram($sections['MEMPROG'] ?? ''),
            'connections' => $this->parseCountLines($this->reorderCount($sections['CONN'] ?? '')),
            'top_ips'     => $this->parseCountLines($sections['TOPIPS'] ?? ''),
            'domains'     => $domains,
            'total_requests' => $totalReq,
            'total_bandwidth' => $this->humanBytes($totalBytes),
            'heavy_paths' => $this->parseBytesPaths($sections['HEAVYPATHS'] ?? ''),
            'mysql_available' => ! empty($mysqlDb) || ! empty($mysqlProc) || ! empty($mysqlTop),
            'mysql_via'       => $mysqlVia !== '' ? $mysqlVia : null,
            'mysql_processes' => $mysqlProc,
            'mysql_databases' => $mysqlDb,
            'mysql_top'       => $mysqlTop,
        ];
    }

    /**
     * Activa el registro de consultas lentas (slow query log) de MySQL/MariaDB
     * en caliente y lo deja persistente. Acción explícita de configuración.
     *
     * @return array{ok: bool, message: string}
     */
    public function enableSlowQueryLog(Server $server, int $longQueryTime = 1): array
    {
        $t = (int) $longQueryTime;
        $script = <<<SH
if ! command -v mysql >/dev/null 2>&1; then echo "NO_MYSQL"; exit 0; fi
# Activar en caliente
HOT=\$(mysql -e "SET GLOBAL slow_query_log='ON'; SET GLOBAL long_query_time={$t};" 2>&1)
if [ -n "\$HOT" ]; then echo "HOT_ERR:\$HOT"; exit 0; fi
# Persistir en el drop-in de configuración adecuado
DIR=""
for d in /etc/mysql/conf.d /etc/my.cnf.d /etc/mysql/mariadb.conf.d; do [ -d "\$d" ] && DIR="\$d" && break; done
if [ -n "\$DIR" ]; then
  printf '[mysqld]\nslow_query_log = 1\nlong_query_time = {$t}\n' > "\$DIR/gestoru-slowlog.cnf" 2>/dev/null && echo "PERSISTED:\$DIR" || echo "PERSIST_FAIL"
else
  echo "NO_CONFDIR"
fi
# Verificar
mysql -N -e "SELECT @@slow_query_log" 2>/dev/null
SH;

        $out = $this->ssh->run($server, $script);

        if (str_contains($out, 'NO_MYSQL')) {
            return ['ok' => false, 'message' => 'No tiene MySQL/MariaDB instalado (se omite).'];
        }
        if (str_contains($out, 'HOT_ERR')) {
            $err = trim(str_replace('HOT_ERR:', '', strtok($out, "\n")));

            return ['ok' => false, 'message' => 'MySQL rechazó el comando: '.$err];
        }

        $active    = preg_match('/^\s*1\s*$/m', $out) === 1;
        $persisted = str_contains($out, 'PERSISTED:');

        if ($active) {
            $msg = 'Slow log activado';
            $msg .= $persisted ? ' y hecho permanente.' : ' (activo, pero no pude dejarlo permanente — se pierde al reiniciar MySQL).';

            return ['ok' => true, 'message' => $msg];
        }

        return ['ok' => false, 'message' => 'No se pudo confirmar la activación.'];
    }

    // ── Scripts remotos ─────────────────────────────────────────────────────

    private function analyticsScript(string $logArgs): string
    {
        return <<<SH
TODAY=\$(date '+%d/%b/%Y')
echo "==TOPSUMMARY=="
top -bn1 2>/dev/null | head -5
echo "==CPUTOP=="
ps aux --sort=-%cpu 2>/dev/null | head -14
echo "==MEMTOP=="
ps aux --sort=-%mem 2>/dev/null | head -14
echo "==MEMPROG=="
ps -eo rss,comm --no-headers 2>/dev/null | awk '{a[\$2]+=\$1} END{for(k in a) print a[k]"\t"k}' | sort -rn | head -12
echo "==CONN=="
ss -tan 2>/dev/null | awk 'NR>1{s[\$1]++} END{for(k in s) print s[k]"\t"k}' | sort -rn
echo "==TOPIPS=="
ss -tan state established 2>/dev/null | awk 'NR>1{print \$4}' | sed 's/:[0-9]*\$//' | sort | uniq -c | sort -rn | head -8

# Ancho de banda: logs de los vhosts + todos los access log de nginx/apache/caddy
LOGS="{$logArgs}"
for extra in /var/log/nginx/*access*.log /var/log/apache2/*access*.log /var/log/httpd/*access*.log /var/log/caddy/*.log; do
  [ -f "\$extra" ] && LOGS="\$LOGS \$extra"
done
LOGS=\$(printf '%s\n' \$LOGS | awk '!seen[\$0]++')
echo "==BANDWIDTH=="
for L in \$LOGS; do
  if [ -f "\$L" ]; then
    awk -v d="[\$TODAY" -v log="\$L" '\$0 ~ d {req++; ip[\$1]=1; b+=\$10} END{if(req>0){n=0; for(k in ip)n++; print log"\t"req+0"\t"b+0"\t"n}}' "\$L"
  fi
done
echo "==HEAVYPATHS=="
for L in \$LOGS; do
  [ -f "\$L" ] && awk -v d="[\$TODAY" '\$0 ~ d {u=\$7; sub(/\\?.*/,"",u); bytes[u]+=\$10; cnt[u]++} END{for(k in bytes) print bytes[k]"\t"cnt[k]"\t"k}' "\$L"
done | sort -rn | head -12

# ── MySQL: en el host o dentro de un contenedor Docker ──────────────────────
MYSQL=""; VIA=""
if command -v mysql >/dev/null 2>&1 && mysql -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL="mysql"; VIA="host"
elif command -v docker >/dev/null 2>&1; then
  for c in \$(docker ps --format '{{.Names}}' 2>/dev/null | grep -Ei 'mysql|mariadb|maria|percona|db|database'); do
    docker exec "\$c" sh -c 'command -v mysql || command -v mariadb' >/dev/null 2>&1 || continue
    PW=\$(docker exec "\$c" sh -c 'printf %s "\${MYSQL_ROOT_PASSWORD:-\$MARIADB_ROOT_PASSWORD}"' 2>/dev/null)
    if [ -n "\$PW" ]; then TRY="docker exec \$c mysql -uroot -p\$PW"; else TRY="docker exec \$c mysql"; fi
    if \$TRY -e "SELECT 1" >/dev/null 2>&1; then MYSQL="\$TRY"; VIA="docker: \$c"; break; fi
  done
fi
echo "==MYSQLVIA=="
[ -n "\$MYSQL" ] && echo "\$VIA"
echo "==MYSQLPROC=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT id,user,COALESCE(db,'-'),time,COALESCE(state,'-'),COALESCE(LEFT(info,80),'-') FROM information_schema.processlist WHERE command<>'Sleep' AND info IS NOT NULL ORDER BY time DESC LIMIT 15" 2>/dev/null
echo "==MYSQLDB=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT table_schema, ROUND(SUM(data_length+index_length)/1048576,1) FROM information_schema.tables GROUP BY table_schema ORDER BY 2 DESC LIMIT 12" 2>/dev/null
echo "==MYSQLTOP=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT COALESCE(SCHEMA_NAME,'-'), ROUND(SUM_TIMER_WAIT/1000000000000,1), COUNT_STAR, ROUND(SUM_TIMER_WAIT/COUNT_STAR/1000000000,1), LEFT(REPLACE(REPLACE(DIGEST_TEXT,'\\n',' '),'\\t',' '),90) FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME IS NOT NULL AND DIGEST_TEXT IS NOT NULL ORDER BY SUM_TIMER_WAIT DESC LIMIT 12" 2>/dev/null
SH;
    }

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
  # nginx: configuración efectiva completa (incluye todos los include/)
  nginx -T 2>/dev/null | grep -hoE 'server_name[[:space:]]+[^;]+;' | sed 's/server_name//;s/;//' | tr ' ' '\n'
  # nginx: por si -T no está disponible, leer archivos directamente
  grep -rhoE 'server_name[[:space:]]+[^;]+;' /etc/nginx 2>/dev/null | sed 's/server_name//;s/;//' | tr ' ' '\n'
  # Apache: virtual hosts efectivos y directivas
  { apache2ctl -S 2>/dev/null || apachectl -S 2>/dev/null || httpd -S 2>/dev/null; } | grep -oE 'namevhost [^ ]+' | awk '{print $2}'
  grep -rhoE '(ServerName|ServerAlias)[[:space:]]+[^ ]+' /etc/apache2 /etc/httpd 2>/dev/null | awk '{print $2}'
  # Certificados Let's Encrypt = dominios con HTTPS (fuente muy confiable)
  ls /etc/letsencrypt/live 2>/dev/null | grep -v README
  # Docker: dominios en labels de Traefik y variables VIRTUAL_HOST
  if command -v docker >/dev/null 2>&1; then
    for c in $(docker ps -q 2>/dev/null); do
      docker inspect "$c" --format '{{range .Config.Env}}{{println .}}{{end}}{{range $k,$v := .Config.Labels}}{{println $v}}{{end}}' 2>/dev/null
    done | grep -oE '([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}'
  fi
} 2>/dev/null | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -vE '^\*|^$' | sort -u
SH;
    }

    // ── Parsers y helpers ───────────────────────────────────────────────────

    /**
     * Divide una salida con marcadores ==NOMBRE== en secciones.
     *
     * @return array<string, string>
     */
    private function splitSections(string $raw): array
    {
        $sections = [];
        $current  = null;

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^==([A-Z0-9_]+)==\s*$/', trim($line), $m)) {
                $current = $m[1];
                $sections[$current] = '';
                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }

        return $sections;
    }

    /**
     * Parsea líneas tipo "  123 valor" (salida de uniq -c) a [{count, value}].
     *
     * @return array<int, array{count: int, value: string}>
     */
    private function parseCountLines(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $m)) {
                $rows[] = ['count' => (int) $m[1], 'value' => trim($m[2])];
            }
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function nonEmptyLines(string $raw): array
    {
        return array_values(array_filter(
            preg_split('/\r?\n/', $raw),
            fn ($l) => trim($l) !== ''
        ));
    }

    /**
     * Parsea la salida de `ps aux` a filas estructuradas.
     *
     * @return array<int, array<string, string>>
     */
    private function parsePsLines(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $parts = preg_split('/\s+/', trim($line), 11);
            if (count($parts) < 11 || $parts[0] === 'USER') {
                continue;
            }
            $cmd = $parts[10];
            // Excluir el propio comando de medición (ps ... --sort) para no
            // reportarlo como "el que más consume".
            if (preg_match('/\bps\b.*--sort=|^ps\s|--sort=-%(cpu|mem)/', $cmd)) {
                continue;
            }
            $rows[] = [
                'user'    => $parts[0],
                'pid'     => $parts[1],
                'cpu'     => $parts[2],
                'mem'     => $parts[3],
                'command' => mb_strimwidth($cmd, 0, 90, '…'),
            ];
        }

        return $rows;
    }

    /**
     * Parser tolerante de bloques server{} de nginx.
     *
     * @return array<int, array{domains: array<int,string>, root: ?string, access_log: string, error_log: string}>
     */
    private function parseNginxSites(string $conf): array
    {
        $sites = [];
        $len   = strlen($conf);
        $i     = 0;

        while (($pos = strpos($conf, 'server', $i)) !== false) {
            $j = $pos + 6;
            while ($j < $len && ctype_space($conf[$j])) {
                $j++;
            }
            if ($j >= $len || $conf[$j] !== '{') {
                $i = $pos + 6;
                continue;
            }

            // Balancear llaves para extraer el bloque completo
            $depth = 0;
            $k     = $j;
            for (; $k < $len; $k++) {
                if ($conf[$k] === '{') {
                    $depth++;
                } elseif ($conf[$k] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $block = substr($conf, $j + 1, max(0, $k - $j - 1));
            $i     = $k + 1;

            $domains = [];
            if (preg_match_all('/^\s*server_name\s+([^;]+);/m', $block, $m)) {
                foreach ($m[1] as $names) {
                    foreach (preg_split('/\s+/', trim($names)) as $d) {
                        if ($d !== '' && $d !== '_' && $d !== 'localhost' && ! str_starts_with($d, '*')) {
                            $domains[] = $d;
                        }
                    }
                }
            }
            if (empty($domains)) {
                continue;
            }

            $first = fn (string $directive) => preg_match('/^\s*'.$directive.'\s+([^;\s]+)/m', $block, $mm) ? $mm[1] : null;

            $accessLog = $first('access_log');
            $errorLog  = $first('error_log');

            $sites[] = [
                'domains'    => array_values(array_unique($domains)),
                'root'       => $first('root'),
                'access_log' => ($accessLog && $accessLog !== 'off') ? $accessLog : '/var/log/nginx/access.log',
                'error_log'  => ($errorLog && $errorLog !== 'off') ? $errorLog : '/var/log/nginx/error.log',
            ];
        }

        // Si un dominio aparece en varios bloques (80 y 443), preferir el que tenga root
        $byDomain = [];
        foreach ($sites as $site) {
            foreach ($site['domains'] as $d) {
                if (! isset($byDomain[$d]) || ($site['root'] && ! $byDomain[$d]['root'])) {
                    $byDomain[$d] = $site;
                }
            }
        }

        $unique = [];
        foreach ($byDomain as $site) {
            $key = implode('|', $site['domains']).'|'.($site['root'] ?? '');
            $unique[$key] = $site;
        }

        return array_values($unique);
    }

    /**
     * Parsea líneas "log\treq\tbytes\tips" del bloque BANDWIDTH.
     *
     * @return array<string, array{req: int, bytes: int, ips: int}>
     */
    private function parseBandwidthLogs(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $p = explode("\t", $line);
            if (count($p) >= 4) {
                $out[$p[0]] = ['req' => (int) $p[1], 'bytes' => (int) $p[2], 'ips' => (int) $p[3]];
            }
        }

        return $out;
    }

    /**
     * Parsea "rssKB\tprograma" a filas con memoria legible.
     *
     * @return array<int, array{program: string, bytes: int, human: string}>
     */
    private function parseMemByProgram(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $p = explode("\t", $line, 2);
            if (count($p) === 2 && is_numeric(trim($p[0]))) {
                $bytes = (int) trim($p[0]) * 1024;
                $rows[] = ['program' => trim($p[1]), 'bytes' => $bytes, 'human' => $this->humanBytes($bytes)];
            }
        }

        return $rows;
    }

    /**
     * Parsea "bytes\tcount\tpath" (rutas más pesadas por ancho de banda).
     *
     * @return array<int, array{bytes: int, human: string, count: int, path: string}>
     */
    private function parseBytesPaths(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $p = explode("\t", $line, 3);
            if (count($p) === 3 && is_numeric(trim($p[0]))) {
                $bytes = (int) trim($p[0]);
                $rows[] = [
                    'bytes' => $bytes,
                    'human' => $this->humanBytes($bytes),
                    'count' => (int) trim($p[1]),
                    'path'  => trim($p[2]),
                ];
            }
        }

        return $rows;
    }

    /**
     * Convierte "count\tvalue" (salida de awk) a "count value" (para parseCountLines).
     */
    private function reorderCount(string $raw): string
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $p = explode("\t", $line, 2);
            if (count($p) === 2) {
                $out[] = trim($p[0]).' '.trim($p[1]);
            }
        }

        return implode("\n", $out);
    }

    /**
     * Parsea una tabla separada por tabuladores (salida de mysql -B).
     *
     * @param  array<int, string>  $cols
     * @return array<int, array<string, string>>
     */
    private function parsePipeTable(string $raw, array $cols): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < count($cols)) {
                continue;
            }
            $row = [];
            foreach ($cols as $i => $c) {
                $row[$c] = $parts[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Parser de bloques <VirtualHost> de Apache.
     *
     * @return array<int, array{domains: array<int,string>, root: ?string, access_log: string, error_log: string}>
     */
    private function parseApacheSites(string $conf): array
    {
        if (trim($conf) === '') {
            return [];
        }

        $sites = [];
        if (! preg_match_all('/<VirtualHost[^>]*>(.*?)<\/VirtualHost>/is', $conf, $blocks)) {
            return [];
        }

        foreach ($blocks[1] as $block) {
            $domains = [];
            if (preg_match('/^\s*ServerName\s+([^\s:]+)/im', $block, $m)) {
                $domains[] = strtolower($m[1]);
            }
            if (preg_match_all('/^\s*ServerAlias\s+(.+)$/im', $block, $mm)) {
                foreach ($mm[1] as $aliases) {
                    foreach (preg_split('/\s+/', trim($aliases)) as $a) {
                        if ($a !== '') {
                            $domains[] = strtolower($a);
                        }
                    }
                }
            }
            $domains = array_values(array_filter(array_unique($domains), fn ($d) => $this->isRealDomain($d)));
            if (empty($domains)) {
                continue;
            }

            $first = fn (string $dir, string $def) => preg_match('/^\s*'.$dir.'\s+"?([^\s"]+)/im', $block, $x) ? $x[1] : $def;

            // CustomLog "ruta" formato → tomamos la ruta
            $accessLog = preg_match('/^\s*CustomLog\s+"?([^\s"]+)/im', $block, $c) ? $c[1] : '/var/log/apache2/access.log';

            $sites[] = [
                'domains'    => $domains,
                'root'       => $first('DocumentRoot', ''),
                'access_log' => $accessLog,
                'error_log'  => $first('ErrorLog', '/var/log/apache2/error.log'),
            ];
        }

        return $sites;
    }

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

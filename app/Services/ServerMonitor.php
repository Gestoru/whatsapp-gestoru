<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Recoge, a través de SSH, todo lo que el dashboard muestra de un servidor:
 * rendimiento, proyectos instalados, dominios y navegación de archivos.
 * Todos los comandos son de solo lectura.
 */
class ServerMonitor
{
    public function __construct(private SshClient $ssh) {}

    /**
     * Ejecuta un comando de solo lectura por SSH y cachea su salida cruda
     * unos segundos. Hace que recargar el dashboard sea casi instantáneo y
     * que escale a muchos servidores sin abrir SSH en cada clic.
     */
    private function cachedRun(Server $server, string $tag, int $ttl, string $script, ?int $timeout = null): string
    {
        return Cache::remember(
            "srvmon:{$server->id}:{$tag}",
            $ttl,
            fn () => $this->ssh->run($server, $script, $timeout)
        );
    }

    /**
     * Métricas de rendimiento del servidor.
     *
     * @return array<string, mixed>
     */
    public function metrics(Server $server): array
    {
        $raw = $this->cachedRun($server, 'metrics', 15, $this->metricsScript());
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
            // Usuarios activos ahora: IPs de cliente únicas conectadas a la web
            // (puertos 80/443). web_conns = conexiones totales establecidas.
            'web_users' => isset($kv['WEB_USERS']) ? (int) $kv['WEB_USERS'] : null,
            'web_conns' => isset($kv['WEB_CONNS']) ? (int) $kv['WEB_CONNS'] : null,
        ];
    }

    /**
     * Visitantes en vivo: IPs de cliente conectadas ahora a la web (80/443),
     * incluyendo conexiones recién cerradas (time-wait ≈ último minuto), con
     * su número de conexiones. Es la base del panel «Usuarios en vivo».
     *
     * @return array{visitors: array<int, array{ip: string, conns: int}>, diag: array<string, mixed>}
     */
    public function liveVisitors(Server $server): array
    {
        // Dos fuentes: ss (sockets del host) y conntrack (flujos NAT hacia
        // contenedores Docker que ss no ve). Cada una filtra IPs privadas y las
        // del propio servidor. Se emite además un bloque de diagnóstico.
        $script = <<<'SH'
LIPS=$(hostname -I 2>/dev/null)
webflt(){ awk -v L="$LIPS" 'BEGIN{n=split(L,A," ");for(i=1;i<=n;i++)loc[A[i]]=1}{ip=$1; if(ip=="")next; if(ip in loc)next; if(ip ~ /^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/)next; if(ip ~ /^(::1|::ffff:127|fe80|fd)/)next; print ip}'; }
echo "==SS=="
ss -tan state established state time-wait 2>/dev/null | awk 'NR>1{loc=$(NF-1); if(loc ~ /:(80|443)$/){ip=$NF; sub(/:[0-9]+$/,"",ip); gsub(/[][]/,"",ip); print ip}}' | webflt
echo "==CT=="
(cat /proc/net/nf_conntrack 2>/dev/null || cat /proc/net/ip_conntrack 2>/dev/null || conntrack -L -p tcp 2>/dev/null) | awk '/ESTABLISHED|TIME_WAIT/{o="";d="";for(i=1;i<=NF;i++){if(o==""&&$i~/^src=/)o=substr($i,5);if(d==""&&$i~/^dport=/)d=substr($i,7)};if((d=="80"||d=="443")&&o!="")print o}' | webflt
echo "==NS=="
if command -v docker >/dev/null 2>&1 && command -v nsenter >/dev/null 2>&1; then
  for pid in $(docker ps -q 2>/dev/null | xargs -r -I{} docker inspect -f '{{.State.Pid}}' {} 2>/dev/null); do
    [ "$pid" = "0" ] && continue
    nsenter -t "$pid" -n ss -H -tan state established state time-wait 2>/dev/null
  done | awk '{lp=$(NF-1); pr=$NF; nn=split(lp,LA,":"); lport=LA[nn]+0; mm=split(pr,PA,":"); pport=PA[mm]+0; peer=pr; sub(/:[0-9]+$/,"",peer); gsub(/[][]/,"",peer); if(peer!="" && lport>0 && pport>0 && lport<pport) print peer}' | webflt
fi
echo "==DIAG=="
echo "ct_file=$([ -r /proc/net/nf_conntrack ] && echo 1 || echo 0)"
echo "ct_bin=$(command -v conntrack >/dev/null 2>&1 && echo 1 || echo 0)"
echo "docker=$(command -v docker >/dev/null 2>&1 && echo 1 || echo 0)"
echo "nsenter=$(command -v nsenter >/dev/null 2>&1 && echo 1 || echo 0)"
echo "containers=$(docker ps -q 2>/dev/null | wc -l | tr -d ' ')"
echo "ct_lines=$( { cat /proc/net/nf_conntrack 2>/dev/null || conntrack -L -p tcp 2>/dev/null; } | grep -c . )"
SH;
        $raw = $this->cachedRun($server, 'livevisitors', 10, $script);
        $sec = $this->splitSections($raw);

        $counts = [];
        foreach (array_merge(
            $this->nonEmptyLines($sec['SS'] ?? ''),
            $this->nonEmptyLines($sec['CT'] ?? ''),
            $this->nonEmptyLines($sec['NS'] ?? '')
        ) as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $counts[$ip] = ($counts[$ip] ?? 0) + 1;
            }
        }
        arsort($counts);

        $visitors = [];
        foreach (array_slice($counts, 0, 300, true) as $ip => $conns) {
            $visitors[] = ['ip' => $ip, 'conns' => $conns];
        }

        $d = $this->parseKeyValues($sec['DIAG'] ?? '');

        return [
            'visitors' => $visitors,
            'diag' => [
                'ct_file'    => ($d['ct_file'] ?? '0') === '1',
                'ct_bin'     => ($d['ct_bin'] ?? '0') === '1',
                'docker'     => ($d['docker'] ?? '0') === '1',
                'nsenter'    => ($d['nsenter'] ?? '0') === '1',
                'containers' => (int) ($d['containers'] ?? 0),
                'ct_lines'   => (int) ($d['ct_lines'] ?? 0),
                'ss_count'   => count($this->nonEmptyLines($sec['SS'] ?? '')),
                'ct_count'   => count($this->nonEmptyLines($sec['CT'] ?? '')),
                'ns_count'   => count($this->nonEmptyLines($sec['NS'] ?? '')),
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
        $raw      = $this->cachedRun($server, 'projects', 600, $this->projectsScript());
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
     * Dominios detectados (lista plana). Deriva de siteMap().
     *
     * @return array<int, string>
     */
    public function domains(Server $server): array
    {
        return array_keys($this->siteMap($server)['domains']);
    }

    /**
     * Mapa de sitios del servidor: cada dominio REAL alojado aquí, con su
     * origen (dónde se encontró) y el proyecto Docker al que pertenece.
     * Solo lee fuentes autoritativas (nginx, apache, certificados y las
     * variables/etiquetas de Docker que denotan hosting) — nada de raspar
     * cualquier texto de las variables de entorno.
     *
     * @return array{domains: array<string, array{sources: array<int,string>, projects: array<int,string>}>, byProject: array<string, array<int,string>>}
     */
    public function siteMap(Server $server): array
    {
        $raw = $this->cachedRun($server, 'sitemap', 600, $this->domainsScript());
        $s   = $this->splitSections($raw);

        $domains = [];
        foreach ($this->nonEmptyLines($s['MAP'] ?? '') as $line) {
            $p = explode("\t", $line);
            if (count($p) < 3) {
                continue;
            }
            [$source, $project, $name] = $p;

            $name = strtolower(trim($name));
            $name = preg_replace('/^\*\./', '', $name);   // *.dominio.com → dominio.com
            $name = preg_replace('#^https?://#', '', $name);
            $name = preg_replace('#[/:].*$#', '', $name);  // quita puerto y ruta
            $name = trim($name, '.');

            if (! $this->isRealDomain($name)) {
                continue;
            }

            $domains[$name]['sources'][$source] = true;
            if ($project !== '' && $project !== '-') {
                $domains[$name]['projects'][$project] = true;
            }
        }

        ksort($domains);

        $out = [];
        $byProject = [];
        foreach ($domains as $name => $info) {
            $sources  = array_keys($info['sources'] ?? []);
            $projects = array_keys($info['projects'] ?? []);
            $out[$name] = ['sources' => $sources, 'projects' => $projects];
            foreach ($projects as $proj) {
                $byProject[$proj][$name] = true;
            }
        }

        return [
            'domains'   => $out,
            'byProject' => array_map(fn ($m) => array_keys($m), $byProject),
        ];
    }

    /**
     * Filtra lo que NO es un dominio real alojado aquí: nombres de archivo
     * disfrazados (.png, .yml, .env…), placeholders y dominios de servicios
     * de terceros que las apps solo consumen (github.com, gmail.com…).
     */
    private function isRealDomain(string $d): bool
    {
        $d = ltrim($d, '*.');
        if ($d === '' || str_starts_with($d, '_')) {
            return false;
        }

        // Forma de dominio válida (etiqueta.tld)
        if (! preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $d)) {
            return false;
        }

        // "TLD" que en realidad es una extensión de archivo o basura de config
        $parts = explode('.', $d);
        $tld   = end($parts);
        $fakeTlds = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'css', 'js', 'mjs',
            'cjs', 'json', 'yml', 'yaml', 'env', 'xml', 'txt', 'md', 'sh', 'bash', 'conf',
            'cfg', 'ini', 'toml', 'lock', 'sql', 'gz', 'xz', 'tar', 'zip', 'tgz', 'asc',
            'key', 'pem', 'crt', 'cert', 'log', 'tpl', 'php', 'html', 'htm', 'ts', 'tsx',
            'jsx', 'map', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'pdf', 'csv', 'bak', 'db',
            'sqlite', 'sqlite3', 'dist', 'sample', 'example', 'tmpl', 'mp4', 'mp3', 'webm',
            'py', 'rb', 'go', 'rs', 'java', 'class', 'jar', 'war', 'deb', 'rpm', 'iso',
            'img', 'bin', 'dat', 'old', 'swp', 'pid', 'sock', 'el', 'vue', 'scss', 'less'];
        if (in_array($tld, $fakeTlds, true)) {
            return false;
        }

        // Placeholders, dominios locales/internos
        $blacklist = ['localhost', 'example.com', 'example.org', 'example.net', 'test.com',
            'domain.tld', 'yourdomain.com', 'localhost.localdomain', 'host.docker.internal'];
        if (in_array($d, $blacklist, true)) {
            return false;
        }
        foreach (['.local', '.localdomain', '.internal', '.svc', '.arpa', '.test', '.invalid', '.example'] as $suf) {
            if (str_ends_with($d, $suf)) {
                return false;
            }
        }

        // Servicios de terceros que las apps CONSUMEN (no alojan aquí)
        $services = ['github.com', 'githubusercontent.com', 'gmail.com', 'hotmail.com',
            'outlook.com', 'yahoo.com', 'slack.com', 'php.net', 'portainer.io', 'docker.io',
            'docker.com', 'frankenphp.dev', 'dapta.ai', 'digitaloceanspaces.com',
            'contabostorage.com', 'googleapis.com', 'google.com', 'gstatic.com',
            'cloudflare.com', 'cloudflare.net', 'jsdelivr.net', 'amazonaws.com', 'sentry.io',
            'npmjs.com', 'unpkg.com', 'letsencrypt.org', 'ubuntu.com', 'debian.org',
            'nginx.org', 'nginx.com', 'apache.org', 'mysql.com', 'mariadb.org', 'redis.io',
            'mongodb.com', 'cloudinary.com', 'sendgrid.net', 'mailgun.org', 'twilio.com',
            'stripe.com', 'paypal.com', 'facebook.com', 'fbcdn.net', 'twitter.com', 'x.com',
            'linkedin.com', 'microsoft.com', 'office.com', 'apple.com', 'mozilla.org',
            'w3.org', 'schema.org', 'bootstrapcdn.com', 'fontawesome.com'];
        foreach ($services as $svc) {
            if ($d === $svc || str_ends_with($d, '.'.$svc)) {
                return false;
            }
        }

        return true;
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
        $raw = $this->cachedRun(
            $server,
            'sites',
            600,
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

        $raw      = $this->cachedRun($server, 'domreport:'.md5($site['access_log']), 60, $script);
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
        $raw = $this->cachedRun($server, 'topproc', 30, 'echo "==CPU=="; ps aux --sort=-%cpu 2>/dev/null | head -9; echo "==MEM=="; ps aux --sort=-%mem 2>/dev/null | head -9');
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

        $raw = $this->cachedRun($server, 'slow', 300, $script);
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

        // 45 s de margen: el script recorre logs y contenedores; con el timeout
        // corto por defecto la salida se cortaba y MySQL aparecía "no disponible".
        $raw      = $this->cachedRun($server, 'analytics', 120, $this->analyticsScript($logArgs), 45);
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
        // Sitios dentro de contenedores Docker (su access log vive en el contenedor)
        foreach ($this->parseBandwidthLogs($sections['DOCKERBW'] ?? '') as $container => $stats) {
            $domains[] = [
                'label'    => '🐳 '.$container,
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
        $mysqlTop  = $this->parsePipeTable($sections['MYSQLTOP'] ?? '', ['db', 'total_s', 'execs', 'avg_ms', 'last_seen', 'query']);
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
     * Estado rápido de MySQL/MariaDB (host o Docker): conexiones y consultas
     * en ejecución. Ligero, pensado para el muestreo periódico.
     *
     * @return array{available: bool, connections: ?int, running: ?int}
     */
    public function mysqlStatus(Server $server): array
    {
        $script = <<<'SH'
MYSQL=""
if command -v mysql >/dev/null 2>&1 && mysql -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL="mysql"
elif command -v docker >/dev/null 2>&1; then
  for c in $(docker ps --format '{{.Names}}' 2>/dev/null | grep -Ei 'mysql|mariadb|maria|percona|db|database'); do
    docker exec "$c" sh -c 'command -v mysql || command -v mariadb' >/dev/null 2>&1 || continue
    PW=$(docker exec "$c" sh -c 'printf %s "${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD}"' 2>/dev/null)
    if [ -n "$PW" ]; then TRY="docker exec $c mysql -uroot -p$PW"; else TRY="docker exec $c mysql"; fi
    if $TRY -e "SELECT 1" >/dev/null 2>&1; then MYSQL="$TRY"; break; fi
  done
fi
[ -z "$MYSQL" ] && { echo "NO"; exit 0; }
$MYSQL -N -B -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running')" 2>/dev/null
SH;

        $raw = $this->cachedRun($server, 'mysqlstatus', 15, $script);

        if (str_contains($raw, 'NO')) {
            return ['available' => false, 'connections' => null, 'running' => null];
        }

        $kv = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 2) {
                $kv[$p[0]] = (int) $p[1];
            }
        }

        if (! isset($kv['Threads_connected'])) {
            return ['available' => false, 'connections' => null, 'running' => null];
        }

        return [
            'available'   => true,
            'connections' => $kv['Threads_connected'] ?? null,
            'running'     => $kv['Threads_running'] ?? null,
        ];
    }

    /**
     * Foto INSTANTÁNEA para atribuir un pico (sin caché): procesos por consumo
     * ACTUAL (top, no el promedio de vida que da ps) y contenedores Docker por
     * CPU del momento. Es lo que responde "¿quién está consumiendo AHORA?".
     *
     * @return array{top: array{cpu: array, mem: array}, containers: array<int, array{name: string, cpu: float, mem: float}>}
     */
    public function peakSnapshot(Server $server): array
    {
        $script = <<<'SH'
echo "==PROC=="
top -bn2 -d 0.7 -c -w 400 2>/dev/null | awk '/^top -/{blk++} blk==2 && $1 ~ /^[0-9]+$/ && $9+0 > 0 {cmd=""; for(i=12;i<=NF;i++) cmd=cmd (i>12?" ":"") $i; print $9"\t"$10"\t"$1"\t"$2"\t"cmd}' | sort -rn | head -8
echo "==CONT=="
command -v docker >/dev/null 2>&1 && timeout 8 docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemPerc}}' 2>/dev/null
SH;

        $s = $this->splitSections($this->ssh->run($server, $script, 30));

        $procs = [];
        foreach ($this->nonEmptyLines($s['PROC'] ?? '') as $line) {
            $p = explode("\t", $line);
            if (count($p) < 5 || str_contains($p[4], 'top -bn2')) {
                continue;
            }
            $procs[] = ['cpu' => $p[0], 'mem' => $p[1], 'pid' => $p[2], 'user' => $p[3], 'command' => trim($p[4])];
        }

        $containers = [];
        foreach ($this->nonEmptyLines($s['CONT'] ?? '') as $line) {
            $p = explode("\t", $line);
            if (count($p) < 3) {
                continue;
            }
            $containers[] = [
                'name' => $p[0],
                'cpu'  => (float) rtrim($p[1], '%'),
                'mem'  => (float) rtrim($p[2], '%'),
            ];
        }
        usort($containers, fn ($a, $b) => $b['cpu'] <=> $a['cpu']);

        $byMem = $procs;
        usort($byMem, fn ($a, $b) => (float) $b['mem'] <=> (float) $a['mem']);

        return ['top' => ['cpu' => $procs, 'mem' => $byMem], 'containers' => $containers];
    }

    /**
     * Actividad de MySQL EN VIVO (sin caché): conexiones, consultas en curso
     * y el detalle de cada una. Alimenta el refresco automático del panel.
     *
     * @return array<string, mixed>
     */
    public function mysqlLive(Server $server): array
    {
        $script = $this->mysqlDetectSnippet()."\n".<<<'SH'
echo "==MLVIA=="
[ -n "$MYSQL" ] && echo "$VIA"
echo "==MLSTATUS=="
[ -n "$MYSQL" ] && $MYSQL -N -B -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running')" 2>/dev/null
echo "==MLPROC=="
[ -n "$MYSQL" ] && $MYSQL -N -B -e "SELECT time, COALESCE(db,'-'), user, COALESCE(LEFT(REPLACE(REPLACE(info,'\n',' '),'\t',' '),160),'-') FROM information_schema.processlist WHERE command<>'Sleep' AND info IS NOT NULL AND info NOT LIKE '%information_schema.processlist%' ORDER BY time DESC LIMIT 15" 2>/dev/null
SH;

        $s = $this->splitSections($this->ssh->run($server, $script));

        if (trim($s['MLVIA'] ?? '') === '') {
            return ['available' => false, 'connections' => null, 'running' => null, 'processes' => []];
        }

        $kv = [];
        foreach ($this->nonEmptyLines($s['MLSTATUS'] ?? '') as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 2) {
                $kv[$p[0]] = (int) $p[1];
            }
        }

        return [
            'available'   => true,
            'connections' => $kv['Threads_connected'] ?? null,
            'running'     => $kv['Threads_running'] ?? null,
            'processes'   => $this->parsePipeTable($s['MLPROC'] ?? '', ['time', 'db', 'user', 'info']),
        ];
    }

    /**
     * Reporte profundo de consultas para el optimizador con IA: estadísticas
     * completas por consulta (performance_schema), usuarios que las ejecutan
     * y estructura de las tablas involucradas.
     *
     * @return array<string, mixed>
     */
    public function queryReport(Server $server): array
    {
        $raw = $this->ssh->run($server, $this->queryReportScript());
        $s   = $this->splitSections($raw);

        $via = trim($s['QVIA'] ?? '');
        if ($via === '') {
            return ['available' => false];
        }

        // Offset (segundos vs UTC) del reloj de MySQL, para convertir sus horas
        // a la zona del panel (America/Bogota) al mostrarlas.
        $tzLine = trim($this->nonEmptyLines($s['QTZ'] ?? '')[0] ?? '');
        $mysqlOffset = is_numeric($tzLine) ? (int) $tzLine : 0;

        // Info del servidor MySQL (versión, uptime, buffer pool, conexiones)
        $version = null; $uptime = null; $buffer = null; $maxConn = null;
        foreach ($this->nonEmptyLines($s['QSERVER'] ?? '') as $line) {
            $p = preg_split('/\t+/', trim($line));
            if (($p[0] ?? '') === 'Uptime') {
                $uptime = (int) ($p[1] ?? 0);
            } elseif (count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                $buffer = (int) $p[0];
                $maxConn = (int) $p[1];
            } elseif ($version === null) {
                $version = trim($line);
            }
        }

        $queries = $this->parsePipeTable($s['QDIGESTS'] ?? '', [
            'digest', 'db', 'execs', 'total_s', 'avg_ms', 'max_ms', 'lock_s',
            'rows_examined', 'rows_sent', 'tmp_disk', 'tmp_mem', 'full_join',
            'full_scan', 'no_index', 'no_good_index', 'first_seen', 'last_seen', 'query',
        ]);
        $users = $this->parsePipeTable($s['QUSERS'] ?? '', ['user', 'execs', 'total_s']);

        // Usuario por consulta: muestra de la historia reciente (mejor esfuerzo,
        // el resumen por digest de MySQL no guarda el usuario).
        $userMap = [];
        foreach ($this->parsePipeTable($s['QDIGESTUSER'] ?? '', ['digest', 'user', 'n']) as $r) {
            $userMap[$r['digest']][] = $r['user'];
        }

        $allTables = [];
        foreach ($queries as &$q) {
            $q['users']  = array_values(array_unique($userMap[$q['digest']] ?? []));
            $q['tables'] = $this->extractTables($q['query'], $q['db']);
            foreach ($q['tables'] as $t) {
                $allTables[$t] = true;
            }
        }
        unset($q);

        return [
            'available'    => true,
            'via'          => $via,
            'version'      => $version,
            'uptime'       => $uptime,
            'buffer'       => $buffer,
            'max_conn'     => $maxConn,
            'mysql_offset' => $mysqlOffset,
            'queries'      => $queries,
            'users'        => $users,
            'ddl'          => $this->fetchTableDdl($server, array_slice(array_keys($allTables), 0, 15)),
        ];
    }

    /** Estructura (SHOW CREATE TABLE) y tamaño de tablas puntuales. */
    private function fetchTableDdl(Server $server, array $tables): array
    {
        if (empty($tables)) {
            return [];
        }

        $cmds = '';
        foreach ($tables as $i => $t) {
            [$db, $tb] = explode('.', $t, 2);
            $cmds .= "echo \"==T{$i}==\"\n";
            $cmds .= "[ -n \"\$MYSQL\" ] && \$MYSQL -N -B -e \"SHOW CREATE TABLE \\\`{$db}\\\`.\\\`{$tb}\\\`\" 2>/dev/null\n";
            $cmds .= "echo \"==I{$i}==\"\n";
            $cmds .= "[ -n \"\$MYSQL\" ] && \$MYSQL -N -B -e \"SELECT TABLE_ROWS, ROUND((DATA_LENGTH+INDEX_LENGTH)/1048576,1) FROM information_schema.tables WHERE table_schema='{$db}' AND table_name='{$tb}'\" 2>/dev/null\n";
        }

        $sec = $this->splitSections($this->ssh->run($server, $this->mysqlDetectSnippet()."\n".$cmds));

        $out = [];
        foreach ($tables as $i => $t) {
            $create = trim($sec["T{$i}"] ?? '');
            if ($create === '') {
                continue;
            }
            // -B entrega "tabla<TAB>CREATE TABLE..." con \n escapados
            $tab = strpos($create, "\t");
            $create = $tab !== false ? substr($create, $tab + 1) : $create;
            $create = str_replace(['\n', '\t'], ["\n", '  '], $create);

            $info = preg_split('/\t/', trim($sec["I{$i}"] ?? ''));
            $out[$t] = [
                'create' => $create,
                'rows'   => isset($info[0]) && is_numeric($info[0]) ? (int) $info[0] : null,
                'mb'     => isset($info[1]) && is_numeric($info[1]) ? (float) $info[1] : null,
            ];
        }

        return $out;
    }

    /**
     * Tablas mencionadas en una consulta normalizada (FROM/JOIN/UPDATE/INTO).
     *
     * @return array<int, string> pares "basededatos.tabla"
     */
    private function extractTables(string $query, string $defaultDb): array
    {
        $tables = [];
        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(\w+)`?(?:\s*\.\s*`?(\w+)`?)?/i', $query, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $db = ! empty($match[2]) ? $match[1] : $defaultDb;
                $tb = ! empty($match[2]) ? $match[2] : $match[1];
                if ($db === '' || $db === '-' || in_array(strtoupper($tb), ['SELECT', 'DUAL'], true)) {
                    continue;
                }
                $tables["{$db}.{$tb}"] = true;
            }
        }

        return array_keys($tables);
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

    /** Trozo de shell que deja en $MYSQL el cliente utilizable (host o Docker) y en $VIA cómo se llegó. */
    private function mysqlDetectSnippet(): string
    {
        return <<<'SH'
MYSQL=""; VIA=""
if command -v mysql >/dev/null 2>&1 && mysql -e "SELECT 1" >/dev/null 2>&1; then
  MYSQL="mysql"; VIA="host"
elif command -v docker >/dev/null 2>&1; then
  for c in $(docker ps --format '{{.Names}}' 2>/dev/null | grep -Ei 'mysql|mariadb|maria|percona|db|database'); do
    docker exec "$c" sh -c 'command -v mysql || command -v mariadb' >/dev/null 2>&1 || continue
    PW=$(docker exec "$c" sh -c 'printf %s "${MYSQL_ROOT_PASSWORD:-$MARIADB_ROOT_PASSWORD}"' 2>/dev/null)
    if [ -n "$PW" ]; then TRY="docker exec $c mysql -uroot -p$PW"; else TRY="docker exec $c mysql"; fi
    if $TRY -e "SELECT 1" >/dev/null 2>&1; then MYSQL="$TRY"; VIA="docker: $c"; break; fi
  done
fi
SH;
    }

    private function queryReportScript(): string
    {
        $detect = $this->mysqlDetectSnippet();

        return <<<SH
{$detect}
echo "==QVIA=="
[ -n "\$MYSQL" ] && echo "\$VIA"
echo "==QTZ=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())" 2>/dev/null
echo "==QSERVER=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT VERSION()" 2>/dev/null
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SHOW GLOBAL STATUS WHERE Variable_name='Uptime'" 2>/dev/null
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT @@innodb_buffer_pool_size, @@max_connections" 2>/dev/null
echo "==QDIGESTS=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT DIGEST, COALESCE(SCHEMA_NAME,'-'), COUNT_STAR, ROUND(SUM_TIMER_WAIT/1000000000000,1), ROUND(SUM_TIMER_WAIT/COUNT_STAR/1000000000,1), ROUND(MAX_TIMER_WAIT/1000000000,1), ROUND(SUM_LOCK_TIME/1000000000000,1), SUM_ROWS_EXAMINED, SUM_ROWS_SENT, SUM_CREATED_TMP_DISK_TABLES, SUM_CREATED_TMP_TABLES, SUM_SELECT_FULL_JOIN, SUM_SELECT_SCAN, SUM_NO_INDEX_USED, SUM_NO_GOOD_INDEX_USED, FIRST_SEEN, LAST_SEEN, LEFT(REPLACE(REPLACE(DIGEST_TEXT,'\\n',' '),'\\t',' '),500) FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME IS NOT NULL AND SCHEMA_NAME NOT IN ('mysql','sys','performance_schema','information_schema') AND DIGEST_TEXT IS NOT NULL ORDER BY SUM_TIMER_WAIT DESC LIMIT 12" 2>/dev/null
echo "==QUSERS=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT USER, SUM(COUNT_STAR), ROUND(SUM(SUM_TIMER_WAIT)/1000000000000,1) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER IS NOT NULL GROUP BY USER ORDER BY 3 DESC LIMIT 10" 2>/dev/null
echo "==QDIGESTUSER=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT h.DIGEST, COALESCE(t.PROCESSLIST_USER,'?'), COUNT(*) FROM (SELECT DIGEST, THREAD_ID FROM performance_schema.events_statements_history WHERE DIGEST IS NOT NULL UNION ALL SELECT DIGEST, THREAD_ID FROM performance_schema.events_statements_history_long WHERE DIGEST IS NOT NULL) h JOIN performance_schema.threads t ON t.THREAD_ID=h.THREAD_ID GROUP BY 1,2 ORDER BY 3 DESC LIMIT 300" 2>/dev/null
SH;
    }

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
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT id,user,COALESCE(db,'-'),time,COALESCE(state,'-'),COALESCE(LEFT(info,80),'-') FROM information_schema.processlist WHERE command<>'Sleep' AND info IS NOT NULL AND info NOT LIKE '%information_schema.processlist%' ORDER BY time DESC LIMIT 15" 2>/dev/null
echo "==MYSQLDB=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT table_schema, ROUND(SUM(data_length+index_length)/1048576,1) FROM information_schema.tables GROUP BY table_schema ORDER BY 2 DESC LIMIT 12" 2>/dev/null
echo "==MYSQLTOP=="
[ -n "\$MYSQL" ] && \$MYSQL -N -B -e "SELECT COALESCE(SCHEMA_NAME,'-'), ROUND(SUM_TIMER_WAIT/1000000000000,1), COUNT_STAR, ROUND(SUM_TIMER_WAIT/COUNT_STAR/1000000000,1), DATE_FORMAT(LAST_SEEN,'%d/%m %H:%i'), LEFT(REPLACE(REPLACE(DIGEST_TEXT,'\\n',' '),'\\t',' '),90) FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME IS NOT NULL AND DIGEST_TEXT IS NOT NULL ORDER BY SUM_TIMER_WAIT DESC LIMIT 12" 2>/dev/null

# Tráfico de sitios que corren DENTRO de contenedores Docker. Va AL FINAL y
# con tope de tiempo: si hay muchos contenedores con logs enormes, se corta
# esto y no las secciones importantes de arriba.
echo "==DOCKERBW=="
if command -v docker >/dev/null 2>&1; then
  SINCE=\$(date '+%Y-%m-%dT00:00:00')
  DSTART=\$(date +%s)
  for c in \$(docker ps --format '{{.Names}}' 2>/dev/null | head -12); do
    [ \$(( \$(date +%s) - DSTART )) -ge 10 ] && break
    timeout 3 docker logs --since "\$SINCE" --tail 40000 "\$c" 2>&1 \
      | awk -v name="\$c" '\$1 ~ /^([0-9]{1,3}\.){3}[0-9]{1,3}\$/ && \$6 ~ /^"(GET|POST|PUT|DELETE|HEAD|OPTIONS|PATCH)/ {req++; ip[\$1]=1; if(\$10 ~ /^[0-9]+\$/) b+=\$10} END{if(req>0){n=0; for(k in ip)n++; print name"\t"req"\t"b+0"\t"n}}'
  done
fi
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
# Usuarios web activos: 3 fuentes — ss (host) + conntrack (NAT) + nsenter en
# cada contenedor (ve clientes DENTRO de Docker aunque no haya conntrack).
# Excluye IPs privadas/internas y las propias del servidor.
LIPS=$(hostname -I 2>/dev/null)
webflt(){ awk -v L="$LIPS" 'BEGIN{n=split(L,A," ");for(i=1;i<=n;i++)loc[A[i]]=1}{ip=$1; if(ip=="")next; if(ip in loc)next; if(ip ~ /^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/)next; if(ip ~ /^(::1|::ffff:127|fe80|fd)/)next; print ip}'; }
WU=$({
ss -tan state established state time-wait 2>/dev/null | awk 'NR>1{loc=$(NF-1); if(loc ~ /:(80|443)$/){ip=$NF; sub(/:[0-9]+$/,"",ip); gsub(/[][]/,"",ip); print ip}}'
(cat /proc/net/nf_conntrack 2>/dev/null || cat /proc/net/ip_conntrack 2>/dev/null || conntrack -L -p tcp 2>/dev/null) | awk '/ESTABLISHED|TIME_WAIT/{o="";d="";for(i=1;i<=NF;i++){if(o==""&&$i~/^src=/)o=substr($i,5);if(d==""&&$i~/^dport=/)d=substr($i,7)};if((d=="80"||d=="443")&&o!="")print o}'
if command -v docker >/dev/null 2>&1 && command -v nsenter >/dev/null 2>&1; then
  for pid in $(docker ps -q 2>/dev/null | xargs -r -I{} docker inspect -f '{{.State.Pid}}' {} 2>/dev/null); do
    [ "$pid" = "0" ] && continue
    nsenter -t "$pid" -n ss -H -tan state established state time-wait 2>/dev/null
  done | awk '{lp=$(NF-1); pr=$NF; nn=split(lp,LA,":"); lport=LA[nn]+0; mm=split(pr,PA,":"); pport=PA[mm]+0; peer=pr; sub(/:[0-9]+$/,"",peer); gsub(/[][]/,"",peer); if(peer!="" && lport>0 && pport>0 && lport<pport) print peer}'
fi
} | webflt)
echo "WEB_CONNS=$(printf '%s\n' "$WU" | grep -c .)"
echo "WEB_USERS=$(printf '%s\n' "$WU" | sort -u | grep -c .)"
SH;
    }

    private function projectsScript(): string
    {
        return <<<'SH'
# ── Paneles de control: cada cuenta es un proyecto (Winhosting/cPanel) ──
if [ -d /var/cpanel/users ]; then
  for f in /var/cpanel/users/*; do
    [ -f "$f" ] || continue
    u=$(basename "$f")
    dom=$(sed -n 's/^DNS=//p' "$f" | head -1)
    susp=$(sed -n 's/^SUSPENDED=//p' "$f" | head -1)
    st="cuenta cPanel: $u"; [ "$susp" = "1" ] && st="$st · SUSPENDIDA"
    printf 'panel\t%s\t%s\n' "${dom:-$u}" "$st"
  done
fi
if command -v plesk >/dev/null 2>&1; then
  plesk bin domain --list 2>/dev/null | while read -r d; do
    [ -n "$d" ] && printf 'panel\t%s\tcuenta Plesk\n' "$d"
  done
fi
# ── Proyectos Docker Compose (agrupa contenedores por proyecto = el "título") ──
if command -v docker >/dev/null 2>&1; then
  docker ps --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null | grep -v '^$' | sort | uniq -c | while read -r cnt proj; do
    printf 'proyecto\t%s\t%s contenedor(es) en ejecución\n' "$proj" "$cnt"
  done
  # Contenedores sueltos (sin proyecto compose)
  docker ps --format '{{.Label "com.docker.compose.project"}}|{{.Names}}|{{.Image}}' 2>/dev/null | while IFS='|' read -r proj n img; do
    [ -z "$proj" ] && [ -n "$n" ] && printf 'docker\t%s\t%s\n' "$n" "$img"
  done
fi
# ── PM2 ──
if command -v pm2 >/dev/null 2>&1; then
  pm2 jlist 2>/dev/null | grep -o '"name":"[^"]*"' | sed 's/"name":"//;s/"//' | sort -u | while read -r n; do
    [ -n "$n" ] && printf 'pm2\t%s\tproceso node\n' "$n"
  done
fi
# ── Servicios systemd relevantes ──
systemctl list-units --type=service --state=running --no-legend --no-pager 2>/dev/null \
  | awk '{print $1}' | grep -Ei 'nginx|apache|mysql|mariadb|postgres|php|node|redis|mongo|docker' | sort -u | while read -r s; do
    printf 'servicio\t%s\tactivo\n' "$s"
  done
# ── Carpetas de proyectos web (omite /home si hay cPanel, para no duplicar paneles) ──
BASES="/var/www /opt /srv/www"
[ -d /var/cpanel/users ] || BASES="$BASES /home"
for base in $BASES; do
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
        // Emite líneas "origen<TAB>proyecto<TAB>dominio". Solo fuentes que
        // realmente indican un sitio alojado aquí: server_name de nginx,
        // vhosts de apache, certificados Let's Encrypt y —en Docker— las
        // reglas de Traefik y las variables que denotan el dominio propio
        // (VIRTUAL_HOST, LETSENCRYPT_HOST, APP_URL…). NADA de raspar cualquier
        // texto de las variables de entorno (eso metía github.com, tar.xz…).
        return <<<'SH'
echo "==MAP=="
# ── nginx: server_name de la configuración efectiva y de los archivos ──
{ nginx -T 2>/dev/null; cat /etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf 2>/dev/null; } \
  | grep -hoE 'server_name[[:space:]]+[^;]+;' | sed 's/server_name//;s/;//' | tr ' ' '\n' \
  | while read -r d; do [ -n "$d" ] && printf 'nginx\t-\t%s\n' "$d"; done
# ── apache: vhosts efectivos y directivas ServerName/ServerAlias ──
{ apache2ctl -S 2>/dev/null || apachectl -S 2>/dev/null || httpd -S 2>/dev/null; } \
  | grep -oE 'namevhost [^ ]+' | awk '{print $2}' \
  | while read -r d; do [ -n "$d" ] && printf 'apache\t-\t%s\n' "$d"; done
grep -rhoE '(ServerName|ServerAlias)[[:space:]]+[^ ]+' /etc/apache2 /etc/httpd 2>/dev/null | awk '{print $2}' \
  | while read -r d; do [ -n "$d" ] && printf 'apache\t-\t%s\n' "$d"; done
# ── certificados Let's Encrypt (fuente muy confiable: hay HTTPS emitido) ──
ls /etc/letsencrypt/live 2>/dev/null | grep -v README \
  | while read -r d; do [ -n "$d" ] && printf 'cert\t-\t%s\n' "$d"; done
# ── Docker: solo reglas de enrutado reales, agrupadas por proyecto compose ──
if command -v docker >/dev/null 2>&1; then
  for c in $(docker ps -q 2>/dev/null); do
    proj=$(docker inspect "$c" --format '{{index .Config.Labels "com.docker.compose.project"}}' 2>/dev/null)
    [ -z "$proj" ] && proj=$(docker inspect "$c" --format '{{.Name}}' 2>/dev/null | sed 's#^/##')
    # Traefik: Host(`dominio`)
    docker inspect "$c" --format '{{range $k,$v := .Config.Labels}}{{println $v}}{{end}}' 2>/dev/null \
      | grep -oE 'Host\(`[^`]+`\)' | grep -oE '`[^`]+`' | tr -d '`' \
      | while read -r d; do [ -n "$d" ] && printf 'docker\t%s\t%s\n' "$proj" "$d"; done
    # nginx-proxy / apps: variables que SON el dominio propio del sitio
    docker inspect "$c" --format '{{range .Config.Env}}{{println .}}{{end}}' 2>/dev/null \
      | grep -E '^(VIRTUAL_HOST|LETSENCRYPT_HOST|APP_URL|APP_DOMAIN|SITE_URL|SESSION_DOMAIN|SANCTUM_STATEFUL_DOMAINS)=' \
      | sed 's/^[^=]*=//' | tr ', ' '\n\n' \
      | while read -r d; do [ -n "$d" ] && printf 'docker\t%s\t%s\n' "$proj" "$d"; done
  done
fi
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

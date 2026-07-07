<?php

namespace App\Services;

use App\Models\Server;

/**
 * Prueba de carga (estrés) controlada contra los propios sitios de un
 * servidor, para descubrir errores y límites antes de que ocurran en
 * producción. Genera carga real: usar con criterio y en horas de bajo tráfico.
 *
 * SEGURIDAD: el objetivo SIEMPRE debe ser un dominio/host del propio servidor.
 * Nunca permite apuntar a terceros (evita convertirlo en herramienta de ataque).
 */
class StressTester
{
    public function __construct(
        private SshClient $ssh,
        private ServerMonitor $monitor,
    ) {}

    /** Límites de seguridad. */
    public const MAX_SECONDS = 30;
    public const MAX_CONCURRENCY = 50;

    /**
     * Objetivos permitidos: dominios detectados + el host del servidor.
     *
     * @return array<int, string>
     */
    public function allowedTargets(Server $server): array
    {
        $targets = [$server->host];
        try {
            foreach ($this->monitor->domains($server) as $d) {
                $targets[] = $d;
            }
        } catch (\Throwable) {
            // sin conexión: al menos el host
        }

        return array_values(array_unique($targets));
    }

    /** ¿La URL apunta a un objetivo permitido de este servidor? */
    public function isAllowed(Server $server, string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }
        $host = strtolower($host);

        foreach ($this->allowedTargets($server) as $t) {
            $t = strtolower($t);
            if ($host === $t || $host === 'www.'.$t || str_ends_with($host, '.'.$t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ejecuta la prueba de carga (con curl concurrente, sin instalar nada).
     *
     * @return array<string, mixed>
     */
    public function run(Server $server, string $url, int $seconds, int $concurrency): array
    {
        $seconds     = max(3, min(self::MAX_SECONDS, $seconds));
        $concurrency = max(1, min(self::MAX_CONCURRENCY, $concurrency));

        $parts  = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $port   = $parts['port'] ?? ($scheme === 'http' ? 80 : 443);

        $u = escapeshellarg($url);
        $h = escapeshellarg($host);
        $p = (int) $port;

        // Probamos contra localhost forzando la resolución del dominio
        // (--resolve): evita el NAT del propio servidor y el firewall externo,
        // y prueba directamente la app/servidor con el vhost correcto.
        $script = <<<SH
URL={$u}; HOST={$h}; PORT={$p}; SECS={$seconds}; CONC={$concurrency}
CLIENT=""
command -v curl >/dev/null 2>&1 && CLIENT="curl"
[ -z "\$CLIENT" ] && command -v wget >/dev/null 2>&1 && CLIENT="wget"
echo "CLIENT=\$CLIENT"
if [ -z "\$CLIENT" ]; then echo "==STATS=="; exit 0; fi

TMP=\$(mktemp -d)
read -r _ a b c d e f g _ < /proc/stat 2>/dev/null; t1=\$((a+b+c+d+e+f+g)); i1=\$((d+e))
END=\$(( \$(date +%s) + SECS ))

if [ "\$CLIENT" = "curl" ]; then
  worker(){ while [ \$(date +%s) -lt \$END ]; do
    curl -k -o /dev/null -s --resolve "\$HOST:\$PORT:127.0.0.1" -w '%{http_code} %{time_total}\n' --max-time 15 "\$URL" >> "\$TMP/out" 2>/dev/null
  done; }
else
  worker(){ while [ \$(date +%s) -lt \$END ]; do
    if wget -q -O /dev/null --no-check-certificate --timeout=15 "\$URL" 2>/dev/null; then echo "200 0" >> "\$TMP/out"; else echo "000 0" >> "\$TMP/out"; fi
  done; }
fi

i=0; while [ \$i -lt \$CONC ]; do worker & i=\$((i+1)); done
wait

read -r _ a b c d e f g _ < /proc/stat 2>/dev/null; t2=\$((a+b+c+d+e+f+g)); i2=\$((d+e))
dt=\$((t2-t1)); di=\$((i2-i1))
[ "\$dt" -gt 0 ] && echo "CPU=\$(( (100*(dt-di))/dt ))" || echo "CPU="
echo "==STATS=="
awk '{c[\$1]++; n++; s+=\$2; if(\$2>mx)mx=\$2} END{
  printf "total=%d\n", n;
  printf "avg_ms=%.0f\n", (n? s/n*1000 : 0);
  printf "max_ms=%.0f\n", mx*1000;
  for(k in c) printf "code=%s:%d\n", k, c[k];
}' "\$TMP/out" 2>/dev/null
rm -rf "\$TMP"
SH;

        $raw    = $this->ssh->run($server, $script, $seconds + 25);
        $result = $this->parse($raw, $seconds, $concurrency, $url);

        // Recoger evidencia del servidor tras la prueba y armar el plan
        try {
            $result['diagnostics'] = $this->gatherDiagnostics($server, $host);
        } catch (\Throwable) {
            $result['diagnostics'] = ['error_log' => [], 'app_log' => [], 'slow' => [], 'top' => []];
        }
        $result['plan'] = $this->buildActionPlan($server, $host, $result);

        return $result;
    }

    /**
     * Recoge evidencia del servidor justo después de la prueba: logs de error
     * del servidor web, logs de los contenedores del dominio, consultas lentas
     * y procesos que más consumen.
     *
     * @return array{error_log: array<int,string>, app_log: array<int,string>, slow: array<int,string>, top: array<int,string>}
     */
    private function gatherDiagnostics(Server $server, string $host): array
    {
        $h = escapeshellarg($host);
        $script = <<<SH
echo "==ERRLOG=="
tail -n 20 /var/log/nginx/error.log 2>/dev/null
for f in /var/log/nginx/*error*.log; do [ -f "\$f" ] && tail -n 8 "\$f"; done 2>/dev/null
tail -n 12 /var/log/apache2/error.log 2>/dev/null
echo "==APPLOG=="
if command -v docker >/dev/null 2>&1; then
  for c in \$(docker ps --format '{{.Names}}' 2>/dev/null); do
    if docker inspect "\$c" 2>/dev/null | grep -qi {$h}; then
      echo "--- contenedor \$c ---"
      docker logs --tail 25 "\$c" 2>&1 | grep -iE 'error|exception|fatal|warn|fail|timeout|denied|refused|memory|too many' | tail -20
    fi
  done
fi
echo "==SLOW=="
for f in /var/log/mysql/*slow*.log /var/lib/mysql/*-slow.log; do [ -f "\$f" ] && tail -n 25 "\$f"; done 2>/dev/null
echo "==TOP=="
ps aux --sort=-%cpu 2>/dev/null | head -8
SH;

        $sections = $this->splitDiag($this->ssh->run($server, $script));

        return [
            'error_log' => $this->clean($sections['ERRLOG'] ?? '', 25),
            'app_log'   => $this->clean($sections['APPLOG'] ?? '', 30),
            'slow'      => $this->clean($sections['SLOW'] ?? '', 25),
            'top'       => $this->clean($sections['TOP'] ?? '', 8),
        ];
    }

    /** @return array<string, string> */
    private function splitDiag(string $raw): array
    {
        $out = []; $cur = null;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if (preg_match('/^==([A-Z]+)==$/', trim($line), $m)) {
                $cur = $m[1]; $out[$cur] = '';
            } elseif ($cur !== null) {
                $out[$cur] .= $line."\n";
            }
        }
        return $out;
    }

    /** @return array<int, string> */
    private function clean(string $raw, int $limit): array
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', $raw), fn ($l) => trim($l) !== ''));

        return array_slice($lines, -$limit);
    }

    /**
     * Construye un "plan de solución" concreto y copiable para el programador.
     */
    private function buildActionPlan(Server $server, string $host, array $r): string
    {
        $v      = $r['verdict'];
        $codes  = collect($r['codes'])->map(fn ($c, $k) => "$k: $c")->implode(', ');
        $now    = now()->format('d/m/Y H:i');

        // Causas y pasos según el resultado
        $causes = [];
        $steps  = [];

        if (! ($r['client'] ?? null)) {
            $causes[] = 'El servidor no tiene curl/wget para generar carga; no se pudo medir.';
            $steps[]  = 'Instalar curl en el servidor: apt-get install -y curl, y repetir la prueba.';
        } elseif ($r['total'] === 0) {
            $causes[] = "El sitio no respondió en localhost:{$this->schemePort($r['url'])} — el servicio puede estar caído o escuchando en otro puerto.";
            $steps[]  = 'Verificar que el contenedor/servicio del sitio esté arriba (docker ps) y el puerto correcto.';
            $steps[]  = 'Revisar los logs de la aplicación (abajo) para el error de arranque.';
        } else {
            $has5xx = collect($r['codes'])->keys()->contains(fn ($c) => (int) $c >= 500);
            $hasTimeouts = ($r['codes']['000'] ?? 0) > 0;

            if ($r['error_pct'] >= 20 || $has5xx) {
                $causes[] = "El sitio devuelve errores del servidor (5xx) bajo carga ({$r['error_pct']}% de fallos). Suele ser saturación de la app o de la base de datos (conexiones agotadas, memoria, o consultas lentas).";
                $steps[]  = 'Revisar el log de la aplicación (abajo) buscando "too many connections", "out of memory", o excepciones repetidas.';
                $steps[]  = 'Si es MySQL: subir max_connections y/o el pool del framework; optimizar las consultas lentas listadas abajo (agregar índices, cachear resultados).';
                $steps[]  = 'Aumentar los workers del servidor de aplicación (php-fpm pm.max_children, o réplicas del contenedor).';
            }
            if ($hasTimeouts) {
                $causes[] = 'Hubo peticiones sin respuesta (timeouts): el servidor no alcanzó a atender toda la concurrencia.';
                $steps[]  = 'Aumentar workers/hilos o poner una cola/límite; considerar más CPU/RAM si el servidor quedó saturado.';
            }
            if ($r['avg_ms'] >= 1000) {
                $causes[] = "Respuestas lentas (promedio {$r['avg_ms']} ms). Indica cuellos de botella en código, consultas o falta de caché.";
                $steps[]  = 'Agregar caché (respuestas, consultas, opcache), optimizar las consultas más pesadas y revisar llamadas externas lentas.';
            }
            if (($r['cpu_during'] ?? 0) >= 85) {
                $causes[] = "El CPU llegó al {$r['cpu_during']}% durante la prueba: el sitio es intensivo en CPU bajo carga.";
                $steps[]  = 'Perfilar el código de la ruta probada; cachear lo que se recalcula; considerar escalar horizontalmente.';
            }
            if (empty($causes)) {
                $causes[] = 'El sitio respondió estable. No se detectaron errores relevantes en esta prueba.';
                $steps[]  = 'Repetir con más concurrencia/tiempo para encontrar el punto de quiebre real.';
            }
        }

        $lines = [];
        $lines[] = '📋 REPORTE DE PRUEBA DE ESTRÉS — '.$server->name;
        $lines[] = 'Fecha: '.$now;
        $lines[] = 'Servidor: '.$server->name.' ('.$server->host.')';
        $lines[] = 'Dominio/proyecto: '.$host;
        $lines[] = 'URL probada: '.$r['url'];
        $lines[] = 'Parámetros: '.$r['seconds'].'s con '.$r['concurrency'].' usuarios simultáneos';
        $lines[] = '';
        $lines[] = '── RESULTADO ──';
        $lines[] = 'Veredicto: '.strtoupper($v['level']).' — '.$v['text'];
        $lines[] = 'Peticiones: '.$r['total'].' ('.$r['rps'].'/seg)';
        $lines[] = 'Latencia: promedio '.$r['avg_ms'].' ms · máxima '.$r['max_ms'].' ms';
        $lines[] = 'Errores: '.$r['error_pct'].'%';
        $lines[] = 'Códigos: '.($codes ?: 'ninguno');
        $lines[] = 'CPU durante la prueba: '.($r['cpu_during'] !== null ? $r['cpu_during'].'%' : 'n/d');
        $lines[] = '';
        $lines[] = '── CAUSA PROBABLE ──';
        foreach ($causes as $i => $c) {
            $lines[] = ($i + 1).'. '.$c;
        }
        $lines[] = '';
        $lines[] = '── PLAN DE ACCIÓN SUGERIDO ──';
        foreach ($steps as $i => $s) {
            $lines[] = ($i + 1).'. '.$s;
        }

        $d = $r['diagnostics'] ?? [];
        if (! empty($d['app_log'])) {
            $lines[] = '';
            $lines[] = '── LOG DE LA APLICACIÓN (contenedores del dominio) ──';
            $lines = array_merge($lines, array_slice($d['app_log'], -20));
        }
        if (! empty($d['error_log'])) {
            $lines[] = '';
            $lines[] = '── ERRORES DEL SERVIDOR WEB ──';
            $lines = array_merge($lines, array_slice($d['error_log'], -15));
        }
        if (! empty($d['slow'])) {
            $lines[] = '';
            $lines[] = '── CONSULTAS SQL LENTAS ──';
            $lines = array_merge($lines, array_slice($d['slow'], -20));
        }
        if (! empty($d['top'])) {
            $lines[] = '';
            $lines[] = '── PROCESOS QUE MÁS CONSUMÍAN ──';
            $lines = array_merge($lines, $d['top']);
        }

        return implode("\n", $lines);
    }

    private function schemePort(string $url): int
    {
        $parts = parse_url($url);

        return $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);
    }

    /** @return array<string, mixed> */
    private function parse(string $raw, int $seconds, int $concurrency, string $url): array
    {
        $lines  = preg_split('/\r?\n/', trim($raw));
        $cpu    = null;
        $total  = 0;
        $avg    = 0;
        $max    = 0;
        $codes  = [];
        $client = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'CLIENT=')) {
                $client = substr($line, 7) ?: null;
            } elseif (str_starts_with($line, 'CPU=')) {
                $v = substr($line, 4);
                $cpu = $v === '' ? null : (int) $v;
            } elseif (str_starts_with($line, 'total=')) {
                $total = (int) substr($line, 6);
            } elseif (str_starts_with($line, 'avg_ms=')) {
                $avg = (int) substr($line, 7);
            } elseif (str_starts_with($line, 'max_ms=')) {
                $max = (int) substr($line, 7);
            } elseif (str_starts_with($line, 'code=')) {
                [$code, $count] = explode(':', substr($line, 5), 2);
                $codes[$code] = (int) $count;
            }
        }

        arsort($codes);

        $ok    = 0;
        $errs  = 0;
        foreach ($codes as $code => $count) {
            if ($code === '000') {
                $errs += $count; // conexión fallida / timeout
            } elseif ((int) $code >= 500) {
                $errs += $count;
            } elseif ((int) $code >= 200 && (int) $code < 400) {
                $ok += $count;
            }
        }
        $errorPct = $total > 0 ? round(($total - $ok) / $total * 100, 1) : 0;

        return [
            'url'          => $url,
            'seconds'      => $seconds,
            'concurrency'  => $concurrency,
            'total'        => $total,
            'rps'          => $seconds > 0 ? (int) round($total / $seconds) : 0,
            'avg_ms'       => $avg,
            'max_ms'       => $max,
            'cpu_during'   => $cpu,
            'codes'        => $codes,
            'ok'           => $ok,
            'errors'       => $errs,
            'error_pct'    => $errorPct,
            'client'       => $client,
            'verdict'      => $this->verdict($total, $errorPct, $avg, $client),
        ];
    }

    /** Diagnóstico legible del resultado. */
    private function verdict(int $total, float $errorPct, int $avgMs, ?string $client): array
    {
        if (! $client) {
            return ['level' => 'bad', 'text' => 'El servidor no tiene curl ni wget instalados para generar la carga. Instala uno con: apt-get install -y curl'];
        }
        if ($total === 0) {
            return ['level' => 'bad', 'text' => 'No se completó ninguna petición. El sitio no respondió en el propio servidor (¿el servicio está caído o escucha en otro puerto?).'];
        }
        if ($errorPct >= 20) {
            return ['level' => 'bad', 'text' => 'Muchos errores bajo carga ('.$errorPct.'%). El sitio se degrada o cae al recibir tráfico — hay que revisarlo.'];
        }
        if ($errorPct >= 3 || $avgMs >= 2000) {
            return ['level' => 'warn', 'text' => 'Aguanta, pero con señales de estrés (errores o respuestas lentas). Conviene optimizar antes de que crezca el tráfico.'];
        }

        return ['level' => 'ok', 'text' => 'El sitio respondió estable bajo carga. 💪'];
    }
}

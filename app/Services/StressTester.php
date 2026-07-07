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

        $raw = $this->ssh->run($server, $script, $seconds + 25);

        return $this->parse($raw, $seconds, $concurrency, $url);
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

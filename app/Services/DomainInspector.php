<?php

namespace App\Services;

/**
 * Utilidades de dominios: extraer el dominio raíz de un hostname y
 * consultar la fecha de vencimiento vía whois (desde el servidor del panel).
 */
class DomainInspector
{
    /** TLDs de dos niveles frecuentes (Colombia, LatAm y genéricos). */
    private const MULTI_TLDS = [
        'com.co', 'net.co', 'org.co', 'edu.co', 'gov.co', 'mil.co', 'nom.co',
        'com.mx', 'com.ar', 'com.br', 'com.pe', 'com.ec', 'com.ve',
        'co.uk', 'org.uk', 'com.es',
    ];

    /**
     * Dominio raíz de un hostname: app.gestoru.com → gestoru.com,
     * tienda.miempresa.com.co → miempresa.com.co
     */
    public function apexFor(string $hostname): ?string
    {
        $hostname = strtolower(trim($hostname, " \t.\r\n"));
        if ($hostname === '' || filter_var($hostname, FILTER_VALIDATE_IP)) {
            return null;
        }

        $labels = explode('.', $hostname);
        $n      = count($labels);
        if ($n < 2) {
            return null;
        }

        foreach (self::MULTI_TLDS as $tld) {
            if (str_ends_with($hostname, '.'.$tld)) {
                $keep = substr_count($tld, '.') + 2;

                return $n >= $keep ? implode('.', array_slice($labels, -$keep)) : null;
            }
        }

        return implode('.', array_slice($labels, -2));
    }

    /**
     * Consulta whois y devuelve la fecha de vencimiento.
     *
     * @return array{ok: bool, expires_at: ?string, error: ?string}
     */
    public function whoisExpiry(string $domain): array
    {
        if (! preg_match('/^[a-z0-9.-]{4,253}$/i', $domain)) {
            return ['ok' => false, 'expires_at' => null, 'error' => 'Dominio inválido.'];
        }

        $bin = trim((string) shell_exec('command -v whois 2>/dev/null'));
        if ($bin === '') {
            return [
                'ok'         => false,
                'expires_at' => null,
                'error'      => 'El comando whois no está instalado en el servidor del panel. Vuelve a ejecutar el instalador para agregarlo.',
            ];
        }

        $out = (string) shell_exec('timeout 20 whois '.escapeshellarg($domain).' 2>/dev/null');
        if (trim($out) === '') {
            return ['ok' => false, 'expires_at' => null, 'error' => 'whois no devolvió información (posible límite de consultas — intenta en unos minutos).'];
        }

        $patterns = [
            '/Registry Expiry Date:\s*([^\s]+)/i',
            '/Registrar Registration Expiration Date:\s*([^\s]+)/i',
            '/Expiration Date:\s*([^\s]+)/i',
            '/Expiry Date:\s*([^\s]+)/i',
            '/paid-till:\s*([^\s]+)/i',
            '/expires?:\s*([0-9]{4}-[0-9]{2}-[0-9]{2})/i',
        ];

        foreach ($patterns as $p) {
            if (preg_match($p, $out, $m)) {
                $ts = strtotime($m[1]);
                if ($ts !== false) {
                    return ['ok' => true, 'expires_at' => date('Y-m-d', $ts), 'error' => null];
                }
            }
        }

        return ['ok' => false, 'expires_at' => null, 'error' => 'whois respondió pero no encontré la fecha de vencimiento en la respuesta.'];
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Geolocaliza direcciones IP (país, ciudad, ISP, coordenadas) usando el
 * servicio gratuito ip-api.com en lotes. Cada IP se cachea 24 h para no
 * repetir consultas. Si el servicio falla, degrada sin romper: devuelve las
 * IPs sin datos de ubicación.
 */
class GeoLocator
{
    private const ENDPOINT = 'http://ip-api.com/batch?fields=status,country,countryCode,city,lat,lon,isp,query';

    /**
     * @param  string[]  $ips
     * @return array<string, array{country: ?string, countryCode: ?string, city: ?string, lat: ?float, lon: ?float, isp: ?string}>
     */
    public function locate(array $ips): array
    {
        $ips = array_values(array_unique(array_filter($ips, fn ($ip) => $this->isPublic($ip))));

        $result  = [];
        $missing = [];
        foreach ($ips as $ip) {
            $cached = Cache::get('geo:'.$ip);
            if ($cached !== null) {
                $result[$ip] = $cached;
            } else {
                $missing[] = $ip;
            }
        }

        foreach (array_chunk($missing, 100) as $chunk) {
            try {
                $resp = Http::timeout(6)->acceptJson()->post(
                    self::ENDPOINT,
                    array_map(fn ($ip) => ['query' => $ip], $chunk)
                );
                foreach ($resp->json() ?? [] as $row) {
                    $ip = $row['query'] ?? null;
                    if (! $ip) {
                        continue;
                    }
                    $geo = [
                        'country'     => $row['country'] ?? null,
                        'countryCode' => $row['countryCode'] ?? null,
                        'city'        => $row['city'] ?? null,
                        'lat'         => $row['lat'] ?? null,
                        'lon'         => $row['lon'] ?? null,
                        'isp'         => $row['isp'] ?? null,
                    ];
                    Cache::put('geo:'.$ip, $geo, now()->addDay());
                    $result[$ip] = $geo;
                }
            } catch (\Throwable $e) {
                // Sin geolocalización para este lote; seguimos sin romper.
            }
        }

        return $result;
    }

    /** Solo IPs públicas se geolocalizan (descarta privadas/reservadas). */
    public function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** Emoji de bandera a partir del código de país (ISO-2). */
    public function flag(?string $cc): string
    {
        if (! $cc || strlen($cc) !== 2) {
            return '🌐';
        }
        $cc = strtoupper($cc);

        return mb_chr(0x1F1E6 + ord($cc[0]) - 65).mb_chr(0x1F1E6 + ord($cc[1]) - 65);
    }
}

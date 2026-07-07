<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricSample extends Model
{
    protected $fillable = [
        'server_id', 'sampled_at', 'cpu_pct', 'mem_used', 'mem_total',
        'disk_used', 'disk_total', 'load1',
        'top_cpu_cmd', 'top_cpu_pct', 'top_mem_cmd', 'top_mem_pct',
        'mysql_conns', 'mysql_running',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'cpu_pct'    => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Crea una muestra a partir de la salida de ServerMonitor (métricas +
     * procesos + MySQL). La usan el muestreo de cada 5 min y la captura
     * inmediata de picos críticos.
     */
    public static function fromMetrics(Server $server, array $m, array $top = ['cpu' => [], 'mem' => []], array $sql = ['connections' => null, 'running' => null]): self
    {
        return self::create([
            'server_id'   => $server->id,
            'sampled_at'  => now(),
            'cpu_pct'     => $m['cpu_pct'],
            'mem_used'    => $m['mem']['used_bytes'] ?: null,
            'mem_total'   => $m['mem']['total_bytes'] ?: null,
            'disk_used'   => $m['disk']['used_bytes'] ?: null,
            'disk_total'  => $m['disk']['total_bytes'] ?: null,
            'load1'       => is_numeric(strtok((string) $m['load'], ' ')) ? (float) strtok((string) $m['load'], ' ') : null,
            'top_cpu_cmd' => $top['cpu'][0]['command'] ?? null,
            'top_cpu_pct' => isset($top['cpu'][0]) ? (float) $top['cpu'][0]['cpu'] : null,
            'top_mem_cmd' => $top['mem'][0]['command'] ?? null,
            'top_mem_pct' => isset($top['mem'][0]) ? (float) $top['mem'][0]['mem'] : null,
            'mysql_conns'   => $sql['connections'] ?? null,
            'mysql_running' => $sql['running'] ?? null,
        ]);
    }

    public function memPct(): ?int
    {
        return $this->mem_total ? (int) round($this->mem_used / $this->mem_total * 100) : null;
    }

    public function diskPct(): ?int
    {
        return $this->disk_total ? (int) round($this->disk_used / $this->disk_total * 100) : null;
    }
}

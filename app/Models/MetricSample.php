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

    public function memPct(): ?int
    {
        return $this->mem_total ? (int) round($this->mem_used / $this->mem_total * 100) : null;
    }

    public function diskPct(): ?int
    {
        return $this->disk_total ? (int) round($this->disk_used / $this->disk_total * 100) : null;
    }
}

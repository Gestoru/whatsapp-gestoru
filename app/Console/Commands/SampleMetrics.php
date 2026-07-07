<?php

namespace App\Console\Commands;

use App\Models\MetricSample;
use App\Models\Server;
use App\Services\ServerMonitor;
use Illuminate\Console\Command;

class SampleMetrics extends Command
{
    protected $signature = 'metrics:sample {--prune-days=30 : Días de histórico a conservar}';

    protected $description = 'Toma una muestra de métricas de cada servidor activo y la guarda para el histórico.';

    public function handle(ServerMonitor $monitor): int
    {
        $servers = Server::where('is_active', true)->get()
            ->filter(fn (Server $s) => $s->hasCredentials());

        foreach ($servers as $server) {
            try {
                $m   = $monitor->metrics($server);
                $top = $monitor->topProcesses($server);

                $sql = ['connections' => null, 'running' => null];
                try {
                    $sql = $monitor->mysqlStatus($server);
                } catch (\Throwable) {
                    // MySQL opcional: si falla, se guarda la muestra sin BD
                }

                MetricSample::create([
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

                $this->info("✔ {$server->name}: CPU {$m['cpu_pct']}% · RAM {$m['mem']['pct']}%");
            } catch (\Throwable $e) {
                $this->warn("✗ {$server->name}: {$e->getMessage()}");
            }
        }

        // Limpiar histórico viejo
        $days = (int) $this->option('prune-days');
        if ($days > 0) {
            MetricSample::where('sampled_at', '<', now()->subDays($days))->delete();
        }

        return self::SUCCESS;
    }
}

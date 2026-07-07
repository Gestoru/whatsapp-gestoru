<?php

namespace App\Console\Commands;

use App\Models\MetricSample;
use App\Models\Server;
use App\Services\AlertService;
use App\Services\ServerMonitor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Support\Facades\Log;

class SampleMetrics extends Command implements Isolatable
{
    protected $signature = 'metrics:sample {--prune-days=30 : Días de histórico a conservar}';

    protected $description = 'Toma una muestra de métricas de cada servidor activo y la guarda para el histórico.';

    public function handle(ServerMonitor $monitor, AlertService $alerts): int
    {
        $servers = Server::where('is_active', true)->get()
            ->filter(fn (Server $s) => $s->hasCredentials());

        foreach ($servers as $server) {
            try {
                $m = $monitor->metrics($server);

                // Procesos, contenedores y MySQL son opcionales: si fallan,
                // la muestra se guarda igual.
                $top = ['cpu' => [], 'mem' => []];
                $containers = [];
                try {
                    $snap = $monitor->peakSnapshot($server);
                    $top = $snap['top'];
                    $containers = $snap['containers'];
                } catch (\Throwable) {
                }

                $sql = ['connections' => null, 'running' => null];
                try {
                    $sql = $monitor->mysqlStatus($server);
                } catch (\Throwable) {
                }

                $sample = MetricSample::fromMetrics($server, $m, $top, $sql, $containers);

                $alerts->checkSample($server, $sample);

                $this->info("✔ {$server->name}: CPU {$m['cpu_pct']}% · RAM {$m['mem']['pct']}%");
            } catch (\Throwable $e) {
                Log::warning('metrics:sample sin muestra', ['server' => $server->name, 'error' => $e->getMessage()]);
                $alerts->offline($server, $e->getMessage());
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

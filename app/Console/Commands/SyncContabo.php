<?php

namespace App\Console\Commands;

use App\Services\ContaboService;
use Illuminate\Console\Command;

class SyncContabo extends Command
{
    protected $signature = 'servers:sync-contabo';

    protected $description = 'Sincroniza estado, plan y renovación de los VPS de Contabo con el dashboard.';

    public function handle(ContaboService $contabo): int
    {
        if (! $contabo->configured()) {
            $this->warn('Contabo no está configurado (sin credenciales de API). Se omite.');

            return self::SUCCESS;
        }

        $r = $contabo->syncToServers();

        if ($r['ok']) {
            $this->info("✔ Contabo: {$r['total']} VPS ({$r['created']} nuevos, {$r['matched']} actualizados).");

            return self::SUCCESS;
        }

        $this->error('✗ '.$r['error']);

        return self::FAILURE;
    }
}

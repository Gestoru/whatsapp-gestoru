<?php

namespace App\Console\Commands;

use App\Services\GoDaddyService;
use Illuminate\Console\Command;

class SyncGoDaddy extends Command
{
    protected $signature = 'domains:sync-godaddy';

    protected $description = 'Sincroniza el estado de los dominios de GoDaddy con el dashboard.';

    public function handle(GoDaddyService $godaddy): int
    {
        if (! $godaddy->configured()) {
            $this->warn('GoDaddy no está configurado (sin API key/secret). Se omite.');

            return self::SUCCESS;
        }

        $r = $godaddy->syncToDatabase();

        if ($r['ok']) {
            $this->info("✔ GoDaddy: {$r['total']} dominios ({$r['created']} nuevos, {$r['updated']} actualizados).");

            return self::SUCCESS;
        }

        $this->error('✗ '.$r['error']);

        return self::FAILURE;
    }
}

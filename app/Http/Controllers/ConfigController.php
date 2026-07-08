<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AlertService;
use App\Services\ContaboService;
use App\Services\GoDaddyService;

class ConfigController extends Controller
{
    /**
     * Centro de configuración: reúne en un solo lugar todos los módulos de
     * ajustes (alertas por WhatsApp, integración GoDaddy, integración Contabo
     * y preferencias generales del sistema).
     */
    public function index(AlertService $alerts, GoDaddyService $godaddy, ContaboService $contabo, \App\Services\GitHubService $github)
    {
        // Tolerante a que aún falte la migración de repositorios en el servidor.
        $githubRepoCount = 0;
        $githubLastSync  = null;
        try {
            $githubRepoCount = \App\Models\Repository::count();
            $githubLastSync  = \App\Models\Repository::max('synced_at');
        } catch (\Throwable $e) {
            // tabla aún no creada; se mostrará 0
        }

        return view('dashboard.config', [
            // GitHub
            'githubConfigured' => $github->configured(),
            'githubLastSync'   => $githubLastSync,
            'githubRepoCount'  => $githubRepoCount,
            // Alertas por WhatsApp
            'enabled'      => Setting::boolean('alerts_enabled'),
            'phone'        => Setting::get('alerts_phone'),
            'cpu'          => Setting::get('alert_cpu', 85),
            'disk'         => Setting::get('alert_disk', 85),
            'mem'          => Setting::get('alert_mem', 90),
            'cooldown'     => Setting::get('alert_cooldown', 30),
            'waConfigured' => $alerts->whatsappConfigured(),
            'waApiUrl'     => \App\Services\VpsWhatsAppService::apiUrl(),

            // GoDaddy
            'godaddyConfigured' => $godaddy->configured(),
            'godaddyKey'        => Setting::get('godaddy_api_key'),
            'godaddyLastSync'   => \App\Models\Domain::where('registrar', 'godaddy')->max('synced_at'),

            // Contabo
            'contaboConfigured' => $contabo->configured(),
            'contaboClientId'   => Setting::get('contabo_client_id'),
            'contaboApiUser'    => Setting::get('contabo_api_user'),

            // Preferencias generales (solo lectura, vienen del .env)
            'general' => [
                'brand'      => config('dashboard.brand'),
                'title'      => config('dashboard.title'),
                'timezone'   => config('app.timezone'),
                'cpuPeak'    => config('dashboard.cpu_peak_threshold'),
                'hasPassword'=> (bool) config('dashboard.password'),
            ],
        ]);
    }
}

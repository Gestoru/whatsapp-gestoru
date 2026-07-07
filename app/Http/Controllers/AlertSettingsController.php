<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AlertService;
use Illuminate\Http\Request;

class AlertSettingsController extends Controller
{
    public function __construct(private AlertService $alerts) {}

    public function edit()
    {
        return view('dashboard.alerts', [
            'enabled'       => Setting::boolean('alerts_enabled'),
            'phone'         => Setting::get('alerts_phone'),
            'cpu'           => Setting::get('alert_cpu', 85),
            'disk'          => Setting::get('alert_disk', 85),
            'mem'           => Setting::get('alert_mem', 90),
            'cooldown'      => Setting::get('alert_cooldown', 30),
            'waConfigured'  => $this->alerts->whatsappConfigured(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'alerts_enabled' => 'nullable|boolean',
            'alerts_phone'   => 'nullable|string|max:30',
            'alert_cpu'      => 'required|integer|min:1|max:100',
            'alert_disk'     => 'required|integer|min:1|max:100',
            'alert_mem'      => 'required|integer|min:1|max:100',
            'alert_cooldown' => 'required|integer|min:5|max:1440',
        ]);

        Setting::put('alerts_enabled', $request->boolean('alerts_enabled') ? '1' : '0');
        Setting::put('alerts_phone', preg_replace('/\D/', '', (string) ($data['alerts_phone'] ?? '')));
        Setting::put('alert_cpu', $data['alert_cpu']);
        Setting::put('alert_disk', $data['alert_disk']);
        Setting::put('alert_mem', $data['alert_mem']);
        Setting::put('alert_cooldown', $data['alert_cooldown']);

        return redirect()->to(route('dashboard.config').'#alertas')->with('status', 'Configuración de alertas guardada.');
    }

    public function test()
    {
        $r = $this->alerts->sendTest();

        return redirect()->to(route('dashboard.config').'#alertas')
            ->with($r['ok'] ? 'status' : 'error', $r['message']);
    }
}

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

    // ── Conexión del servidor de WhatsApp ──────────────────────────────────

    /** Guarda la URL y clave del servidor de WhatsApp (desde el panel). */
    public function saveWhatsApp(Request $request)
    {
        $data = $request->validate([
            'wa_api_url' => 'nullable|url|max:255',
            'wa_api_key' => 'nullable|string|max:255',
        ]);

        \App\Services\VpsWhatsAppService::saveConnection($data['wa_api_url'] ?? null, $data['wa_api_key'] ?? null);

        return redirect()->to(route('dashboard.config').'#alertas')->with('status', 'Servidor de WhatsApp guardado.');
    }

    /** Estado en vivo (JSON) para el panel: conectado / esperando QR. */
    public function waStatus()
    {
        $configured = (bool) \App\Services\VpsWhatsAppService::apiUrl();
        $out = ['configured' => $configured, 'connected' => false, 'session' => null, 'qr' => null, 'error' => null];

        if ($configured) {
            $s = app(\App\Services\VpsWhatsAppService::class)->status();
            $out['connected'] = (bool) ($s['connected'] ?? false);
            $out['session']   = $s['session'] ?? \Illuminate\Support\Facades\Cache::get('wa_session');
            $out['error']     = $s['error'] ?? null;
            if ($out['connected']) {
                \Illuminate\Support\Facades\Cache::forget('wa_qr');
            } else {
                $out['qr'] = \Illuminate\Support\Facades\Cache::get('wa_qr');
            }
        }

        return response()->json($out);
    }

    /** Pide al servidor que genere un QR nuevo para vincular WhatsApp. */
    public function waRequestQr()
    {
        try {
            app(\App\Services\VpsWhatsAppService::class)->requestQr();

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'No se pudo contactar el servidor de WhatsApp: '.$e->getMessage()]);
        }
    }

    /** Desconecta la sesión de WhatsApp. */
    public function waDisconnect()
    {
        try {
            app(\App\Services\VpsWhatsAppService::class)->disconnect();
            \Illuminate\Support\Facades\Cache::forget('wa_qr');
            \Illuminate\Support\Facades\Cache::forget('wa_session');

            return redirect()->to(route('dashboard.config').'#alertas')->with('status', 'WhatsApp desconectado.');
        } catch (\Throwable $e) {
            return redirect()->to(route('dashboard.config').'#alertas')->with('error', 'No se pudo desconectar: '.$e->getMessage());
        }
    }
}

<?php

namespace App\Services;

use App\Models\MetricSample;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Evalúa condiciones de alerta y envía avisos por WhatsApp, con control
 * anti-spam (cooldown por servidor y tipo de alerta).
 */
class AlertService
{
    public function __construct(private VpsWhatsAppService $wa) {}

    public function enabled(): bool
    {
        return Setting::boolean('alerts_enabled');
    }

    public function phone(): ?string
    {
        $p = Setting::get('alerts_phone');

        return $p ? preg_replace('/\D/', '', (string) $p) : null;
    }

    public function whatsappConfigured(): bool
    {
        return (bool) config('services.vps.api_url');
    }

    /** Evalúa una muestra recién tomada y dispara alertas si aplica. */
    public function checkSample(Server $server, MetricSample $sample): void
    {
        if (! $this->enabled() || ! $this->phone()) {
            return;
        }

        $cpuTh  = (int) Setting::get('alert_cpu', 85);
        $diskTh = (int) Setting::get('alert_disk', 85);
        $memTh  = (int) Setting::get('alert_mem', 90);

        if ($sample->cpu_pct !== null && $sample->cpu_pct >= $cpuTh) {
            $proc = $sample->top_cpu_cmd ? " Proceso: {$sample->top_cpu_cmd}".($sample->top_cpu_pct ? " ({$sample->top_cpu_pct}%)" : '') : '';
            $this->fire($server, 'cpu', "🔴 *CPU alta* en {$server->name}\n{$sample->cpu_pct}% de CPU (carga {$sample->load1}).{$proc}");
        }

        if (($p = $sample->diskPct()) !== null && $p >= $diskTh) {
            $this->fire($server, 'disk', "💾 *Disco casi lleno* en {$server->name}\n{$p}% usado. Conviene liberar espacio pronto.");
        }

        if (($p = $sample->memPct()) !== null && $p >= $memTh) {
            $this->fire($server, 'mem', "🧠 *Memoria alta* en {$server->name}\n{$p}% de RAM en uso.");
        }
    }

    /** Alerta de servidor sin conexión. */
    public function offline(Server $server, string $error): void
    {
        if (! $this->enabled() || ! $this->phone()) {
            return;
        }

        $this->fire($server, 'offline', "⚠️ *Servidor sin conexión*\n{$server->name} ({$server->host}) no responde.\n{$error}");
    }

    /** Envía un mensaje de prueba. @return array{ok: bool, message: string} */
    public function sendTest(): array
    {
        if (! $this->phone()) {
            return ['ok' => false, 'message' => 'Configura primero el número de WhatsApp.'];
        }
        if (! $this->whatsappConfigured()) {
            return ['ok' => false, 'message' => 'Falta configurar VPS_API_URL (el servidor de WhatsApp). Sin eso no se puede enviar.'];
        }

        try {
            $this->wa->sendMessage($this->waAddress(), "✅ *Prueba de alertas* — Infraestructura Gestoru\nSi ves este mensaje, las alertas por WhatsApp están funcionando.");

            return ['ok' => true, 'message' => 'Mensaje de prueba enviado a '.$this->phone().'. Revisa tu WhatsApp.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo enviar: '.$e->getMessage().' (¿está conectado el WhatsApp en el VPS?)'];
        }
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    private function fire(Server $server, string $type, string $message): void
    {
        $cooldown = (int) Setting::get('alert_cooldown', 30);
        $key      = "alert:{$server->id}:{$type}";

        if (Cache::has($key)) {
            return; // dentro del período de silencio
        }
        Cache::put($key, true, now()->addMinutes(max(5, $cooldown)));

        if (! $this->whatsappConfigured()) {
            return;
        }

        try {
            $this->wa->sendMessage($this->waAddress(), $message);
        } catch (\Throwable $e) {
            Log::warning('Alerta WhatsApp no enviada', ['server' => $server->id, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** Número en formato whatsapp-web.js (con @c.us). */
    private function waAddress(): string
    {
        return $this->phone().'@c.us';
    }
}

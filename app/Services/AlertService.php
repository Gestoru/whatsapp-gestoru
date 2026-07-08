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
        return $this->phones()[0] ?? null;
    }

    /**
     * Lista de números destinatarios (a quiénes les llega la alerta).
     * Acepta varios separados por coma, salto de línea o punto y coma.
     *
     * @return array<int, string>
     */
    public function phones(): array
    {
        $raw = (string) (Setting::get('alerts_phones') ?: Setting::get('alerts_phone'));

        // Un número por línea/coma/punto y coma. Cada uno puede traer espacios
        // o guiones (57 310 987 6543) que se limpian a solo dígitos.
        return collect(preg_split('/[\n\r,;]+/', $raw))
            ->map(fn ($p) => preg_replace('/\D/', '', (string) $p))
            ->filter(fn ($p) => strlen($p) >= 8)   // número válido
            ->unique()
            ->values()
            ->all();
    }

    public function whatsappConfigured(): bool
    {
        return (bool) VpsWhatsAppService::apiUrl();
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
        $phones = $this->phones();
        if (empty($phones)) {
            return ['ok' => false, 'message' => 'Registra primero al menos un número de WhatsApp.'];
        }
        if (! $this->whatsappConfigured()) {
            return ['ok' => false, 'message' => 'Falta configurar el servidor de WhatsApp. Sin eso no se puede enviar.'];
        }

        $msg  = "✅ *Prueba de alertas* — Infraestructura Gestoru\nSi ves este mensaje, las alertas por WhatsApp están funcionando.";
        $sent = 0;
        $lastError = null;
        foreach ($phones as $p) {
            try {
                $this->wa->sendMessage($p.'@c.us', $msg);
                $sent++;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($sent > 0) {
            return ['ok' => true, 'message' => "Mensaje de prueba enviado a {$sent} número(s). Revisa tu WhatsApp."];
        }

        return ['ok' => false, 'message' => 'No se pudo enviar: '.$lastError.' (¿está conectado el WhatsApp en el servidor?)'];
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

        // Envía la alerta a TODOS los números registrados.
        foreach ($this->phones() as $p) {
            try {
                $this->wa->sendMessage($p.'@c.us', $message);
            } catch (\Throwable $e) {
                Log::warning('Alerta WhatsApp no enviada', ['server' => $server->id, 'type' => $type, 'to' => $p, 'error' => $e->getMessage()]);
            }
        }
    }
}

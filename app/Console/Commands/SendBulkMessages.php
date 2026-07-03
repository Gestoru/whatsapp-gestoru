<?php

namespace App\Console\Commands;

use App\Services\TwilioWhatsAppService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;

/**
 * Envío masivo de mensajes a una lista de clientes vía Twilio,
 * por SMS o por WhatsApp (se elige con --channel).
 *
 * Ejemplos:
 *   php artisan messages:blast clientes.csv --channel=sms --message="Hola {{nombre}}, tenemos una oferta."
 *   php artisan messages:blast clientes.csv --channel=whatsapp --message-file=mensaje.txt --dry-run
 *   php artisan messages:blast clientes.csv --message="..." --default-country=1 --results=salida.csv
 *
 * Formato del CSV (con o sin cabecera):
 *   phone,name
 *   +18095551234,Juan
 *   +18095559876,María
 *
 * Se admiten cabeceras en español (telefono/celular, nombre) o inglés (phone, name).
 * En el mensaje, {{nombre}} o {{name}} se reemplaza por el nombre del cliente.
 */
class SendBulkMessages extends Command
{
    protected $signature = 'messages:blast
        {recipients : Ruta al CSV con los clientes (columnas phone[,name])}
        {--channel=sms : Canal de envío: sms o whatsapp}
        {--message= : Texto del mensaje a enviar}
        {--message-file= : Ruta a un archivo de texto con el mensaje}
        {--from= : Remitente puntual; por defecto TWILIO_SMS_FROM (sms) o TWILIO_WHATSAPP_FROM (whatsapp)}
        {--default-country= : Código de país (p. ej. 1 para RD/EE.UU., 34 España) que se antepone a los números sin prefijo +}
        {--delay=0 : Segundos de espera entre cada envío}
        {--dry-run : Simula el envío sin enviar nada}
        {--results= : Ruta donde guardar un CSV con el resultado de cada envío}';

    protected $description = 'Envía un mensaje (SMS o WhatsApp) a una lista de clientes mediante Twilio';

    private const CHANNELS = ['sms', 'whatsapp'];

    public function handle(TwilioWhatsAppService $twilio): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $channel = strtolower(trim((string) $this->option('channel')));

        // ── Canal ────────────────────────────────────────────────────────────
        if (!in_array($channel, self::CHANNELS, true)) {
            $this->error('Canal no válido. Usa --channel=sms o --channel=whatsapp.');
            return self::FAILURE;
        }

        // ── Validaciones de configuración ────────────────────────────────────
        if (!$dryRun && !$this->channelReady($twilio, $channel)) {
            return self::FAILURE;
        }

        // ── Mensaje ──────────────────────────────────────────────────────────
        $message = $this->resolveMessage();
        if ($message === null) {
            $this->error('Debes indicar el mensaje con --message="..." o --message-file=ruta.txt');
            return self::FAILURE;
        }

        // ── Destinatarios ────────────────────────────────────────────────────
        $path = $this->argument('recipients');
        if (!is_file($path)) {
            $this->error("No se encontró el archivo de destinatarios: {$path}");
            return self::FAILURE;
        }

        $defaultCc = preg_replace('/\D+/', '', (string) $this->option('default-country')) ?: null;

        [$recipients, $invalid] = $this->parseRecipients($path, $defaultCc);

        if (!empty($invalid)) {
            $this->warn(sprintf(
                '%d número(s) sin formato E.164 válido se omitirán%s.',
                count($invalid),
                $defaultCc ? '' : ' (usa --default-country=1 para anteponer el código de país)'
            ));
        }

        if (empty($recipients)) {
            $this->error('El archivo no contiene destinatarios válidos.');
            return self::FAILURE;
        }

        $from  = $this->option('from') ?: null;
        $delay = max(0, (int) $this->option('delay'));

        $this->info(sprintf(
            '%s[%s] %d destinatario(s) válido(s). Remitente: %s',
            $dryRun ? '[DRY-RUN] ' : '',
            strtoupper($channel),
            count($recipients),
            $from ?: $this->defaultFrom($channel) ?: '(configurar remitente)'
        ));

        // ── Envío ────────────────────────────────────────────────────────────
        $results = [];
        $sent = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar(count($recipients));
        $bar->start();

        foreach ($recipients as $r) {
            $body = $this->personalize($message, $r['name']);
            $row  = ['phone' => $r['phone'], 'name' => $r['name']];

            if ($dryRun) {
                $row['status'] = 'dry-run';
                $row['detail'] = $body;
            } else {
                try {
                    $resp = $this->dispatch($twilio, $channel, $r['phone'], $body, $from);
                    $row['status'] = $resp['status'] ?? 'queued';
                    $row['detail'] = $resp['sid'] ?? '';
                    $sent++;
                } catch (RequestException $e) {
                    $row['status'] = 'error';
                    $row['detail'] = $this->errorDetail($e);
                    $failed++;
                } catch (\Throwable $e) {
                    $row['status'] = 'error';
                    $row['detail'] = $e->getMessage();
                    $failed++;
                }

                if ($delay > 0) {
                    sleep($delay);
                }
            }

            $results[] = $row;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ── Resumen ──────────────────────────────────────────────────────────
        if ($dryRun) {
            $this->info(sprintf('DRY-RUN completado: %d mensaje(s) preparados (no se envió nada).', count($results)));
        } else {
            $this->info(sprintf('Envío completado: %d enviados, %d fallidos.', $sent, $failed));
        }

        if ($resultsPath = $this->option('results')) {
            // Añade los números descartados al informe para trazabilidad.
            foreach ($invalid as $inv) {
                $results[] = [
                    'phone'  => $inv['raw'],
                    'name'   => $inv['name'],
                    'status' => 'invalid',
                    'detail' => 'Formato de teléfono no válido (se requiere E.164)',
                ];
            }
            $this->writeResults($resultsPath, $results);
            $this->info("Resultados guardados en: {$resultsPath}");
        }

        if (!$dryRun && $failed > 0) {
            $this->warn('Algunos mensajes fallaron. Revisa la columna "detail" en los resultados.');
        }

        return self::SUCCESS;
    }

    // ── Envío por canal ──────────────────────────────────────────────────────

    private function dispatch(TwilioWhatsAppService $twilio, string $channel, string $phone, string $body, ?string $from): array
    {
        return $channel === 'whatsapp'
            ? $twilio->sendMessage($phone, $body)
            : $twilio->sendSms($phone, $body, $from);
    }

    private function channelReady(TwilioWhatsAppService $twilio, string $channel): bool
    {
        if ($channel === 'whatsapp') {
            if (!$twilio->isConfigured() || !config('services.twilio.whatsapp_from')) {
                $this->error('WhatsApp por Twilio no está configurado. Define TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN y TWILIO_WHATSAPP_FROM en tu .env.');
                return false;
            }
            return true;
        }

        if (!$twilio->isSmsConfigured()) {
            $this->error('Twilio SMS no está configurado. Define TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN y TWILIO_SMS_FROM en tu .env.');
            return false;
        }

        return true;
    }

    private function defaultFrom(string $channel): ?string
    {
        return $channel === 'whatsapp'
            ? config('services.twilio.whatsapp_from')
            : config('services.twilio.sms_from');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveMessage(): ?string
    {
        if ($file = $this->option('message-file')) {
            if (!is_file($file)) {
                $this->error("No se encontró el archivo de mensaje: {$file}");
                return null;
            }
            return trim(file_get_contents($file));
        }

        $msg = $this->option('message');

        return ($msg === null || $msg === '') ? null : $msg;
    }

    /**
     * Lee el CSV y devuelve dos listas: destinatarios válidos e inválidos.
     *
     * @return array{0: array<int,array{phone:string,name:string}>, 1: array<int,array{raw:string,name:string}>}
     */
    private function parseRecipients(string $path, ?string $defaultCc): array
    {
        $valid = [];
        $invalid = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [[], []];
        }

        $phoneIdx = 0;
        $nameIdx  = 1;
        $first = true;

        while (($cols = fgetcsv($handle)) !== false) {
            // Ignora líneas vacías.
            if ($cols === [null] || (count($cols) === 1 && trim((string) $cols[0]) === '')) {
                continue;
            }

            // Detección de cabecera en la primera fila.
            if ($first) {
                $first = false;
                $map = $this->detectHeader($cols);
                if ($map !== null) {
                    $phoneIdx = $map['phone'];
                    $nameIdx  = $map['name'];
                    continue; // La cabecera no es un destinatario.
                }
            }

            $raw  = trim((string) ($cols[$phoneIdx] ?? ''));
            $name = isset($cols[$nameIdx]) ? trim((string) $cols[$nameIdx]) : '';

            if ($raw === '') {
                continue; // Fila sin teléfono, se ignora por completo.
            }

            $phone = $this->normalizePhone($raw, $defaultCc);

            if ($phone === null) {
                $invalid[] = ['raw' => $raw, 'name' => $name];
                continue;
            }

            $valid[] = ['phone' => $phone, 'name' => $name];
        }

        fclose($handle);

        return [$valid, $invalid];
    }

    /**
     * Devuelve los índices de columna si la fila parece una cabecera, o null.
     *
     * @return array{phone:int,name:int}|null
     */
    private function detectHeader(array $cols): ?array
    {
        $phoneKeys = ['phone', 'telefono', 'teléfono', 'celular', 'movil', 'móvil', 'numero', 'número'];
        $nameKeys  = ['name', 'nombre', 'cliente', 'contacto'];

        $phoneIdx = null;
        $nameIdx  = null;

        foreach ($cols as $i => $col) {
            $key = strtolower(trim((string) $col));
            if (in_array($key, $phoneKeys, true)) {
                $phoneIdx = $i;
            } elseif (in_array($key, $nameKeys, true)) {
                $nameIdx = $i;
            }
        }

        if ($phoneIdx === null) {
            return null; // No es cabecera reconocible.
        }

        return ['phone' => $phoneIdx, 'name' => $nameIdx ?? ($phoneIdx === 0 ? 1 : 0)];
    }

    /**
     * Normaliza a E.164 (+<código país><número>). Devuelve null si no es válido.
     *
     * - Si empieza por '+', se respeta el código de país que traiga.
     * - Si no, y se indicó --default-country, se antepone ese código (quitando
     *   un 0 inicial de marcación nacional).
     * - Si no hay '+' ni código por defecto, se considera inválido: no se
     *   inventa un país para evitar enviar a números equivocados.
     */
    private function normalizePhone(string $raw, ?string $defaultCc): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            $digits = preg_replace('/\D+/', '', $raw);
            return strlen($digits) >= 8 ? '+' . $digits : null;
        }

        if ($defaultCc) {
            $digits = ltrim(preg_replace('/\D+/', '', $raw), '0');
            if ($digits === '' || strlen($digits) < 6) {
                return null;
            }
            return '+' . $defaultCc . $digits;
        }

        return null;
    }

    private function personalize(string $message, string $name): string
    {
        return str_replace(
            ['{{nombre}}', '{{name}}', '{{NOMBRE}}', '{{NAME}}'],
            $name,
            $message
        );
    }

    private function errorDetail(RequestException $e): string
    {
        if ($e->hasResponse()) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            if (isset($body['message'])) {
                return trim(($body['code'] ?? '') . ' ' . $body['message']);
            }
        }

        return $e->getMessage();
    }

    private function writeResults(string $path, array $results): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            $this->warn("No se pudo escribir el archivo de resultados: {$path}");
            return;
        }

        fputcsv($handle, ['phone', 'name', 'status', 'detail']);
        foreach ($results as $row) {
            fputcsv($handle, [$row['phone'], $row['name'], $row['status'], $row['detail']]);
        }
        fclose($handle);
    }
}

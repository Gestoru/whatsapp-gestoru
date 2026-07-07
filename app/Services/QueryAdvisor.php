<?php

namespace App\Services;

use App\Models\Server;

/**
 * Analiza el reporte de consultas (queryReport) y produce, por consulta:
 * diagnóstico automático en lenguaje claro, severidad y un contexto completo
 * listo para pegar en una IA (ChatGPT / Claude) e iterar la optimización.
 */
class QueryAdvisor
{
    /** @return array<int, array<string, mixed>> consultas enriquecidas, ordenadas por costo */
    public function analyze(array $report, Server $server): array
    {
        $queries  = $report['queries'] ?? [];
        $totalAll = 0.0;
        foreach ($queries as $q) {
            $totalAll += (float) $q['total_s'];
        }
        $totalAll = max(0.1, $totalAll);

        foreach ($queries as $i => &$q) {
            $q['rank']          = $i + 1;
            $q['execs']         = (int) $q['execs'];
            $q['total_s']       = (float) $q['total_s'];
            $q['avg_ms']        = (float) $q['avg_ms'];
            $q['max_ms']        = (float) $q['max_ms'];
            $q['lock_s']        = (float) $q['lock_s'];
            $q['rows_examined'] = (int) $q['rows_examined'];
            $q['rows_sent']     = (int) $q['rows_sent'];
            $q['tmp_disk']      = (int) $q['tmp_disk'];
            $q['full_join']     = (int) $q['full_join'];
            $q['no_index']      = (int) $q['no_index'];

            $q['share']        = round($q['total_s'] / $totalAll * 100);
            $q['ratio']        = $q['rows_sent'] > 0 ? (int) round($q['rows_examined'] / $q['rows_sent']) : null;
            $q['no_index_pct'] = $q['execs'] > 0 ? (int) round($q['no_index'] / $q['execs'] * 100) : 0;

            [$q['severity'], $q['findings']] = $this->diagnose($q);
            $q['ai_prompt'] = $this->buildAiPrompt($q, $report, $server);
        }
        unset($q);

        return $queries;
    }

    /** @return array{0: string, 1: array<int, string>} [severidad, hallazgos] */
    private function diagnose(array $q): array
    {
        $f = [];

        $isLoop = $q['execs'] >= 500_000 && $q['avg_ms'] < 5;
        if ($isLoop) {
            $f[] = 'Se ejecuta '.number_format($q['execs']).' veces con un promedio de solo '.$q['avg_ms'].'ms: el problema no es la consulta sino la FRECUENCIA. Es el patrón típico de un bucle N+1 o un contador que la aplicación repite por cada registro. Cachear el resultado o sacarla del bucle ahorraría ~'.number_format((int) $q['total_s']).'s de carga.';
        }

        if ($q['avg_ms'] >= 1000) {
            $f[] = 'Promedio de '.round($q['avg_ms'] / 1000, 1).'s por ejecución: muy lenta para una consulta de aplicación (lo sano es <100ms). Cada usuario que la dispara se queda esperando.';
        } elseif ($q['avg_ms'] >= 300 && ! $isLoop) {
            $f[] = 'Promedio de '.(int) $q['avg_ms'].'ms por ejecución: lenta; hay margen claro de mejora con índices.';
        }

        if ($q['no_index_pct'] >= 30) {
            $f[] = 'El '.$q['no_index_pct'].'% de las ejecuciones NO usó ningún índice: casi seguro falta un índice en las columnas del WHERE o del JOIN.';
        }

        if ($q['ratio'] !== null && $q['ratio'] >= 100) {
            $f[] = 'Examina '.number_format($q['ratio']).' filas por cada fila que devuelve: los índices actuales no son selectivos para este filtro.';
        }

        if ($q['execs'] > 0 && $q['tmp_disk'] > $q['execs'] * 0.1) {
            $f[] = 'Crea tablas temporales EN DISCO ('.number_format($q['tmp_disk']).' veces): el ORDER BY / GROUP BY no está cubierto por un índice o el resultado intermedio es muy grande.';
        }

        if ($q['full_join'] > 0) {
            $f[] = 'Hace JOINs sin índice ('.number_format($q['full_join']).' full joins): falta un índice en la columna de unión.';
        }

        if ($q['lock_s'] > 0 && $q['total_s'] > 0 && $q['lock_s'] >= $q['total_s'] * 0.2) {
            $f[] = 'Pasa '.number_format((int) $q['lock_s']).'s esperando bloqueos: compite con otras consultas por las mismas filas/tablas.';
        }

        if (empty($f)) {
            $f[] = 'Sin señales graves: su costo viene del volumen de uso normal. Aun así, cachear resultados frecuentes la bajaría del ranking.';
        }

        $severity = 'media';
        if ($q['share'] >= 15 || $q['avg_ms'] >= 1000 || ($q['no_index_pct'] >= 50 && $q['total_s'] >= 600)) {
            $severity = 'critica';
        } elseif ($q['share'] >= 8 || $q['avg_ms'] >= 300 || $q['no_index_pct'] >= 30 || $isLoop) {
            $severity = 'alta';
        }

        return [$severity, $f];
    }

    /** Contexto completo, en texto plano, listo para pegar en una IA. */
    private function buildAiPrompt(array $q, array $report, Server $server): string
    {
        $gb  = $report['buffer'] ? round($report['buffer'] / 1073741824, 1).' GB' : 'desconocido';
        $up  = $report['uptime'] ? round($report['uptime'] / 86400, 1).' días' : 'desconocido';
        $usr = ! empty($q['users']) ? implode(', ', $q['users']) : 'no identificado en la muestra reciente';

        $lines = [
            'Actúa como un DBA experto en MySQL/MariaDB y en aplicaciones PHP/Laravel.',
            'Analiza esta consulta de producción y dame un plan de optimización concreto.',
            '',
            '## Servidor',
            '- Motor: '.($report['version'] ?? 'MySQL').' (vía '.($report['via'] ?? '?').')',
            '- Uptime de MySQL: '.$up.' · innodb_buffer_pool_size: '.$gb.' · max_connections: '.($report['max_conn'] ?? '?'),
            '',
            '## Consulta (normalizada, los ? son parámetros)',
            '```sql',
            $q['query'],
            '```',
            '- Base de datos: '.$q['db'],
            '- Usuario(s) MySQL que la ejecutan: '.$usr,
            '',
            '## Estadísticas acumuladas (performance_schema, desde el último reinicio)',
            '- Ejecuciones: '.number_format($q['execs']),
            '- Tiempo total: '.number_format((int) $q['total_s']).' s ('.$q['share'].'% de la carga total de consultas)',
            '- Promedio: '.$q['avg_ms'].' ms · Máximo: '.$q['max_ms'].' ms · Tiempo en bloqueos: '.$q['lock_s'].' s',
            '- Filas examinadas: '.number_format($q['rows_examined']).' · Filas devueltas: '.number_format($q['rows_sent']).($q['ratio'] !== null ? ' (ratio '.number_format($q['ratio']).':1)' : ''),
            '- Ejecuciones sin usar índice: '.number_format($q['no_index']).' ('.$q['no_index_pct'].'%)',
            '- Tablas temporales en disco: '.number_format($q['tmp_disk']).' · Full joins: '.number_format($q['full_join']),
            '- Vista por primera vez: '.$q['first_seen'].' · Última vez: '.$q['last_seen'],
            '',
            '## Diagnóstico preliminar del panel',
        ];
        foreach ($q['findings'] as $fnd) {
            $lines[] = '- '.$fnd;
        }

        $lines[] = '';
        $lines[] = '## Estructura de las tablas involucradas';
        $ddl = $report['ddl'] ?? [];
        if (empty($q['tables'])) {
            $lines[] = '(no se pudieron identificar las tablas automáticamente)';
        }
        foreach ($q['tables'] as $t) {
            if (isset($ddl[$t])) {
                $meta = [];
                if ($ddl[$t]['rows'] !== null) {
                    $meta[] = '~'.number_format($ddl[$t]['rows']).' filas';
                }
                if ($ddl[$t]['mb'] !== null) {
                    $meta[] = $ddl[$t]['mb'].' MB (datos+índices)';
                }
                $lines[] = '';
                $lines[] = '### '.$t.($meta ? ' — '.implode(' · ', $meta) : '');
                $lines[] = '```sql';
                $lines[] = $ddl[$t]['create'];
                $lines[] = '```';
            } else {
                $lines[] = '- '.$t.' (no se pudo leer su estructura)';
            }
        }

        $lines[] = '';
        $lines[] = '## Qué necesito de ti';
        $lines[] = '1. Diagnóstico: cuál es el problema principal de esta consulta.';
        $lines[] = '2. Índices exactos a crear si aplican (sentencias CREATE INDEX listas para ejecutar) y por qué.';
        $lines[] = '3. Reescritura de la consulta si conviene.';
        $lines[] = '4. Cambios recomendados en la aplicación (cache, evitar N+1, paginación, denormalizar un contador, etc.).';
        $lines[] = '5. Riesgos y precauciones antes de aplicar cada cambio en producción.';
        $lines[] = 'Si te falta información (un EXPLAIN con valores reales, otra tabla, variables del servidor), dime exactamente qué comando debo ejecutar y te pego el resultado.';

        return implode("\n", $lines);
    }
}

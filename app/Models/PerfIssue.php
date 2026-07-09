<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerfIssue extends Model
{
    protected $fillable = [
        'server_id', 'kind', 'signature', 'title', 'summary', 'ai_cause', 'ai_prompt',
        'severity', 'status', 'status_manual', 'metrics', 'occurrences', 'reopened_count',
        'score', 'link', 'first_detected_at', 'last_seen_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'metrics'           => 'array',
            'status_manual'     => 'boolean',
            'score'             => 'float',
            'first_detected_at' => 'datetime',
            'last_seen_at'      => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    /** Columnas del tablero, en orden. */
    public const COLUMNS = [
        'por_revisar' => ['📥', 'Por revisar',     '#7dd3fc'],
        'optimizando' => ['🔧', 'En optimización', '#fbbf24'],
        'resuelta'    => ['✅', 'Resueltas',        '#22e39b'],
        'aceptada'    => ['🔒', 'No aplica',        '#94a3c4'],
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PerfIssueEvent::class)->orderByDesc('happened_at');
    }

    /** Registra un evento en la trazabilidad de esta incidencia. */
    public function logEvent(string $type, ?string $note = null): void
    {
        $this->events()->create([
            'type'        => $type,
            'note'        => $note,
            'happened_at' => now(),
        ]);
    }
}

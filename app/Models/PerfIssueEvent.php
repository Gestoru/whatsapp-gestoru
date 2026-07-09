<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerfIssueEvent extends Model
{
    protected $fillable = ['perf_issue_id', 'type', 'note', 'happened_at'];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    /** Icono por tipo de evento, para el historial. */
    public function icon(): string
    {
        return [
            'detectada'     => '🔍',
            'reaparecio'    => '🔁',
            'resuelta_auto' => '✅',
            'movida'        => '↔️',
        ][$this->type] ?? '•';
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(PerfIssue::class, 'perf_issue_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plan de optimización generado con IA para una incidencia del tablero.
 * Guarda el repositorio mapeado, la investigación de archivos, el plan en
 * Markdown, la conversación de ajustes y la referencia en GitHub al publicar.
 */
class PerfPlan extends Model
{
    protected $fillable = [
        'perf_issue_id', 'repository_id', 'repository_full_name', 'status',
        'plan', 'edits', 'investigation', 'chat', 'model', 'error',
        'github_branch', 'github_pr_url', 'github_file_url', 'code_commit_url',
        'generated_at', 'pushed_at', 'code_pushed_at',
    ];

    protected function casts(): array
    {
        return [
            'investigation' => 'array',
            'chat'          => 'array',
            'edits'         => 'array',
            'generated_at'  => 'datetime',
            'pushed_at'     => 'datetime',
            'code_pushed_at' => 'datetime',
        ];
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(PerfIssue::class, 'perf_issue_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** Añade un mensaje a la conversación con la IA (rol: user|assistant). */
    public function pushChat(string $role, string $content): void
    {
        $chat = $this->chat ?? [];
        $chat[] = ['role' => $role, 'content' => $content, 'at' => now()->format('d/m/Y H:i')];
        $this->update(['chat' => $chat]);
    }
}

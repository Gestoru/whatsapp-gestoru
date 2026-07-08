<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuerySnapshot extends Model
{
    protected $fillable = [
        'server_id', 'digest', 'db', 'query_preview',
        'total_s', 'execs', 'avg_ms', 'rows_ratio', 'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'seen_at'  => 'datetime',
            'total_s'  => 'float',
            'avg_ms'   => 'float',
            'execs'    => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Última foto guardada de cada digest de un servidor (la más reciente por
     * cada consulta), indexada por digest.
     *
     * @return array<string, self>
     */
    public static function latestPerDigest(int $serverId): array
    {
        $out = [];
        self::where('server_id', $serverId)
            ->orderBy('seen_at')
            ->get()
            ->each(function (self $s) use (&$out) {
                $out[$s->digest] = $s; // el último gana por el orden asc
            });

        return $out;
    }
}

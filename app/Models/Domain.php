<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $fillable = [
        'name', 'registrar', 'expires_at', 'renewal_url', 'notes', 'source', 'whois_checked_at',
        'status', 'auto_renew', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'       => 'date',
            'whois_checked_at' => 'datetime',
            'synced_at'        => 'datetime',
            'auto_renew'       => 'boolean',
        ];
    }

    /** Etiqueta legible del estado del dominio. */
    public function getStatusLabelAttribute(): ?string
    {
        if (! $this->status) {
            return null;
        }

        return match (strtoupper($this->status)) {
            'ACTIVE'                 => 'Activo',
            'EXPIRED'                => 'Vencido',
            'CANCELLED', 'CANCELED'  => 'Cancelado',
            'PENDING', 'AWAITING_*'  => 'Pendiente',
            'SUSPENDED'              => 'Suspendido',
            'TRANSFERRED_OUT'        => 'Transferido',
            default                  => ucfirst(strtolower(str_replace('_', ' ', $this->status))),
        };
    }

    /** ok | warn | bad según el estado. */
    public function statusTone(): string
    {
        return match (strtoupper((string) $this->status)) {
            'ACTIVE'                          => 'ok',
            'EXPIRED', 'SUSPENDED', 'CANCELLED', 'CANCELED' => 'bad',
            ''                                => 'muted',
            default                           => 'warn',
        };
    }

    /** Días restantes hasta el vencimiento (negativo si ya venció). */
    public function daysLeft(): ?int
    {
        return $this->expires_at
            ? (int) now()->startOfDay()->diffInDays($this->expires_at, false)
            : null;
    }

    /** ok | warn (<=30 días) | due (<=10 días o vencido) | unknown */
    public function expiryLevel(): string
    {
        $days = $this->daysLeft();

        return match (true) {
            $days === null => 'unknown',
            $days <= 10    => 'due',
            $days <= 30    => 'warn',
            default        => 'ok',
        };
    }

    public function getRegistrarLabelAttribute(): string
    {
        return match ($this->registrar) {
            'godaddy'    => 'GoDaddy',
            'ionos'      => 'IONOS',
            'winhosting' => 'Winhosting',
            default      => ucfirst($this->registrar),
        };
    }

    /** Enlace de renovación: el guardado o el del registrador. */
    public function renewalLink(): ?string
    {
        return $this->renewal_url ?: match ($this->registrar) {
            'godaddy' => 'https://account.godaddy.com/products',
            'ionos'   => 'https://my.ionos.com/domains',
            default   => null,
        };
    }
}

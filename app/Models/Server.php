<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $fillable = [
        'name',
        'provider',
        'host',
        'port',
        'username',
        'auth_type',
        'password',
        'private_key',
        'key_passphrase',
        'color',
        'notes',
        'is_active',
        'paid_until',
        'monthly_cost',
        'renewal_url',
    ];

    /**
     * Los secretos se cifran en reposo con APP_KEY.
     */
    protected $hidden = [
        'password',
        'private_key',
        'key_passphrase',
    ];

    protected function casts(): array
    {
        return [
            'password'       => 'encrypted',
            'private_key'    => 'encrypted',
            'key_passphrase' => 'encrypted',
            'is_active'      => 'boolean',
            'port'           => 'integer',
            'paid_until'     => 'date',
            'monthly_cost'   => 'decimal:2',
        ];
    }

    /** Días restantes del plan (negativo si ya venció). */
    public function paymentDaysLeft(): ?int
    {
        return $this->paid_until
            ? (int) now()->startOfDay()->diffInDays($this->paid_until, false)
            : null;
    }

    /** ok | warn (<=15 días) | due (<=7 días o vencido) | unknown */
    public function paymentLevel(): string
    {
        $days = $this->paymentDaysLeft();

        return match (true) {
            $days === null => 'unknown',
            $days <= 7     => 'due',
            $days <= 15    => 'warn',
            default        => 'ok',
        };
    }

    /** Enlace de pago: el guardado o el del proveedor. */
    public function renewalLink(): ?string
    {
        return $this->renewal_url ?: match ($this->provider) {
            'contabo' => 'https://my.contabo.com/invoices',
            default   => null,
        };
    }

    /**
     * ¿Ya tiene credenciales para conectarse?
     */
    public function hasCredentials(): bool
    {
        return $this->auth_type === 'key'
            ? filled($this->private_key)
            : filled($this->password);
    }

    public function getProviderLabelAttribute(): string
    {
        return match ($this->provider) {
            'contabo'    => 'Contabo',
            'winhosting' => 'Winhosting',
            default      => ucfirst($this->provider),
        };
    }
}

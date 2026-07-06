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
        ];
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

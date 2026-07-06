<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $fillable = [
        'key',
        'name',
        'group',
        'host',
        'port',
        'username',
        'auth_method',
        'private_key_path',
        'password',
        'base_path',
        'notes',
        'is_active',
        'last_checked_at',
    ];

    protected $casts = [
        'port' => 'integer',
        'is_active' => 'boolean',
        'password' => 'encrypted', // se guarda cifrada en la base de datos
        'last_checked_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'private_key_path',
    ];

    /**
     * Ruta base efectiva a listar al abrir el servidor.
     */
    public function basePath(): string
    {
        return $this->base_path ?: config('servers.default_base_path', '/var/www');
    }

    /**
     * Convierte el registro (o un array del inventario de config) en un
     * arreglo de conexión que entiende el SshClient.
     */
    public function connectionConfig(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_method' => $this->auth_method,
            'private_key' => $this->private_key_path,
            'password' => $this->password,
        ];
    }
}

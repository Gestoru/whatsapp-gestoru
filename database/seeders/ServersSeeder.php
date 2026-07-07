<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;

/**
 * Pre-carga los servidores de la empresa. Solo crea los que no existan
 * (por host) — nunca toca credenciales ni cambios hechos desde el panel.
 */
class ServersSeeder extends Seeder
{
    public function run(): void
    {
        $servers = [
            [
                'host'  => '2.58.82.99',
                'name'  => 'Tienda Gestor y Backend Catálogo',
                'color' => '#f59e0b',
                'notes' => 'Contabo vmi3400758 · Cloud VPS 30 SSD · EU',
            ],
            [
                'host'  => '194.163.159.44',
                'name'  => 'GestorDeParte.net y Gestoru.com',
                'color' => '#22c55e',
                'notes' => 'Contabo vmi3298959 · Cloud VPS 40 SSD · EU',
            ],
            [
                'host'  => '217.216.65.118',
                'name'  => 'Servidor Personal Samuel',
                'color' => '#38bdf8',
                'notes' => 'Contabo vmi3336150 · Cloud VPS 20 NVMe · US-east',
            ],
            [
                'host'  => '157.173.194.26',
                'name'  => 'Apps Varias / Storage / GST2 / APIs',
                'color' => '#a78bfa',
                'notes' => 'Contabo vmi2229710 · Cloud VPS 30 SSD · US-central · aquí vive este panel',
            ],
            [
                'host'     => '72.14.182.249',
                'name'     => 'Winhosting',
                'provider' => 'winhosting',
                'color'    => '#f472b6',
                'notes'    => 'Servidor Winhosting · acceso SSH como root',
            ],
            [
                'host'     => '152.233.22.46',
                'name'     => 'Winkhosting · gestordesalud.co',
                'provider' => 'winhosting',
                'username' => 'gestord1',
                'port'     => 22902,
                'color'    => '#38bdf8',
                'notes'    => 'Winkhosting cPanel · usuario gestord1 · dominio gestordesalud.co · dir /home/gestord1 · si no conecta, ajusta el puerto SSH',
            ],
        ];

        foreach ($servers as $data) {
            Server::firstOrCreate(
                ['host' => $data['host']],
                $data + [
                    'provider'  => 'contabo',
                    'port'      => 22,
                    'username'  => 'root',
                    'auth_type' => 'password',
                    'is_active' => true,
                ]
            );
        }
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panel de Administración de Servidores
    |--------------------------------------------------------------------------
    |
    | Configuración del panel que se conecta por SSH a tus servidores
    | (VPS Linux, WinHosting, etc.) para mostrar carpetas/proyectos y
    | métricas de rendimiento.
    |
    */

    // Contraseña de acceso al panel /admin (sesión). Cámbiala en .env.
    'admin_password' => env('SERVER_ADMIN_PASSWORD'),

    // Tiempo máximo (segundos) para conexión y ejecución de comandos SSH.
    'ssh_timeout' => (int) env('SERVER_SSH_TIMEOUT', 15),

    // Ruta base que se listará al abrir un servidor (carpetas/proyectos).
    'default_base_path' => env('SERVER_DEFAULT_BASE_PATH', '/var/www'),

    /*
    | Modo de operación:
    |  - readonly : solo comandos de lectura de la lista blanca (recomendado)
    |  - actions  : permite además ejecutar comandos permitidos (reinicios, etc.)
    */
    'mode' => env('SERVER_ADMIN_MODE', 'readonly'),

    /*
    | Servidores precargados desde variables de entorno. También puedes
    | añadir/editar servidores desde la propia interfaz del panel (se guardan
    | en la base de datos con la contraseña cifrada).
    |
    | Define varios separando con índices, p. ej. SERVER_1_HOST, SERVER_2_HOST…
    */
    'inventory' => array_values(array_filter([
        env('SERVER_1_HOST') ? [
            'key' => env('SERVER_1_KEY', 'servidor-1'),
            'name' => env('SERVER_1_NAME', 'Servidor 1'),
            'group' => env('SERVER_1_GROUP', 'vps'),
            'host' => env('SERVER_1_HOST'),
            'port' => (int) env('SERVER_1_PORT', 22),
            'username' => env('SERVER_1_USER', 'root'),
            'auth_method' => env('SERVER_1_AUTH', 'key'), // key | password
            'private_key' => env('SERVER_1_KEY_PATH'),
            'password' => env('SERVER_1_PASSWORD'),
            'base_path' => env('SERVER_1_BASE_PATH'),
        ] : null,
        env('SERVER_2_HOST') ? [
            'key' => env('SERVER_2_KEY', 'servidor-2'),
            'name' => env('SERVER_2_NAME', 'Servidor 2'),
            'group' => env('SERVER_2_GROUP', 'winhosting'),
            'host' => env('SERVER_2_HOST'),
            'port' => (int) env('SERVER_2_PORT', 22),
            'username' => env('SERVER_2_USER'),
            'auth_method' => env('SERVER_2_AUTH', 'password'),
            'private_key' => env('SERVER_2_KEY_PATH'),
            'password' => env('SERVER_2_PASSWORD'),
            'base_path' => env('SERVER_2_BASE_PATH'),
        ] : null,
    ])),

];

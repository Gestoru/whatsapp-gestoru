<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contraseña del dashboard de infraestructura
    |--------------------------------------------------------------------------
    |
    | Contraseña única para entrar al panel de servidores. Defínela en tu .env
    | como DASHBOARD_PASSWORD. Si queda vacía, el panel no exige login (solo
    | recomendado en entornos locales).
    |
    */

    'password' => env('DASHBOARD_PASSWORD'),

    // Marca corta que se muestra como logo de texto en la barra lateral.
    'brand' => env('DASHBOARD_BRAND', 'NEXO'),

    'title' => env('DASHBOARD_TITLE', 'Infraestructura Gestoru'),

    // A partir de qué % de CPU se considera "pico" y se genera evento/registro.
    'cpu_peak_threshold' => (int) env('DASHBOARD_CPU_PEAK', 50),

    // A partir de qué % de CPU el pico es CRÍTICO: el panel lo registra al
    // instante (sin esperar el muestreo de 5 min) y suena la alarma sísmica.
    'cpu_critical_threshold' => (int) env('DASHBOARD_CPU_CRITICAL', 90),

    // Modelo de IA (API de Anthropic) para generar los planes de optimización
    // del tablero. Se puede sobreescribir con ANTHROPIC_MODEL en el .env.
    'ai_model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),

];

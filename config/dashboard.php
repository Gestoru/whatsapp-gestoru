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

    'title' => env('DASHBOARD_TITLE', 'Infraestructura Gestoru'),

];

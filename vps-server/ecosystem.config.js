// Configuración para PM2 — mantiene el servidor vivo en el VPS
module.exports = {
    apps: [
        {
            name:        'wpp-gestoru-vps',
            script:      'server.js',
            instances:   1,
            exec_mode:   'fork',   // NUNCA cluster: es un único servidor con sesión de WhatsApp
            autorestart: true,
            watch:       false,
            max_memory_restart: '500M',
            env: {
                NODE_ENV: 'production',
            },
        },
    ],
};

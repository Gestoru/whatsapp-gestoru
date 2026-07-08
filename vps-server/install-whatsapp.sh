#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────
#  Instalador del servidor de WhatsApp (whatsapp-web.js) para las Alertas.
#  Deja el servidor corriendo con PM2 en el puerto 3000. Best-effort: los
#  pasos opcionales no detienen la instalación si fallan.
# ──────────────────────────────────────────────────────────────────────────
set -uo pipefail
cd "$(dirname "$0")"

say(){ printf '\n\033[1;36m%s\033[0m\n' "$*"; }
ok(){ printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn(){ printf '\033[1;33m⚠ %s\033[0m\n' "$*"; }

# ── 1. Node.js 18+ ──────────────────────────────────────────────────────────
say "1/5 · Node.js"
NEED_NODE=1
if command -v node >/dev/null 2>&1; then
    MAJ=$(node -v | sed 's/v//; s/\..*//')
    [ "${MAJ:-0}" -ge 18 ] 2>/dev/null && NEED_NODE=0
fi
if [ "$NEED_NODE" = 1 ]; then
    warn "Instalando Node.js 20…"
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >/dev/null 2>&1 || true
    apt-get install -y nodejs >/dev/null 2>&1 || true
fi
command -v node >/dev/null 2>&1 && ok "Node $(node -v)" || { echo "No se pudo instalar Node.js"; exit 1; }

# ── 2. Navegador para whatsapp-web.js (Google Chrome trae sus dependencias) ──
say "2/5 · Navegador (Google Chrome)"
apt-get update -qq 2>/dev/null || true
if command -v google-chrome-stable >/dev/null 2>&1 || command -v chromium-browser >/dev/null 2>&1 || command -v chromium >/dev/null 2>&1; then
    ok "Ya hay un navegador Chromium/Chrome"
else
    warn "Instalando Google Chrome stable…"
    curl -fsSL -o /tmp/google-chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb 2>/dev/null || true
    apt-get install -y /tmp/google-chrome.deb >/dev/null 2>&1 \
      || { apt-get install -f -y >/dev/null 2>&1; apt-get install -y /tmp/google-chrome.deb >/dev/null 2>&1; } || true
    rm -f /tmp/google-chrome.deb
fi
# Librerías por si se usa el Chromium incluido en puppeteer
apt-get install -y -qq \
  ca-certificates fonts-liberation libatk-bridge2.0-0 libatk1.0-0 libcups2 \
  libdbus-1-3 libdrm2 libgbm1 libgtk-3-0 libnspr4 libnss3 libxcomposite1 \
  libxdamage1 libxrandr2 libasound2 xdg-utils >/dev/null 2>&1 || true
command -v google-chrome-stable >/dev/null 2>&1 && ok "Google Chrome: $(command -v google-chrome-stable)" || warn "Se usará el Chromium de puppeteer"

# ── 3. npm install ───────────────────────────────────────────────────────────
say "3/5 · Instalando el servidor (npm)"
npm install --omit=dev || { echo "Falló npm install"; exit 1; }
ok "Dependencias Node instaladas"

# ── 4. Configuración (.env) ──────────────────────────────────────────────────
say "4/5 · Configuración"
if [ -f .env ]; then
    ok "Ya existe vps-server/.env — se conserva"
else
    SECRET=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
    PANEL_PORT="${DASHBOARD_PORT:-8088}"
    cat > .env <<EOF
PORT=3000
LARAVEL_WEBHOOK_URL=http://127.0.0.1:${PANEL_PORT}/api/whatsapp/webhook-from-vps
WEBHOOK_SECRET=${SECRET}
AUTO_INIT=true
EOF
    ok "Creado vps-server/.env"
    warn "WEBHOOK_SECRET=${SECRET}"
    warn "→ Copia ese valor a WEBHOOK_SECRET en el .env del panel y corre:  php artisan config:cache"
fi

# ── 5. Arranque con PM2 ──────────────────────────────────────────────────────
say "5/5 · Arrancando con PM2"
command -v pm2 >/dev/null 2>&1 || npm install -g pm2 >/dev/null 2>&1 || true
if command -v pm2 >/dev/null 2>&1; then
    # Borra cualquier instancia previa para no duplicar ni chocar en el puerto 3000
    pm2 delete wpp-gestoru-vps >/dev/null 2>&1 || true
    pm2 start ecosystem.config.js
    pm2 save >/dev/null 2>&1 || true
    ok "Servidor en marcha (pm2 status para verlo)"
else
    warn "PM2 no disponible; arranca manual:  node server.js"
fi

cat <<EOF

┌────────────────────────────────────────────────────────────────┐
│  Servidor de WhatsApp listo en  http://127.0.0.1:3000          │
└────────────────────────────────────────────────────────────────┘
  Siguiente:
  1) Asegura el mismo WEBHOOK_SECRET en el .env del panel + config:cache.
  2) Panel → Configuración → Alertas → Servidor de WhatsApp:
       URL = http://127.0.0.1:3000  → Guardar servidor
  3) «Vincular WhatsApp (mostrar QR)» y escanéalo desde tu teléfono.
  Logs / QR en texto:  pm2 logs wpp-gestoru-vps

EOF

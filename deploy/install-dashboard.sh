#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════════
#  Instalador del Dashboard de Infraestructura Gestoru
#  Probado en Ubuntu 20.04 / 22.04 / 24.04 (Contabo VPS)
#
#  Uso (como root, desde la carpeta del repositorio ya clonado):
#      bash deploy/install-dashboard.sh
#
#  Qué hace:
#   1. Instala PHP 8.3 + extensiones, nginx y utilidades
#   2. Configura la app en /opt/gestoru-dashboard
#   3. Crea la base de datos (SQLite) y ejecuta migraciones
#   4. Publica el panel en el puerto 8088 con nginx + php-fpm
#   5. Te pide la contraseña con la que entrarás al panel
#
#  Es seguro re-ejecutarlo: conserva tu configuración y tus servidores.
# ══════════════════════════════════════════════════════════════════════════
set -uo pipefail

APP_DIR="/opt/gestoru-dashboard"
PORT="${DASHBOARD_PORT:-8088}"
PHPV="8.3"
PHP_BIN="/usr/bin/php${PHPV}"
FPM_SOCK="/run/php/php${PHPV}-fpm.sock"

log()  { echo -e "\n\033[1;36m▶ $*\033[0m"; }
ok()   { echo -e "\033[1;32m✔ $*\033[0m"; }
fail() { echo -e "\033[1;31m✘ $*\033[0m"; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Ejecuta este script como root (o con sudo)."

# ── 0. Comprobaciones previas ───────────────────────────────────────────────
if ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ":${PORT}\$"; then
    fail "El puerto ${PORT} ya está ocupado en este servidor. Vuelve a ejecutar con otro puerto: DASHBOARD_PORT=8090 bash deploy/install-dashboard.sh"
fi

# Recordar el PHP por defecto actual para NO romper apps existentes
PREV_PHP="$(readlink -f /usr/bin/php 2>/dev/null || true)"
HAD_NGINX="$(command -v nginx || true)"

# ── 1. Paquetes del sistema ─────────────────────────────────────────────────
log "Actualizando índices de paquetes (los errores de repositorios ajenos no detienen la instalación)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq 2>/dev/null || true

if ! apt-cache show "php${PHPV}-cli" >/dev/null 2>&1; then
    log "Agregando repositorio de PHP ${PHPV} (ppa:ondrej/php)…"
    apt-get install -y -qq software-properties-common >/dev/null 2>&1 || true
    add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || fail "No se pudo agregar el repositorio de PHP."
    apt-get update -qq 2>/dev/null || true
fi

log "Instalando PHP ${PHPV}, nginx y utilidades…"
apt-get install -y -qq \
    "php${PHPV}-cli" "php${PHPV}-fpm" "php${PHPV}-sqlite3" "php${PHPV}-mbstring" \
    "php${PHPV}-xml" "php${PHPV}-curl" "php${PHPV}-zip" \
    nginx unzip git rsync >/dev/null || fail "Falló la instalación de paquetes. Revisa el mensaje de arriba."
ok "Paquetes instalados"

# Restaurar el PHP por defecto anterior (no afectar apps/cron existentes)
if [ -n "$PREV_PHP" ] && [ -x "$PREV_PHP" ] && [ "$PREV_PHP" != "$PHP_BIN" ]; then
    update-alternatives --set php "$PREV_PHP" >/dev/null 2>&1 || true
    ok "PHP por defecto del sistema conservado ($PREV_PHP)"
fi

systemctl enable --now "php${PHPV}-fpm" >/dev/null 2>&1 || true
[ -S "$FPM_SOCK" ] || systemctl restart "php${PHPV}-fpm" || true
[ -S "$FPM_SOCK" ] || fail "No se encontró el socket de php-fpm en $FPM_SOCK"

# ── 2. Código de la aplicación ──────────────────────────────────────────────
log "Copiando la aplicación a $APP_DIR…"
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$APP_DIR"
rsync -a --delete \
    --exclude '.git' --exclude 'node_modules' --exclude 'vps-server' \
    --exclude '.env' --exclude 'database/database.sqlite' \
    "$REPO_DIR"/ "$APP_DIR"/ || fail "No se pudo copiar la aplicación."
cd "$APP_DIR" || fail "No existe $APP_DIR"

[ -f vendor.zip ] || fail "No existe vendor.zip en el repositorio."
rm -rf vendor
unzip -qo vendor.zip || fail "No se pudo descomprimir vendor.zip"
ok "Dependencias PHP desplegadas (vendor.zip)"

# ── 3. Configuración (.env) ─────────────────────────────────────────────────
IP=$(hostname -I | awk '{print $1}')
if [ ! -f .env ]; then
    log "Creando configuración .env…"
    cp .env.example .env

    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
    sed -i "s|^APP_URL=.*|APP_URL=http://${IP}:${PORT}|" .env

    # Contraseña del panel
    if [ -z "${DASHBOARD_PASSWORD:-}" ]; then
        echo
        read -r -s -p "🔑 Inventa la contraseña con la que entrarás al panel y presiona Enter: " DASHBOARD_PASSWORD
        echo
    fi
    [ -n "$DASHBOARD_PASSWORD" ] || fail "La contraseña del panel no puede quedar vacía."
    sed -i "s|^DASHBOARD_PASSWORD=.*|DASHBOARD_PASSWORD=${DASHBOARD_PASSWORD}|" .env

    "$PHP_BIN" artisan key:generate --force >/dev/null || fail "No se pudo generar la clave de la app."
    ok "Configuración creada"
else
    ok "Ya existía .env — se conserva (actualización de código)"
fi

# ── 4. Base de datos ────────────────────────────────────────────────────────
log "Preparando base de datos…"
mkdir -p database
touch database/database.sqlite
"$PHP_BIN" artisan migrate --force || fail "Fallaron las migraciones."
"$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true
ok "Base de datos lista"

# Permisos para nginx/php-fpm
chown -R www-data:www-data storage bootstrap/cache database
chmod -R ug+rwX storage bootstrap/cache database

# ── 5. nginx ────────────────────────────────────────────────────────────────
log "Publicando el panel en el puerto ${PORT}…"
cat > /etc/nginx/sites-available/gestoru-dashboard <<NGINX
server {
    listen ${PORT};
    server_name _;
    root ${APP_DIR}/public;
    index index.php;

    client_max_body_size 20m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known) { deny all; }
}
NGINX
mkdir -p /etc/nginx/sites-enabled
ln -sf /etc/nginx/sites-available/gestoru-dashboard /etc/nginx/sites-enabled/gestoru-dashboard
nginx -t >/dev/null 2>&1 || fail "La configuración de nginx no es válida (ejecuta: nginx -t)"

if systemctl is-active --quiet nginx; then
    systemctl reload nginx || fail "No se pudo recargar nginx."
else
    # nginx recién instalado: si el puerto 80 está ocupado (p. ej. apache),
    # quitamos su sitio por defecto para que solo escuche nuestro puerto.
    if [ -z "$HAD_NGINX" ] && ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ':80$'; then
        rm -f /etc/nginx/sites-enabled/default
    fi
    systemctl enable --now nginx || fail "nginx no pudo iniciar. Ejecuta: journalctl -u nginx --no-pager | tail"
fi
ok "nginx configurado"

# Abrir el puerto en ufw si está activo
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow "${PORT}/tcp" >/dev/null 2>&1 || true
    ok "Puerto ${PORT} permitido en ufw"
fi

# ── Final ───────────────────────────────────────────────────────────────────
echo
echo "══════════════════════════════════════════════════════════════"
echo -e "  \033[1;32m🎉 ¡Dashboard instalado!\033[0m"
echo
echo -e "  Ábrelo en tu navegador:  \033[1;33mhttp://${IP}:${PORT}\033[0m"
echo "  Entra con la contraseña que escribiste."
echo
echo "  Luego usa el botón «＋ Agregar servidor» para registrar"
echo "  cada uno de tus servidores (IP, usuario root y contraseña)."
echo
echo "  ⚠️  Si no abre, revisa que el puerto ${PORT} esté permitido en"
echo "     el Firewall de Contabo (panel de Contabo → Firewall)."
echo "══════════════════════════════════════════════════════════════"

#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════════
#  Instalador del Dashboard de Infraestructura Gestoru
#  Probado en Ubuntu 22.04 / 24.04 (Contabo VPS)
#
#  Uso (como root, desde la carpeta del repositorio ya clonado):
#      bash deploy/install-dashboard.sh
#
#  Qué hace:
#   1. Instala PHP + extensiones, nginx y utilidades
#   2. Configura la app en /opt/gestoru-dashboard
#   3. Crea la base de datos (SQLite) y ejecuta migraciones
#   4. Publica el panel en el puerto 8088 con nginx + php-fpm
#   5. Te pide la contraseña con la que entrarás al panel
# ══════════════════════════════════════════════════════════════════════════
set -euo pipefail

APP_DIR="/opt/gestoru-dashboard"
PORT="${DASHBOARD_PORT:-8088}"

log()  { echo -e "\n\033[1;36m▶ $*\033[0m"; }
ok()   { echo -e "\033[1;32m✔ $*\033[0m"; }
fail() { echo -e "\033[1;31m✘ $*\033[0m"; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Ejecuta este script como root (o con sudo)."

# ── 1. Paquetes del sistema ─────────────────────────────────────────────────
log "Instalando PHP, nginx y utilidades…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq \
    php-cli php-fpm php-sqlite3 php-mbstring php-xml php-curl php-zip \
    nginx unzip git rsync >/dev/null
ok "Paquetes instalados"

PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
FPM_SOCK="/run/php/php${PHPV}-fpm.sock"
[ -S "$FPM_SOCK" ] || systemctl restart "php${PHPV}-fpm" || true
[ -S "$FPM_SOCK" ] || fail "No se encontró el socket de php-fpm en $FPM_SOCK"

# ── 2. Código de la aplicación ──────────────────────────────────────────────
log "Copiando la aplicación a $APP_DIR…"
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$APP_DIR"
rsync -a --delete \
    --exclude '.git' --exclude 'node_modules' --exclude 'vps-server' \
    --exclude '.env' --exclude 'database/database.sqlite' \
    "$REPO_DIR"/ "$APP_DIR"/
cd "$APP_DIR"

[ -f vendor.zip ] || fail "No existe vendor.zip en el repositorio."
rm -rf vendor
unzip -qo vendor.zip
ok "Dependencias PHP desplegadas (vendor.zip)"

# ── 3. Configuración (.env) ─────────────────────────────────────────────────
if [ ! -f .env ]; then
    log "Creando configuración .env…"
    cp .env.example .env

    IP=$(hostname -I | awk '{print $1}')
    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
    sed -i "s|^APP_URL=.*|APP_URL=http://${IP}:${PORT}|" .env

    # Contraseña del panel
    if [ -z "${DASHBOARD_PASSWORD:-}" ]; then
        echo
        read -r -s -p "🔑 Escribe la contraseña con la que entrarás al panel: " DASHBOARD_PASSWORD
        echo
    fi
    [ -n "$DASHBOARD_PASSWORD" ] || fail "La contraseña del panel no puede quedar vacía."
    sed -i "s|^DASHBOARD_PASSWORD=.*|DASHBOARD_PASSWORD=${DASHBOARD_PASSWORD}|" .env

    php artisan key:generate --force >/dev/null
    ok "Configuración creada"
else
    ok "Ya existía .env — se conserva (actualización de código)"
fi

# ── 4. Base de datos ────────────────────────────────────────────────────────
log "Preparando base de datos…"
mkdir -p database
touch database/database.sqlite
php artisan migrate --force
php artisan config:clear >/dev/null
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
ln -sf /etc/nginx/sites-available/gestoru-dashboard /etc/nginx/sites-enabled/gestoru-dashboard
nginx -t >/dev/null || fail "La configuración de nginx no es válida"
systemctl reload nginx
systemctl enable --now "php${PHPV}-fpm" >/dev/null 2>&1 || true
ok "nginx configurado"

# ── Final ───────────────────────────────────────────────────────────────────
IP=$(hostname -I | awk '{print $1}')
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

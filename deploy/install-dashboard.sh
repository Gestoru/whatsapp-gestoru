#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════════════════
#  Instalador del Dashboard de Infraestructura Gestoru
#  Probado en Ubuntu 20.04 / 22.04 / 24.04 (Contabo VPS)
#
#  Uso (como root, desde la carpeta del repositorio ya clonado):
#      bash deploy/install-dashboard.sh
#
#  Estrategia de PHP (en orden):
#   1. Paquetes del sistema (apt) → publica con nginx + php-fpm
#   2. Un PHP >= 8.2 ya instalado con las extensiones necesarias
#      → publica como servicio systemd (php artisan serve)
#   3. PHP portátil autónomo (static-php) → igual que el 2
#
#  Es seguro re-ejecutarlo: conserva tu configuración y tus servidores.
# ══════════════════════════════════════════════════════════════════════════
set -uo pipefail

APP_DIR="/opt/gestoru-dashboard"
PORT="${DASHBOARD_PORT:-8088}"
STATIC_DIR="/opt/gestoru-php"

log()  { echo -e "\n\033[1;36m▶ $*\033[0m"; }
ok()   { echo -e "\033[1;32m✔ $*\033[0m"; }
fail() { echo -e "\033[1;31m✘ $*\033[0m"; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "Ejecuta este script como root (o con sudo)."

# ── 0. Comprobaciones previas ───────────────────────────────────────────────
if ! systemctl is-active --quiet gestoru-dashboard 2>/dev/null; then
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ":${PORT}\$"; then
        fail "El puerto ${PORT} ya está ocupado. Vuelve a ejecutar con otro puerto: DASHBOARD_PORT=8090 bash deploy/install-dashboard.sh"
    fi
fi

PREV_PHP="$(readlink -f /usr/bin/php 2>/dev/null || true)"
HAD_NGINX="$(command -v nginx || true)"

export DEBIAN_FRONTEND=noninteractive

# ── 1. Conseguir un PHP utilizable ──────────────────────────────────────────
log "Actualizando índices de paquetes (los errores de repositorios ajenos no detienen la instalación)…"
apt-get update -qq 2>/dev/null || true
apt-get install -y -qq unzip git rsync curl ca-certificates >/dev/null 2>&1 || true

# ¿El paquete tiene un candidato realmente descargable en los repos?
avail() {
    local out
    out="$(apt-cache policy "$1" 2>/dev/null | awk '/Candidate:/ {print $2}')"
    [ -n "$out" ] && [ "$out" != "(none)" ]
}

detect_apt_php() {
    for v in 8.4 8.3 8.2; do
        if avail "php${v}-cli" && avail "php${v}-fpm" && avail "php${v}-sqlite3"; then
            echo "$v"
            return 0
        fi
    done
    return 1
}

# ¿Este binario PHP sirve para el panel? (versión y extensiones)
php_capable() {
    local bin="$1" e
    [ -x "$bin" ] || return 1
    "$bin" -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' >/dev/null 2>&1 || return 1
    for e in pdo_sqlite mbstring curl openssl dom fileinfo tokenizer ctype session; do
        "$bin" -m 2>/dev/null | grep -qix "$e" || return 1
    done
    return 0
}

MODE=""
PHP_BIN=""
PHPV=""

# Plan 1: apt
PHPV="$(detect_apt_php || true)"
if [ -z "$PHPV" ]; then
    log "PHP no está en los repositorios — intentando agregar ppa:ondrej/php…"
    apt-get install -y -qq software-properties-common >/dev/null 2>&1 || true
    add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
    apt-get update -qq 2>/dev/null || true
    PHPV="$(detect_apt_php || true)"
fi

if [ -n "$PHPV" ]; then
    MODE="nginx"
    PHP_BIN="/usr/bin/php${PHPV}"
    ok "Se usará PHP ${PHPV} de los repositorios (modo nginx)"
else
    # Plan 2: un PHP ya instalado que cumpla los requisitos
    log "Buscando un PHP ya instalado que sirva…"
    for cand in "$STATIC_DIR/php" /usr/bin/php8.4 /usr/bin/php8.3 /usr/bin/php8.2 "$PREV_PHP"; do
        [ -n "$cand" ] || continue
        if php_capable "$cand"; then
            MODE="serve"
            PHP_BIN="$cand"
            ok "Se usará el PHP existente: $cand ($("$cand" -r 'echo PHP_VERSION;'))"
            break
        fi
    done
fi

if [ -z "$MODE" ]; then
    # Plan 3: PHP portátil autónomo (no depende de repositorios)
    log "Descargando PHP portátil (static-php)…"
    LATEST="$(curl -fsSL --max-time 30 https://dl.static-php.dev/static-php-cli/common/ 2>/dev/null \
        | grep -oE 'php-8\.[34]\.[0-9]+-cli-linux-x86_64\.tar\.gz' | sort -uV | tail -1)"
    if [ -n "$LATEST" ]; then
        mkdir -p "$STATIC_DIR"
        if curl -fL --max-time 300 -o /tmp/gestoru-php.tar.gz "https://dl.static-php.dev/static-php-cli/common/${LATEST}" \
            && tar -xzf /tmp/gestoru-php.tar.gz -C "$STATIC_DIR"; then
            chmod +x "$STATIC_DIR/php" 2>/dev/null || true
            if php_capable "$STATIC_DIR/php"; then
                MODE="serve"
                PHP_BIN="$STATIC_DIR/php"
                ok "PHP portátil instalado: $("$PHP_BIN" -r 'echo PHP_VERSION;')"
            fi
        fi
        rm -f /tmp/gestoru-php.tar.gz
    fi
fi

if [ -z "$MODE" ]; then
    echo
    echo "  No se consiguió ningún PHP utilizable. Diagnóstico:"
    for cand in /usr/bin/php8.4 /usr/bin/php8.3 /usr/bin/php8.2 "$PREV_PHP"; do
        [ -n "$cand" ] && [ -x "$cand" ] || continue
        echo "  --- $cand ($("$cand" -r 'echo PHP_VERSION;' 2>/dev/null)) — extensiones faltantes:"
        for e in pdo_sqlite mbstring curl openssl dom fileinfo tokenizer ctype session; do
            "$cand" -m 2>/dev/null | grep -qix "$e" || echo "      $e"
        done
    done
    echo
    fail "No hay PHP compatible. Envía una captura de este mensaje."
fi

# ── 2. Paquetes restantes según el modo ─────────────────────────────────────
if [ "$MODE" = "nginx" ]; then
    log "Instalando PHP ${PHPV}, nginx y utilidades…"
    apt-get install -y -qq \
        "php${PHPV}-cli" "php${PHPV}-fpm" "php${PHPV}-sqlite3" "php${PHPV}-mbstring" \
        "php${PHPV}-xml" "php${PHPV}-curl" "php${PHPV}-zip" \
        nginx >/dev/null || fail "Falló la instalación de paquetes. Revisa el mensaje de arriba."
    ok "Paquetes instalados"

    # No cambiar el php por defecto del sistema (apps/cron existentes)
    if [ -n "$PREV_PHP" ] && [ -x "$PREV_PHP" ] && [ "$PREV_PHP" != "$PHP_BIN" ]; then
        update-alternatives --set php "$PREV_PHP" >/dev/null 2>&1 || true
        ok "PHP por defecto del sistema conservado ($PREV_PHP)"
    fi

    FPM_SOCK="/run/php/php${PHPV}-fpm.sock"
    systemctl enable --now "php${PHPV}-fpm" >/dev/null 2>&1 || true
    [ -S "$FPM_SOCK" ] || systemctl restart "php${PHPV}-fpm" || true
    [ -S "$FPM_SOCK" ] || fail "No se encontró el socket de php-fpm en $FPM_SOCK"
fi

# ── 3. Código de la aplicación ──────────────────────────────────────────────
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
ok "Dependencias PHP desplegadas"

# ── 4. Configuración (.env) ─────────────────────────────────────────────────
IP=$(hostname -I | awk '{print $1}')
if [ ! -f .env ]; then
    log "Creando configuración .env…"
    cp .env.example .env

    sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
    sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
    sed -i "s|^APP_URL=.*|APP_URL=http://${IP}:${PORT}|" .env

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

# ── 5. Base de datos ────────────────────────────────────────────────────────
log "Preparando base de datos…"
mkdir -p database
touch database/database.sqlite
"$PHP_BIN" artisan migrate --force || fail "Fallaron las migraciones."
"$PHP_BIN" artisan db:seed --class=ServersSeeder --force >/dev/null 2>&1 \
    && ok "Servidores de la empresa pre-cargados" || true
"$PHP_BIN" artisan config:clear >/dev/null 2>&1 || true
ok "Base de datos lista"

chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwX storage bootstrap/cache database

# ── 6. Publicar el panel ────────────────────────────────────────────────────
if [ "$MODE" = "nginx" ]; then
    log "Publicando con nginx en el puerto ${PORT}…"
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
        if [ -z "$HAD_NGINX" ] && ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ':80$'; then
            rm -f /etc/nginx/sites-enabled/default
        fi
        systemctl enable --now nginx || fail "nginx no pudo iniciar. Ejecuta: journalctl -u nginx --no-pager | tail"
    fi
    ok "nginx configurado"
else
    log "Publicando como servicio (php artisan serve) en el puerto ${PORT}…"
    cat > /etc/systemd/system/gestoru-dashboard.service <<UNIT
[Unit]
Description=Gestoru Infrastructure Dashboard
After=network.target

[Service]
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan serve --host=0.0.0.0 --port=${PORT}
Restart=always
RestartSec=3
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now gestoru-dashboard >/dev/null 2>&1
    systemctl restart gestoru-dashboard
    sleep 2
    systemctl is-active --quiet gestoru-dashboard \
        || fail "El servicio no pudo iniciar. Ejecuta: journalctl -u gestoru-dashboard --no-pager | tail -20"
    ok "Servicio gestoru-dashboard activo"
fi

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

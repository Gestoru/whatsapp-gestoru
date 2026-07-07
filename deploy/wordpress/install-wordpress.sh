#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────
#  Instalador de WordPress AISLADO para gestordesalud.co
#
#  Levanta un WordPress + MariaDB en su propia red y volúmenes Docker, sin
#  tocar ningún otro proyecto que ya corra en el servidor.
#
#  Es NO destructivo:
#   • No ocupa los puertos 80/443 (solo 127.0.0.1:WP_PORT).
#   • No toca contenedores, redes ni volúmenes existentes.
#   • Verifica que el puerto elegido esté libre ANTES de arrancar.
#   • Si algo falla, no deja nada a medias del resto del servidor.
#
#  Uso:   bash install-wordpress.sh
# ──────────────────────────────────────────────────────────────────────────
set -euo pipefail

cd "$(dirname "$0")"

say()  { printf '\n\033[1;36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m⚠ %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

# ── 1. Requisitos ───────────────────────────────────────────────────────────
say "1/5 · Verificando requisitos"

command -v docker >/dev/null 2>&1 || die "Docker no está instalado. Instálalo primero: https://docs.docker.com/engine/install/"

if docker compose version >/dev/null 2>&1; then
    DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
    DC="docker-compose"
else
    die "No se encontró «docker compose». Instala el plugin de Compose."
fi
ok "Docker y Compose disponibles ($DC)"

docker info >/dev/null 2>&1 || die "El demonio de Docker no responde. ¿Está corriendo? ¿Tienes permisos (sudo)?"
ok "El demonio de Docker responde"

# ── 2. Configuración (.env) ──────────────────────────────────────────────────
say "2/5 · Preparando configuración"

rand() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 28; }

if [ -f .env ]; then
    ok "Ya existe .env — se respeta tu configuración actual"
else
    cp .env.example .env
    # Genera contraseñas aleatorias seguras
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rand)|"           .env
    sed -i "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(rand)|" .env
    ok "Se generó .env con contraseñas aleatorias seguras"
fi

# Carga variables para las validaciones siguientes
set -a; . ./.env; set +a
WP_PORT="${WP_PORT:-8090}"

# ── 3. Puerto libre ──────────────────────────────────────────────────────────
say "3/5 · Verificando que el puerto $WP_PORT esté libre"

port_in_use() {
    if command -v ss >/dev/null 2>&1; then
        ss -ltn 2>/dev/null | grep -q ":$1 "
    elif command -v netstat >/dev/null 2>&1; then
        netstat -ltn 2>/dev/null | grep -q ":$1 "
    else
        return 1  # sin herramienta: no podemos verificar, seguimos
    fi
}

if port_in_use "$WP_PORT"; then
    die "El puerto $WP_PORT ya está en uso. Edita WP_PORT en .env y elige otro (ej. 8091) para no chocar con otro proyecto."
fi
ok "El puerto $WP_PORT está libre"

# Avisa si ya existe un stack con este nombre (idempotente, no destructivo)
if docker ps -a --format '{{.Names}}' | grep -q '^gestordesalud-wp$'; then
    warn "Ya existe el contenedor «gestordesalud-wp». Se actualizará sin borrar sus datos."
fi

# ── 4. Levantar el stack ─────────────────────────────────────────────────────
say "4/5 · Levantando WordPress + base de datos (aislado)"

$DC -p gestordesalud up -d
ok "Contenedores en marcha"

# ── 5. Resumen ───────────────────────────────────────────────────────────────
say "5/5 · Listo"

cat <<EOF

┌──────────────────────────────────────────────────────────────────────┐
│  WordPress de gestordesalud.co levantado (aislado, sin afectar nada)  │
└──────────────────────────────────────────────────────────────────────┘

  Acceso local (desde el servidor):   http://127.0.0.1:${WP_PORT}
  Contenedores:                       gestordesalud-wp · gestordesalud-db
  Datos persistentes en volúmenes:    gestordesalud_wp · gestordesalud_db

  SIGUIENTE PASO — enrutar el dominio y migrar el sitio:
  Abre la guía:   deploy/wordpress/GUIA-MIGRACION.md

  Comandos útiles:
    Ver estado:   ${DC} -p gestordesalud ps
    Ver logs:     ${DC} -p gestordesalud logs -f gestordesalud-wp
    Detener:      ${DC} -p gestordesalud down          (conserva los datos)
    Borrar TODO:  ${DC} -p gestordesalud down -v       (¡borra la base de datos!)

EOF

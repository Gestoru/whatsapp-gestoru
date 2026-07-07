# 🖥️ Infraestructura Gestoru

Panel unificado para administrar toda la infraestructura de servidores de la
empresa desde un solo lugar. Aplicación Laravel que se conecta por **SSH** a
cada servidor (Contabo, Winhosting u otros) y muestra, en segundos y sin
necesidad de usar la terminal:

- 📊 **Rendimiento** — CPU, RAM, disco, carga, uptime y sistema operativo.
- 📦 **Proyectos y servicios** — detecta PM2, Docker, servicios systemd y carpetas de proyectos.
- 🌐 **Dominios** — detectados desde nginx, Apache, certificados SSL y Docker; con vencimientos (whois) y botón de renovación por registrador (GoDaddy, IONOS, Winhosting).
- 💳 **Facturación por servidor** — plan vigente, próxima fecha de pago y botón de pago.
- 📁 **Explorador de archivos** — navegar carpetas y ver contenido.
- 📈 **Análisis inteligente** — qué consume CPU/RAM, ancho de banda por dominio, peticiones más pesadas, conexiones y MySQL (consultas activas + tamaño de bases de datos).
- 📉 **Histórico y tendencias** — gráficas de CPU/RAM/disco en el tiempo, con captura del proceso culpable en cada pico.

Todo el acceso a los servidores es de **solo lectura**, salvo acciones de
configuración explícitas que el usuario dispara con un botón (ej. activar el
slow query log de MySQL).

## Instalación

Ver **[deploy/GUIA-INSTALACION.md](deploy/GUIA-INSTALACION.md)** — instalación
de un solo comando en un servidor Ubuntu.

## Seguridad

- Credenciales SSH cifradas en reposo (AES-256 con la clave de la app).
- Panel protegido por contraseña (`DASHBOARD_PASSWORD`).
- Repositorio **privado**: contiene las IPs de los servidores de la empresa.

## Hoja de ruta

Ver **[docs/PLAN-EVOLUCION.md](docs/PLAN-EVOLUCION.md)**: observar → avisar →
reparar con supervisión vía GitHub → autonomía gradual.

## Stack

Laravel 12 · PHP 8.2+ · phpseclib3 (SSH) · SQLite · Blade + CSS propio.

# Panel de Administración de Servidores (SSH)

Módulo integrado en la app Laravel que se conecta por **SSH** a tus servidores
(VPS Linux, WinHosting, etc.) y muestra desde el navegador:

- Lista de todos los servidores con estado de conexión en vivo.
- **Rendimiento**: CPU (carga/uso), memoria RAM + swap, uso de disco por partición
  y procesos que más consumen.
- **Explorador de carpetas**: navega por las carpetas/proyectos del servidor
  (nombre, tamaño, permisos, propietario, fecha).
- Información del sistema: hostname, sistema operativo, kernel, uptime, núcleos.

Es de **solo lectura** por defecto: solo ejecuta comandos de consulta
(`uptime`, `free`, `df`, `ps`, `ls`…). No modifica nada en tus servidores.

## Cómo acceder

1. Define la contraseña del panel en tu archivo `.env`:

   ```env
   SERVER_ADMIN_PASSWORD=una-clave-fuerte
   ```

   (También acepta un hash bcrypt en lugar de texto plano.)

2. Entra en `https://tu-dominio/admin/login`.

## Cómo añadir servidores

Tienes **dos formas** (puedes combinarlas):

### A) Desde la interfaz (recomendado)

En `/admin` → **Añadir servidor**. Los datos y la contraseña SSH se guardan
**cifrados** en la base de datos. Cada servidor define su propio método:
llave privada SSH o usuario+contraseña.

### B) Desde el archivo `.env`

Precarga servidores sin base de datos. Ejemplo para un VPS Linux con llave y
un hosting con usuario+contraseña:

```env
SERVER_1_KEY=vps-principal
SERVER_1_NAME="VPS Principal"
SERVER_1_GROUP=vps
SERVER_1_HOST=123.45.67.89
SERVER_1_PORT=22
SERVER_1_USER=root
SERVER_1_AUTH=key
SERVER_1_KEY_PATH=/home/usuario/.ssh/id_rsa

SERVER_2_KEY=winhosting
SERVER_2_NAME="WinHosting"
SERVER_2_GROUP=winhosting
SERVER_2_HOST=hosting.ejemplo.com
SERVER_2_PORT=22
SERVER_2_USER=cpaneluser
SERVER_2_AUTH=password
SERVER_2_PASSWORD=tu-password
```

## Opciones de configuración (`config/servers.php` / `.env`)

| Variable | Descripción | Por defecto |
|---|---|---|
| `SERVER_ADMIN_PASSWORD` | Contraseña de acceso al panel | — |
| `SERVER_ADMIN_MODE` | `readonly` o `actions` | `readonly` |
| `SERVER_SSH_TIMEOUT` | Segundos de espera para SSH | `15` |
| `SERVER_DEFAULT_BASE_PATH` | Carpeta que se lista al abrir un servidor | `/var/www` |

## Requisitos

- El servidor destino debe permitir conexiones **SSH** desde la IP donde corre
  esta app Laravel (revisa firewall / whitelist de WinHosting).
- Para autenticación por llave, la llave privada debe estar accesible en la ruta
  indicada (`SERVER_1_KEY_PATH` o el campo del formulario) y sin passphrase, o
  con la passphrase en el campo de contraseña.

## Seguridad

- Las credenciales guardadas en base de datos se cifran con la `APP_KEY` de Laravel.
- El acceso al panel está protegido por sesión (`/admin`).
- El modo `readonly` (por defecto) impide ejecutar comandos de escritura.

## Arquitectura

```
app/Services/Ssh/
  SshClient.php            → conexión SSH (phpseclib), llave o contraseña
  ServerMonitorService.php → recolecta y parsea métricas / lista carpetas
  ServerRegistry.php       → unifica servidores de .env + base de datos
app/Http/Controllers/Admin/
  AuthController.php        → login/logout del panel
  ServerAdminController.php → dashboard, métricas, carpetas, CRUD
app/Models/Server.php       → servidor (password cifrada)
resources/views/admin/*     → interfaz del panel
config/servers.php          → configuración e inventario desde .env
```

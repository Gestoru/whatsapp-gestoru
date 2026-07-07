# Migración de **gestordesalud.co** a tu servidor propio (Personal Samuel)

Guía paso a paso para mover el WordPress de **Winkhosting (hosting compartido, Wisconsin)**
a tu **VPS de Contabo «Servidor Personal Samuel» (`217.216.65.118`)**, sin
tocar ni dañar los proyectos que ya corren ahí.

> **Idea clave:** el nuevo WordPress vive en su propia caja aislada (contenedores
> Docker con red, base de datos y volúmenes propios). No comparte nada con tus
> otros proyectos y solo escucha en `127.0.0.1`. El proxy del servidor
> (Caddy/Nginx) es quien conecta el dominio hacia esa caja. Así, si algo sale
> mal, se apaga o se borra sin afectar a nadie más.

---

## Antes de empezar — lo que necesitas

- [ ] Acceso SSH al servidor Personal Samuel (ya lo tienes en el panel).
- [ ] Usuario y contraseña de **wp-admin** del sitio actual en Winkhosting.
- [ ] Acceso a **GoDaddy** (el DNS de `gestordesalud.co` está ahí). Tu cuenta:
      `importadoraseverino@gmail.com`. Ya está integrado en el panel de dominios.

No necesitas SSH del hosting viejo: la migración se hace con un plugin de
WordPress que copia el sitio de servidor a servidor.

---

## Etapa 0 · Revisar el servidor (sin tocar nada)

Antes de instalar, conéctate al servidor y ejecuta este bloque para ver cómo
está montado (es solo lectura, no cambia nada):

```bash
echo "== CONTENEDORES =="; docker ps --format '{{.Names}} | {{.Image}} | {{.Ports}}' 2>/dev/null
echo "== QUIÉN USA 80/443 =="; ss -ltnp 2>/dev/null | grep -E ':80 |:443 ' || echo "libres"
echo "== HAY PROXY? =="; docker ps --format '{{.Image}}' 2>/dev/null | grep -Ei 'caddy|traefik|nginx|proxy' || echo "no detectado"
echo "== PUERTO 8090 LIBRE? =="; ss -ltn 2>/dev/null | grep -q ':8090 ' && echo "OCUPADO" || echo "libre"
echo "== DISCO =="; df -h / | tail -1
```

Lo que buscamos:
- **Qué proxy usas** (Caddy, Traefik o Nginx) → define cómo enrutamos el dominio
  (Etapa 3).
- **Que el puerto 8090 esté libre** → si está ocupado, elige otro (8091, 8092…)
  y ponlo en `.env`.
- **Que haya disco suficiente** para el tamaño del sitio.

---

## Etapa 1 · Instalar el WordPress vacío (automático, ~2 minutos)

En el servidor, copia esta carpeta (`deploy/wordpress/`) y ejecuta:

```bash
cd deploy/wordpress
bash install-wordpress.sh
```

El instalador:
1. Verifica que Docker esté disponible.
2. Genera un `.env` con contraseñas aleatorias seguras.
3. **Comprueba que el puerto esté libre** antes de arrancar (no pisa nada).
4. Levanta WordPress + MariaDB aislados.

Al terminar verás algo como `http://127.0.0.1:8090`. Ese es tu WordPress nuevo,
todavía vacío, esperando el contenido del sitio viejo.

> Si el puerto 8090 estaba ocupado, edita `WP_PORT` en `.env`, elige otro y
> vuelve a correr el instalador.

---

## Etapa 2 · Copiar el sitio viejo al nuevo (plugin, unos clics)

Usaremos **Migrate Guru** (gratis, hecho para migrar entre servidores sin
límites de tamaño y sin usar SSH del hosting viejo).

**En el WordPress VIEJO (Winkhosting):**
1. Entra a `wp-admin` del sitio actual.
2. Ve a **Plugins → Añadir nuevo**, busca **«Migrate Guru»**, instálalo y actívalo.
3. Abre **Migrate Guru**, pon tu correo y elige **«Migrate»**.
4. Como destino elige **«Other host / Custom»** (destino manual).

**En el WordPress NUEVO (el que acabas de instalar):**
5. Necesitas que sea alcanzable temporalmente. Dos opciones:
   - **Opción A (recomendada):** primero haz la Etapa 3 (enrutar el dominio con
     un subdominio temporal, p. ej. `nuevo.gestordesalud.co`) para que Migrate
     Guru tenga una URL pública a la cual escribir.
   - **Opción B:** usa el conector/clave que te da Migrate Guru en el sitio
     destino (instala también Migrate Guru en el WordPress nuevo y sigue su
     asistente de «recibir migración»).
6. Migrate Guru copia archivos + base de datos y reemplaza las URLs solo. Espera
   a que diga **«Migration completed»**.

> **Alternativa:** si el sitio es pequeño, sirve **All-in-One WP Migration**
> (exportas un archivo en el viejo, lo importas en el nuevo). Por eso el
> `docker-compose.yml` ya sube el límite de subida a **1024 MB**.

Cuando termine, revisa el sitio nuevo por su URL temporal: que se vea igual,
que entren los formularios, que carguen las imágenes.

---

## Etapa 3 · Conectar el dominio al servidor nuevo

Aquí decides el momento del «cambio». El sitio viejo sigue vivo hasta que
muevas el DNS, así que puedes probar sin prisa.

### 3.1 · Enrutar en el proxy (según lo que viste en la Etapa 0)

**Si usas Caddy** (lo más común en tus servidores), agrega al `Caddyfile`:

```
gestordesalud.co, www.gestordesalud.co {
    reverse_proxy 127.0.0.1:8090
}
```

y recarga Caddy (`caddy reload` o `docker exec <caddy> caddy reload ...`).
Caddy saca el certificado HTTPS automáticamente.

**Si usas Nginx**, crea un `server` que haga `proxy_pass http://127.0.0.1:8090;`
y saca el certificado con Certbot.

> **Importante:** solo agregas un bloque NUEVO para este dominio. No tocas la
> configuración de los otros proyectos.

### 3.2 · Apuntar el DNS en GoDaddy

El DNS de `gestordesalud.co` está en GoDaddy (ya integrado en tu panel).
Cambia el registro **A** para que apunte a `217.216.65.118`:

- **Tipo:** A · **Nombre:** `@` · **Valor:** `217.216.65.118`
- **Tipo:** A · **Nombre:** `www` · **Valor:** `217.216.65.118`

Puedes hacerlo desde el panel de GoDaddy, o desde el módulo **Dominios** del
dashboard (integración de GoDaddy API ya conectada).

> El DNS tarda de minutos a unas horas en propagarse. Mientras tanto, el sitio
> viejo sigue respondiendo para quien aún tenga el DNS antiguo en caché. **No
> apagues el hosting viejo hasta confirmar** que el nuevo responde bien con el
> dominio real.

---

## Etapa 4 · Verificar y cerrar

- [ ] `https://gestordesalud.co` abre el sitio **nuevo** con candado (HTTPS OK).
- [ ] Entras a `wp-admin` del nuevo y todo está.
- [ ] Formularios, pagos, correos y páginas internas funcionan.
- [ ] Agrega `gestordesalud.co` al módulo **Dominios** del panel para vigilar su
      vencimiento y su estado.
- [ ] Deja el hosting viejo unos días como respaldo. Cuando estés seguro,
      cancélalo en Winkhosting.

---

## Si algo sale mal (no cunde el pánico)

Nada de esto afecta tus otros proyectos: el WordPress nuevo está aislado.

- **Revertir el cambio:** vuelve a poner en GoDaddy la IP vieja de Winkhosting.
  El sitio viejo sigue intacto porque no lo tocamos.
- **Reiniciar el WordPress nuevo:** `docker compose -p gestordesalud restart`.
- **Empezar de cero el nuevo:** `docker compose -p gestordesalud down -v` (borra
  solo los datos de ESTE WordPress) y vuelve a correr el instalador.
- **Ver qué pasa:** `docker compose -p gestordesalud logs -f gestordesalud-wp`.

---

## Resumen de una línea

Levantas un WordPress aislado en el servidor (Etapa 1), copias el sitio viejo
con Migrate Guru (Etapa 2), enrutas el dominio con el proxy + GoDaddy (Etapa 3)
y verificas (Etapa 4). Tus otros proyectos nunca se tocan.

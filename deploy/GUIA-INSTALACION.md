# 🖥️ Guía de instalación — Dashboard de Infraestructura Gestoru

Panel web para ver **todos tus servidores en un solo lugar**: rendimiento
(CPU, RAM, disco), proyectos instalados, dominios y explorador de archivos.
No necesitas saber de terminal: solo copiar y pegar **una vez**.

---

## ¿Qué necesitas?

- La **IP** del servidor donde vivirá el panel (recomendado: el de
  APPS VARIAS) y su **contraseña de root** (la que te dio Contabo).
- Un computador con Windows, Mac o Linux.

> 💡 El panel se instala en **uno solo** de tus servidores. Los demás
> los agregas después **desde el navegador**, sin tocar la terminal.

---

## Paso 1 — Abrir la terminal de tu computador

- **Windows:** presiona `Windows + R`, escribe `cmd` y presiona Enter.
- **Mac:** abre la app **Terminal**.

## Paso 2 — Conectarte al servidor

Escribe (cambia la IP si eliges otro servidor):

```
ssh root@157.173.194.26
```

- Si pregunta `Are you sure you want to continue connecting?` escribe `yes` y Enter.
- Te pedirá la **contraseña de root**. Al escribirla **no se ve nada** — es
  normal, escríbela completa y presiona Enter.

## Paso 3 — Pegar el comando de instalación

Copia y pega **todo esto en una sola línea** y presiona Enter:

```
apt-get update; apt-get install -y git && rm -rf /root/gestoru-repo && git clone https://github.com/Gestoru/infraestructura-gestoru.git /root/gestoru-repo && bash /root/gestoru-repo/deploy/install-dashboard.sh
```

Tarda 2–5 minutos. Al final te pedirá:

> 🔑 Escribe la contraseña con la que entrarás al panel

Inventa una contraseña segura (esa será la del **dashboard**, no la del
servidor) y presiona Enter.

## Paso 4 — Abrir el firewall (si hace falta)

Si al terminar el panel no abre en el navegador:

1. Entra a [my.contabo.com](https://my.contabo.com) → **Firewall**.
2. Agrega una regla que permita el puerto **8088** (TCP, entrada).

También puedes abrirlo desde la misma terminal del servidor:

```
ufw allow 8088/tcp 2>/dev/null || true
```

## Paso 5 — Entrar al panel

Abre en tu navegador:

```
http://157.173.194.26:8088
```

Entra con la contraseña que inventaste en el Paso 3. 🎉

## Paso 6 — Agregar tus servidores

Dentro del panel presiona **«＋ Agregar servidor»** y llena el formulario
por cada servidor (los datos quedan **cifrados** en la base de datos):

| Nombre sugerido | IP | Usuario |
|---|---|---|
| Tienda Gestor y Backend Catálogo | 2.58.82.99 | root |
| Servidor Personal Samuel | 217.216.65.118 | root |
| GestorDeParte.net y Gestoru.com | 194.163.159.44 | root |
| Apps Varias / Storage / GST2 / APIs | 157.173.194.26 | root |
| Winhosting (cuando tengas la IP) | — | — |

En cada uno usa la **contraseña root** de ese servidor y presiona
**«🔌 Probar conexión»** para confirmar que quedó bien.

---

## Preguntas frecuentes

**¿El panel puede dañar mis servidores?**
No. Todos los comandos que ejecuta son de **solo lectura** (leer métricas,
listar carpetas, leer archivos). No borra, no modifica, no reinicia nada.

**¿Es seguro?**
- El panel exige contraseña para entrar.
- Las credenciales SSH se guardan **cifradas** (AES-256 con la clave de la app).
- Recomendación extra: en el firewall de Contabo permite el puerto 8088
  solo desde la IP de tu oficina/casa.

**¿Cómo actualizo el panel cuando haya mejoras?**
Repite los pasos 1–3. El instalador detecta que ya existe y solo
actualiza el código, conservando tu configuración y tus servidores.

**Quiero entrar con un dominio y candado (https).**
Se puede poner detrás de nginx con certificado SSL (Let's Encrypt).
Pídelo como siguiente mejora.

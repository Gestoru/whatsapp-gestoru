# Dejar funcional el módulo de Alertas por WhatsApp

El panel ya está listo para **configurar la conexión, ver el estado y escanear
el QR** desde *Configuración → Alertas*. Solo falta dejar corriendo el
**servidor de WhatsApp** (incluido en esta carpeta `vps-server/`), que es quien
mantiene la sesión de WhatsApp Web y envía los mensajes.

## Qué es esto
- `vps-server/` es un pequeño servidor **Node.js (whatsapp-web.js)**.
- Expone `POST /send-message`, `POST /request-qr`, `POST /disconnect`, `GET /status`.
- Al vincular, envía el QR y el estado al panel por webhook.

## Instalación (en el servidor del panel, `157.173.194.26`)
```bash
cd /root/gestoru-repo/vps-server
sudo bash install-whatsapp.sh
```
El instalador: verifica Node 18+, instala dependencias (incluido Chromium para
whatsapp-web.js), crea `vps-server/.env` con un `WEBHOOK_SECRET` nuevo y arranca
el servidor con PM2 en el puerto **3000**.

> ⚠️ El `WEBHOOK_SECRET` debe ser **el mismo** en `vps-server/.env` y en el `.env`
> del panel (`/opt/gestoru-dashboard/.env`, variable `WEBHOOK_SECRET`). El
> instalador te muestra el valor; cópialo al `.env` del panel y recarga cachés:
> ```
> cd /opt/gestoru-dashboard && php artisan config:cache
> ```

## Conectar desde el panel (1 minuto)
1. En el panel: **Configuración → Alertas → Servidor de WhatsApp**.
2. **Dirección del servidor:** `http://127.0.0.1:3000` → **Guardar servidor**.
3. El estado (arriba a la derecha) pasará a **«esperando vinculación»**.
4. Clic en **📱 Vincular WhatsApp (mostrar QR)** → aparece el código.
5. En tu teléfono: WhatsApp → **Dispositivos vinculados → Vincular un dispositivo**
   → escanea. El estado pasará a **«Conectado»** (verde).
6. Escribe tu **número** (con código de país), **Guardar alertas**, y prueba con
   **📤 Enviar prueba**. Debe llegarte el WhatsApp. ✅

## Comandos útiles
```bash
pm2 status                 # ver el servidor
pm2 logs wpp-gestoru-vps   # ver logs (incluye el QR en texto)
pm2 restart wpp-gestoru-vps
```

## Notas
- La sesión de WhatsApp queda guardada (no hay que re-escanear en cada reinicio).
- Si el estado dice **«servidor no responde»**, revisa `pm2 status` y que la URL
  y el puerto coincidan.
- Las alertas se revisan cada 5 minutos junto con el muestreo de métricas.

# Conexión con Twilio (WhatsApp)

Esta app puede enviar y recibir WhatsApp a través de tu cuenta de Twilio, como
alternativa al proveedor VPS (Baileys).

## 1. Credenciales

En la [Consola de Twilio](https://console.twilio.com) → **Account Info** copia el
`Account SID` y el `Auth Token`, y añádelos a tu `.env`:

```env
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=tu_auth_token
TWILIO_WHATSAPP_FROM=+14155238886        # nº de WhatsApp de Twilio (sandbox: +14155238886)
TWILIO_VALIDATE_SIGNATURE=true
```

## 2. Verificar la conexión

Con el API Token de Laravel:

```bash
curl -H "Authorization: Bearer $LARAVEL_API_TOKEN" \
     https://tu-dominio.com/api/whatsapp/twilio/status
```

Respuesta esperada si las credenciales son válidas:

```json
{ "connected": true, "friendly_name": "...", "status": "active", "whatsapp_from": "+14155238886" }
```

## 3. Configurar los webhooks en Twilio

En la consola de Twilio, en la configuración de tu número/sandbox de WhatsApp:

| Evento                     | Método | URL                                                     |
| -------------------------- | ------ | ------------------------------------------------------- |
| Cuando llega un mensaje    | POST   | `https://tu-dominio.com/api/whatsapp/twilio/incoming`        |
| Callback de estado         | POST   | `https://tu-dominio.com/api/whatsapp/twilio/status-callback` |

Los webhooks se validan con la cabecera `X-Twilio-Signature`. En pruebas locales
puedes poner `TWILIO_VALIDATE_SIGNATURE=false`.

## 4. Enviar mensajes de WhatsApp

`App\Services\TwilioWhatsAppService::sendMessage()` expone el mismo contrato que
`VpsWhatsAppService`, por lo que ambos proveedores son intercambiables. El envío
saliente actual (`MessageController`) sigue usando el VPS; para enrutarlo por
Twilio, inyecta `TwilioWhatsAppService` en su lugar.

## 5. Envío masivo de SMS a clientes

Para SMS necesitas un número habilitado para SMS en Twilio y configurarlo:

```env
TWILIO_SMS_FROM=+1XXXXXXXXXX     # tu número SMS de Twilio, o un Messaging Service SID (MG...)
```

Luego usa el comando `sms:send` con un CSV de clientes y el mensaje.

### CSV de clientes

Con o sin cabecera. Se aceptan cabeceras en español o inglés
(`telefono`/`celular`/`numero`, `nombre`/`name`):

```csv
telefono,nombre
+18095551234,Juan
+18095559876,María
```

Los teléfonos **deben** estar en formato E.164 (`+` y código de país). Si tu
lista trae números locales sin `+`, usa `--default-country` para anteponer el
código de país (p. ej. `1` para RD/EE.UU./Canadá, `34` España, `52` México).
Los números que no se puedan normalizar se **omiten** y se reportan como
`invalid` (nunca se envían a un número inventado).

### Comando

```bash
# Ver primero qué se enviaría, sin enviar nada (recomendado):
php artisan sms:send clientes.csv \
    --message="Hola {{nombre}}, tenemos una promoción para ti." \
    --default-country=1 --dry-run --results=resultado.csv

# Envío real:
php artisan sms:send clientes.csv \
    --message="Hola {{nombre}}, tenemos una promoción para ti." \
    --default-country=1 --results=resultado.csv
```

Opciones:

| Opción              | Descripción                                                        |
| ------------------- | ------------------------------------------------------------------ |
| `--message`         | Texto del mensaje. `{{nombre}}` se reemplaza por el nombre.         |
| `--message-file`    | Alternativa: lee el mensaje desde un archivo de texto.             |
| `--default-country` | Código de país para números sin `+` (p. ej. `1`).                  |
| `--from`            | Remitente puntual (nº o `MG...`); por defecto `TWILIO_SMS_FROM`.   |
| `--delay`           | Segundos de espera entre envíos (para no saturar).                 |
| `--dry-run`         | Simula sin enviar. Úsalo siempre antes del envío real.            |
| `--results`         | Guarda un CSV con `phone,name,status,detail` de cada envío.        |

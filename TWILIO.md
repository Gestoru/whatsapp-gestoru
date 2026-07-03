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

## 4. Enviar mensajes

`App\Services\TwilioWhatsAppService::sendMessage()` expone el mismo contrato que
`VpsWhatsAppService`, por lo que ambos proveedores son intercambiables. El envío
saliente actual (`MessageController`) sigue usando el VPS; para enrutarlo por
Twilio, inyecta `TwilioWhatsAppService` en su lugar.

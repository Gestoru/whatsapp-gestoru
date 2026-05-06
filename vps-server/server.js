require('dotenv').config();

const express    = require('express');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const qrcode     = require('qrcode');
const axios      = require('axios');

const app = express();
app.use(express.json({ limit: '50mb' }));

// ── Estado global ─────────────────────────────────────────────────────────────
let wpClient    = null;
let isReady     = false;
let isInitializing = false;

// ── Webhook hacia Laravel ─────────────────────────────────────────────────────
async function sendWebhook(type, data = {}) {
    const url    = process.env.LARAVEL_WEBHOOK_URL;
    const secret = process.env.WEBHOOK_SECRET;

    if (!url) {
        console.warn(`[webhook] LARAVEL_WEBHOOK_URL no configurado — tipo: ${type}`);
        return;
    }

    try {
        await axios.post(url, { type, ...data }, {
            headers: {
                'Content-Type':    'application/json',
                'x-webhook-secret': secret ?? '',
            },
            timeout: 15000,
        });
        console.log(`[webhook] ✓ ${type}`);
    } catch (err) {
        console.error(`[webhook] ✗ ${type}:`, err.message);
    }
}

// ── Inicializar cliente WhatsApp ──────────────────────────────────────────────
function buildClient() {
    return new Client({
        authStrategy: new LocalAuth({ clientId: 'wpp-gestoru' }),
        puppeteer: {
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--single-process',
                '--disable-gpu',
            ],
        },
    });
}

function attachEvents(client) {
    // QR generado — enviar al Laravel para mostrarlo en el frontend
    client.on('qr', async (qr) => {
        console.log('[whatsapp] QR generado');
        const qrDataUrl = await qrcode.toDataURL(qr);
        await sendWebhook('qr_update', { qr_data_url: qrDataUrl });
    });

    // Autenticado con sesión guardada (no emite qr)
    client.on('authenticated', () => {
        console.log('[whatsapp] Autenticado');
    });

    // Listo para operar
    client.on('ready', async () => {
        isReady        = true;
        isInitializing = false;
        console.log('[whatsapp] Conectado y listo');
        const session = client.info?.wid?.user ?? null;
        await sendWebhook('status_change', { status: 'connected', session });
    });

    // Fallo de autenticación
    client.on('auth_failure', async (msg) => {
        isReady        = false;
        isInitializing = false;
        console.error('[whatsapp] Auth failure:', msg);
        await sendWebhook('status_change', { status: 'disconnected' });
    });

    // Desconexión
    client.on('disconnected', async (reason) => {
        isReady        = false;
        isInitializing = false;
        console.log('[whatsapp] Desconectado:', reason);
        await sendWebhook('status_change', { status: 'disconnected' });
    });

    // Mensaje entrante
    client.on('message', async (msg) => {
        if (msg.fromMe) return;

        const contact   = await msg.getContact();
        let mediaBase64 = null;
        let mediaType   = 'text';
        let mediaName   = null;

        if (msg.hasMedia) {
            try {
                const media = await msg.downloadMedia();
                mediaBase64 = media.data;   // ya viene en base64
                mediaName   = media.filename ?? null;

                if (media.mimetype.startsWith('image/'))      mediaType = 'image';
                else if (media.mimetype.startsWith('audio/')) mediaType = 'audio';
                else if (media.mimetype.startsWith('video/')) mediaType = 'video';
                else                                          mediaType = 'document';
            } catch (err) {
                console.error('[whatsapp] Error descargando media:', err.message);
            }
        }

        await sendWebhook('incoming_message', {
            phone:        msg.from,
            name:         contact.pushname ?? contact.name ?? msg.from,
            content:      msg.body,
            media_type:   mediaType,
            media_base64: mediaBase64,
            media_name:   mediaName,
        });
    });

    // Actualización de estado de mensaje (entregado / leído)
    client.on('message_ack', async (msg, ack) => {
        // 1=sent 2=delivered 3=read 4=played(audio)
        const map = { 2: 'delivered', 3: 'read', 4: 'read' };
        if (!map[ack]) return;

        await sendWebhook('message_status_update', {
            message_id: msg.id._serialized,
            status:     map[ack],
        });
    });
}

async function initWhatsApp() {
    if (isInitializing) {
        console.log('[whatsapp] Ya inicializando, ignorando solicitud duplicada');
        return;
    }

    if (wpClient) {
        try { await wpClient.destroy(); } catch (_) {}
        wpClient = null;
        isReady  = false;
    }

    isInitializing = true;
    wpClient = buildClient();
    attachEvents(wpClient);
    await wpClient.initialize();
}

// ── Endpoints REST ────────────────────────────────────────────────────────────

// Solicitar QR (Base44 → Laravel → aquí)
app.post('/request-qr', async (req, res) => {
    console.log('[api] POST /request-qr');
    initWhatsApp().catch(err => console.error('[whatsapp] Init error:', err.message));
    res.json({ message: 'QR generation started' });
});

// Desconectar sesión
app.post('/disconnect', async (req, res) => {
    console.log('[api] POST /disconnect');
    isReady        = false;
    isInitializing = false;

    if (wpClient) {
        try { await wpClient.destroy(); } catch (_) {}
        wpClient = null;
    }

    res.json({ message: 'Disconnected' });
});

// Enviar mensaje
app.post('/send-message', async (req, res) => {
    console.log('[api] POST /send-message');

    if (!isReady) {
        return res.status(503).json({ error: 'WhatsApp no está conectado' });
    }

    const { phone, content, media_type, media_url, media_name } = req.body;

    if (!phone || !content) {
        return res.status(422).json({ error: 'phone y content son requeridos' });
    }

    try {
        let sentMsg;

        if (media_type && media_type !== 'text' && media_url) {
            const media = await MessageMedia.fromUrl(media_url, { unsafeMime: true });
            if (media_name) media.filename = media_name;
            sentMsg = await wpClient.sendMessage(phone, media, { caption: content });
        } else {
            sentMsg = await wpClient.sendMessage(phone, content);
        }

        res.json({ message_id: sentMsg.id._serialized, status: 'sent' });
    } catch (err) {
        console.error('[whatsapp] Error enviando mensaje:', err.message);
        res.status(500).json({ error: err.message });
    }
});

// Health check
app.get('/status', (_req, res) => {
    res.json({
        connected:    isReady,
        initializing: isInitializing,
        session:      wpClient?.info?.wid?.user ?? null,
    });
});

// ── Arranque ──────────────────────────────────────────────────────────────────
const PORT = process.env.PORT ?? 3000;

app.listen(PORT, () => {
    console.log(`[server] VPS WhatsApp escuchando en puerto ${PORT}`);

    if (process.env.AUTO_INIT === 'true') {
        console.log('[server] AUTO_INIT activo — inicializando WhatsApp...');
        initWhatsApp().catch(err => console.error('[whatsapp] Auto-init error:', err.message));
    }
});
